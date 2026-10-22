<?php

/*
 * Start-up of the Flarum container (run by entrypoint.d/60-flarum.sh before nginx and PHP-FPM start).
 *
 * Two roles, chosen by the environment:
 *
 *   FLARUM_AUTORUN=true   the web container ("flarum"): does everything below.
 *   anything else         helper containers from the same image (the scheduler): wait until the
 *                         forum is installed, write config.php, nothing else.
 *
 * What the web container does, on every start (each step is idempotent):
 *
 *   1. wait for the database
 *   2. forum database empty?  ->  unattended `php flarum install` (admin from FLARUM_ADMIN_*,
 *                                 URL from FLARUM_BASE_URL, database from FLARUM_DB_*, mail settings copied
 *                                 from FLARUM_MAIL_*; Flarum enables its own default extensions; members may
 *                                 upload files). A failed install is rolled back completely, so the next start
 *                                 begins again.
 *      forum database exists? ->  write config.php from the environment and run `php flarum migrate`.
 *                                 This is also Flarum's official way to upgrade an existing 1.x database
 *                                 to 2.x: the code is new, the schema is brought up to date here.
 *   3. store FLARUM_API_KEY in the api_keys table (the Laravel app uses it as the master key)
 *   4. switch on the extensions below (and FLARUM_EXTENSIONS), each one once, with `php flarum extension:enable`
 *   5. publish extension assets, clear and rebuild the caches
 *
 * config.php is generated from the environment and not kept in a volume: change a variable, restart
 * the container, done. Nothing here ever runs Composer; the code comes from the image.
 *
 * Fatal: no database, install/migrate failing. Everything else only logs a warning.
 */

declare(strict_types=1);

const ROOT_DEFAULT = '/var/www/html';

/**
 * Extensions the image switches on (once each). The first block is the set Flarum's own installer enables.
 * Installed but deliberately left off: flarum/audit, deck, gdpr, messages (overlaps with Byobu) and
 * nicknames. Turn them on in the forum administration if you want them. Ids, not composer names.
 */
const DEFAULT_EXTENSIONS = [
    'flarum-tags', 'flarum-flags', 'flarum-approval', 'flarum-bbcode', 'flarum-emoji', 'flarum-lang-english',
    'flarum-likes', 'flarum-lock', 'flarum-markdown', 'flarum-mentions', 'flarum-statistics', 'flarum-sticky',
    'flarum-subscriptions', 'flarum-suspend',
    // for LastPope: the iframe on design/driver pages, uploads, polls, reactions, best answer, private discussions
    'flarum-embed', 'fof-upload', 'fof-polls', 'fof-reactions', 'fof-best-answer', 'fof-byobu',
];

/** Settings key remembering which extensions this script has already handled. */
const SEEN_KEY = 'lastpope.extensions_seen';

/** Marks the api_keys rows owned by this script, so that a changed FLARUM_API_KEY replaces the old one. */
const API_KEY_SCOPE = 'lastpope';

function out(string $message): void
{
    fwrite(STDOUT, "flarum-start: $message\n");
}

function warn(string $message): void
{
    fwrite(STDERR, "flarum-start: WARNING: $message\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "flarum-start: ERROR: $message\n");
    exit(1);
}

function env(string $name, string $default = ''): string
{
    $value = getenv($name);

    return $value === false ? $default : $value;
}

function envRequired(string $name): string
{
    $value = env($name);

    return $value !== '' ? $value : fail("$name is not set.");
}

function envBool(string $name, bool $default = false): bool
{
    $value = strtolower(env($name));

    return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
}

$root = rtrim(env('FLARUM_ROOT', ROOT_DEFAULT), '/');
$prefix = env('FLARUM_DB_PREFIX', 'flarum_');

if (! preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
    fail('FLARUM_DB_PREFIX may only contain letters, digits and underscores.');
}

chdir($root);

// --- helpers ------------------------------------------------------------------------------------

/** Run `php flarum <args>` in the Flarum root; returns the exit code. Output goes straight to the container log. */
function flarum(string $root, string $args): int
{
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/flarum').' '.$args, $code);

    return $code;
}

/**
 * Flarum 2 tells MySQL and MariaDB apart by the driver name in config.php: with "mysql" it sends MySQL-only SQL
 * (CAST(... AS JSON)) that MariaDB rejects, which breaks posting and notifications. The compose stack runs
 * MariaDB, so the default is "mariadb"; FLARUM_DB_DRIVER=mysql is for pointing the forum at a real MySQL server.
 */
function databaseDriver(): string
{
    $driver = strtolower(env('FLARUM_DB_DRIVER', 'mariadb'));

    return in_array($driver, ['mariadb', 'mysql'], true) ? $driver : fail("FLARUM_DB_DRIVER must be mariadb or mysql, not '$driver'.");
}

function connect(string $host, int $port, string $database, string $user, string $password): PDO
{
    return new PDO(
        "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
}

function setting(PDO $pdo, string $prefix, string $key): ?string
{
    $statement = $pdo->prepare("SELECT `value` FROM `{$prefix}settings` WHERE `key` = ?");
    $statement->execute([$key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string) $value;
}

function saveSetting(PDO $pdo, string $prefix, string $key, string $value): void
{
    $pdo->prepare("INSERT INTO `{$prefix}settings` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute([$key, $value]);
}

/** @return list<string> */
function jsonList(?string $json): array
{
    $list = $json === null ? [] : json_decode($json, true);

    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

/** "vendor/package:^1.0", "vendor-package" or "flarum-tags" -> extension id ("vendor-package") */
function extensionId(string $spec): ?string
{
    $name = strtolower(trim(explode(':', trim($spec))[0]));
    $id = str_replace('/', '-', $name);

    return preg_match('/^[a-z0-9_.-]+$/', $id) ? $id : null;
}

/** The ids to switch on: the image's list plus FLARUM_EXTENSIONS (space or comma separated). @return list<string> */
function wantedExtensions(): array
{
    $ids = DEFAULT_EXTENSIONS;

    foreach (preg_split('/[\s,]+/', env('FLARUM_EXTENSIONS'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $spec) {
        $id = extensionId($spec);

        if ($id === null) {
            warn("ignoring invalid entry '$spec' in FLARUM_EXTENSIONS.");
        } else {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/** config.php, written from the environment (same keys the installer writes). */
function writeConfig(string $root, string $prefix): void
{
    require_once $root.'/vendor/autoload.php';

    $database = new Flarum\Install\DatabaseConfig(
        databaseDriver(),
        envRequired('FLARUM_DB_HOST'),
        (int) env('FLARUM_DB_PORT', '3306'),
        envRequired('FLARUM_DB_DATABASE'),
        null,
        envRequired('FLARUM_DB_USERNAME'),
        env('FLARUM_DB_PASSWORD'),
        $prefix,
    );

    $config = [
        'debug' => envBool('FLARUM_DEBUG'),
        'database' => $database->toArray(),
        'url' => (string) Flarum\Install\BaseUrl::fromString(envRequired('FLARUM_BASE_URL')),
        'paths' => ['api' => 'api', 'admin' => 'admin'],
        'headers' => ['poweredByHeader' => false, 'referrerPolicy' => 'same-origin'],
        'queue' => ['driver' => 'sync'],
    ];

    $contents = '<?php return '.var_export($config, true).";\n";
    $file = $root.'/config.php';

    if (is_file($file) && file_get_contents($file) === $contents) {
        return;
    }

    $temporary = $file.'.tmp';
    file_put_contents($temporary, $contents);
    chmod($temporary, 0640);
    rename($temporary, $file);
    out('wrote config.php from the environment.');
}

/** Everything `php flarum install --file` needs, as JSON (the installer reads JSON or YAML). */
function installFile(string $prefix): string
{
    $settings = ['forum_title' => env('FLARUM_FORUM_TITLE', 'Community Forum')];

    // Mail settings are copied once, here, at the very first install; later changes are made in the administration.
    if (env('FLARUM_MAIL_DRIVER') === 'smtp' && env('FLARUM_MAIL_HOST') !== '') {
        $encryption = strtolower(env('FLARUM_MAIL_ENCRYPTION'));

        $settings += [
            'mail_driver' => 'smtp',
            'mail_host' => env('FLARUM_MAIL_HOST'),
            'mail_port' => env('FLARUM_MAIL_PORT', '587'),
            'mail_encryption' => in_array($encryption, ['tls', 'ssl'], true) ? $encryption : '',
            'mail_username' => env('FLARUM_MAIL_USERNAME'),
            'mail_password' => env('FLARUM_MAIL_PASSWORD'),
            'mail_from' => env('FLARUM_MAIL_FROM', 'noreply@localhost') ?: 'noreply@localhost',
        ];
    }

    $data = [
        'debug' => envBool('FLARUM_DEBUG'),
        'baseUrl' => envRequired('FLARUM_BASE_URL'),
        'databaseConfiguration' => [
            'driver' => databaseDriver(),
            'host' => envRequired('FLARUM_DB_HOST'),
            'port' => (int) env('FLARUM_DB_PORT', '3306'),
            'database' => envRequired('FLARUM_DB_DATABASE'),
            'username' => envRequired('FLARUM_DB_USERNAME'),
            'password' => env('FLARUM_DB_PASSWORD'),
            'prefix' => $prefix,
        ],
        'adminUser' => [
            'username' => envRequired('FLARUM_ADMIN_USER'),
            'password' => envRequired('FLARUM_ADMIN_PASSWORD'),
            'email' => envRequired('FLARUM_ADMIN_EMAIL'),
        ],
        'settings' => $settings,
        'queue' => ['driver' => 'sync'],
    ];

    $file = tempnam(sys_get_temp_dir(), 'flarum-install-');
    chmod($file, 0600);
    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return $file;
}

// --- 1. database ----------------------------------------------------------------------------------

$web = envBool('FLARUM_AUTORUN');
$host = envRequired('FLARUM_DB_HOST');
$port = (int) env('FLARUM_DB_PORT', '3306');
$name = envRequired('FLARUM_DB_DATABASE');
$user = envRequired('FLARUM_DB_USERNAME');
$password = env('FLARUM_DB_PASSWORD');
$timeout = (int) env('FLARUM_DB_WAIT', $web ? '180' : '600');

$pdo = null;
$deadline = time() + $timeout;
$lastReport = 0;

while ($pdo === null) {
    try {
        $pdo = connect($host, $port, $name, $user, $password);
    } catch (PDOException $e) {
        if (time() >= $deadline) {
            fail("cannot connect to the database $name on $host:$port after {$timeout}s: ".$e->getMessage());
        }

        if (time() - $lastReport >= 10) {
            out("waiting for the database ($host:$port): ".$e->getMessage());
            $lastReport = time();
        }

        sleep(2);
    }
}

/**
 * What is in the forum database? "empty" (no settings table: nothing to lose), "installed" (a Flarum version
 * is recorded and there is at least one user) or "broken" (tables of a half-finished install, no users).
 */
$state = static function () use (&$pdo, $prefix): string {
    try {
        if (setting($pdo, $prefix, 'version') === null) {
            return 'broken';
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') { // base table or view not found: no settings table yet
            return 'empty';
        }

        throw $e;
    }

    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}users`")->fetchColumn() > 0 ? 'installed' : 'broken';
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return 'broken';
        }

        throw $e;
    }
};

/** @return list<string> */
function tables(PDO $pdo, string $prefix): array
{
    $statement = $pdo->prepare('SHOW TABLES LIKE ?');
    $statement->execute([str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix).'%']);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/** Drop the forum tables that are not in $keep (used to undo a failed or half-finished install). */
function dropTables(PDO $pdo, string $prefix, array $keep = []): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach (array_diff(tables($pdo, $prefix), $keep) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', $table).'`');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// --- helper containers: wait for the forum, write config.php, done ------------------------------------

if (! $web) {
    while ($state() !== 'installed') {
        if (time() >= $deadline) {
            fail('the forum is not installed yet (is the flarum container running?).');
        }

        out('waiting for the forum to be installed...');
        sleep(5);
    }

    writeConfig($root, $prefix);
    exit(0);
}

// --- web container --------------------------------------------------------------------------------------

foreach (['cache', 'formatter', 'less', 'locale', 'logs', 'sessions', 'tmp', 'views'] as $directory) {
    if (! is_dir("$root/storage/$directory") && ! mkdir("$root/storage/$directory", 0775, true)) {
        fail("cannot create storage/$directory.");
    }
}

if (! is_dir("$root/public/assets/avatars") && ! mkdir("$root/public/assets/avatars", 0775, true)) {
    fail('cannot create public/assets/avatars.');
}

$extensions = wantedExtensions();
$forumState = $state();

if ($forumState === 'broken') {
    if ($prefix === '') {
        fail('the forum database holds an unfinished install and has no table prefix: refusing to clean it up automatically.');
    }

    warn("the forum database holds an unfinished install (no users): removing the {$prefix}* tables to start again.");
    dropTables($pdo, $prefix);
    $forumState = 'empty';
}

if ($forumState === 'empty') {
    out('empty forum database: installing Flarum (admin '.envRequired('FLARUM_ADMIN_USER').', '.envRequired('FLARUM_BASE_URL').').');

    // The installer refuses to run next to an existing config.php.
    if (is_file($root.'/config.php')) {
        unlink($root.'/config.php');
    }

    $before = tables($pdo, $prefix);
    $file = installFile($prefix);
    $code = flarum($root, 'install --file='.escapeshellarg($file));
    unlink($file);

    if ($code !== 0) {
        // The installer rolls back what it can, but a PHP fatal error skips that: leave a clean database behind.
        if ($prefix !== '') {
            dropTables($pdo, $prefix, $before);
        }

        @unlink($root.'/config.php');
        fail("php flarum install failed (exit code $code); the database was cleaned up and the next start tries again.");
    }

    // Normalise config.php. The extensions beyond Flarum's own defaults are switched on in step 4.
    writeConfig($root, $prefix);

    // fof/upload grants nothing by itself, so without this the upload button never shows up in the composer.
    // Only at the first install: members may upload (what is accepted is set in the fof/upload administration
    // page, images by default), visitors may download. Change it under Administration, Permissions.
    try {
        $pdo->exec(
            "INSERT IGNORE INTO `{$prefix}group_permission` (`group_id`, `permission`, `created_at`)
             VALUES (3, 'fof-upload.upload', NOW()), (2, 'fof-upload.download', NOW())"
        );
    } catch (PDOException $e) {
        warn('could not grant the upload permissions: '.$e->getMessage());
    }

    out('Flarum is installed.');
} else {
    writeConfig($root, $prefix);

    out('forum database found (Flarum '.(setting($pdo, $prefix, 'version') ?? '?').'): running migrations.');

    $code = flarum($root, 'migrate');

    if ($code !== 0) {
        fail("php flarum migrate failed (exit code $code).");
    }
}

// --- 3. master API key ------------------------------------------------------------------------------------

$key = env('FLARUM_API_KEY');

if ($key === '') {
    out('FLARUM_API_KEY is empty: the Laravel app will not be able to use the forum API.');
} elseif (! preg_match('/^[A-Za-z0-9]{32,100}$/', $key)) {
    warn('FLARUM_API_KEY must be 32-100 letters and digits; not stored.');
} else {
    try {
        $pdo->prepare(
            "INSERT INTO `{$prefix}api_keys` (`key`, `scopes`, `user_id`, `created_at`)
             SELECT ?, ?, NULL, NOW() FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM `{$prefix}api_keys` WHERE `key` = ?)"
        )->execute([$key, API_KEY_SCOPE, $key]);

        // A key we stored earlier and that is no longer FLARUM_API_KEY has been rotated out.
        $pdo->prepare("DELETE FROM `{$prefix}api_keys` WHERE `scopes` = ? AND `key` <> ?")->execute([API_KEY_SCOPE, $key]);

        out('API key is in place.');
    } catch (PDOException $e) {
        warn('could not store FLARUM_API_KEY: '.$e->getMessage());
    }
}

// --- 4. extensions: switch each one on once ---------------------------------------------------------------

try {
    $seen = jsonList(setting($pdo, $prefix, SEEN_KEY));
    $todo = array_values(array_diff($extensions, $seen));

    // An extension may need another one to be on first: go through the list again until nothing changes.
    for ($pass = 0; $todo !== [] && $pass < 4; $pass++) {
        $retry = [];

        foreach ($todo as $id) {
            $enabled = jsonList(setting($pdo, $prefix, 'extensions_enabled'));

            if (! in_array($id, $enabled, true)) {
                out("enabling extension $id");
                flarum($root, 'extension:enable '.escapeshellarg($id).' >/dev/null');
                $enabled = jsonList(setting($pdo, $prefix, 'extensions_enabled'));
            }

            if (in_array($id, $enabled, true)) {
                $seen[] = $id;
            } else {
                $retry[] = $id;
            }
        }

        $progress = count($retry) < count($todo);
        $todo = $retry;

        if (! $progress) {
            break;
        }
    }

    foreach ($todo as $id) {
        warn("extension $id could not be enabled (not installed in this image, or a dependency is missing).");
    }

    saveSetting($pdo, $prefix, SEEN_KEY, json_encode(array_values(array_unique($seen)), JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    warn('extension step failed: '.$e->getMessage());
}

// --- 5. assets and caches ---------------------------------------------------------------------------------

if (flarum($root, 'assets:publish') !== 0) {
    warn('php flarum assets:publish failed; the forum may be missing styles.');
}

if (flarum($root, 'cache:clear') !== 0) {
    warn('php flarum cache:clear failed.');
}

out('ready.');
exit(0);
