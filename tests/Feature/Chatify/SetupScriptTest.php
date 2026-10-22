<?php

/*
|--------------------------------------------------------------------------
| docker/setup.sh --secrets-only and the auto-updater step that uses it
|--------------------------------------------------------------------------
|
| A server that has not run setup.sh since compose.yaml began to require the REVERB_* secrets would refuse every
| deploy. The updater therefore runs `setup.sh --secrets-only` after the merge. It must add only what is missing
| and leave every other byte of .env alone.
*/

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** A scratch copy of the repository's setup.sh with an .env in it; returns the directory. */
function setupSandbox(string $env): string
{
    $dir = sys_get_temp_dir().'/lastpope-setup-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir.'/docker');
    File::copy(base_path('docker/setup.sh'), $dir.'/docker/setup.sh');
    File::copy(base_path('.env.example'), $dir.'/.env.example');
    File::put($dir.'/.env', $env);

    return $dir;
}

/** What a behind-proxy server's .env looks like after an earlier setup.sh and some hand edits (no REVERB_* yet). */
function existingServerEnv(array $override = []): string
{
    $values = array_merge([
        'APP_KEY' => 'base64:ZdraLlH8ljoICqTTIDjJ9ZAgF1inNr1NW+4vY7GQ3ro=',
        'APP_DOMAIN' => 'shop.example.com',
        'ACME_EMAIL' => 'me@example.com',
        'APP_URL' => 'https://shop.example.com',
        'CADDY_CONFIG' => 'Caddyfile.behind-proxy',
        'CADDY_HTTP_BIND' => '127.0.0.1:8880',
        'CADDY_HTTPS_BIND' => '127.0.0.1:8881',
        'MARIADB_ROOT_PASSWORD' => 'rootpw0123456789',
        'MARIADB_PASSWORD' => 'dbpw0123456789',
        'FLARUM_DB_PASSWORD' => 'fdbpw0123456789',
        'REDIS_PASSWORD' => 'redispw0123456789',
        'FLARUM_ADMIN_PASSWORD' => 'adminpw0123456789',
        'JWT_SECRET' => 'SeCrEtKeY',          // the example placeholder: a deploy must not "fix" it
        'MAIL_FROM_ADDRESS' => '"hello@example.com"', // likewise the example mail address
        'MAIL_PASSWORD' => '"p@ss word#with spaces"',
    ], $override);

    $lines = ["# hand edited\r"];
    foreach ($values as $key => $value) {
        $lines[] = "$key=$value";
    }
    $lines[] = '';
    $lines[] = "STRIPE_SECRET_KEY=sk_live_51abc";
    $lines[] = 'DEMO_PASSWORD=\'demo pass\'';

    return implode("\n", $lines)."\n";
}

it('adds only the missing REVERB secrets and leaves every other byte of .env alone', function () {
    $original = existingServerEnv();
    $dir = setupSandbox($original);
    chmod($dir.'/.env', 0640);

    $run = Process::path($dir)->input('')->run(['bash', 'docker/setup.sh', '--secrets-only']);
    $after = File::get($dir.'/.env');

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET')
        // the original file is a byte-for-byte prefix: nothing before the appended lines was touched
        ->and(str_starts_with($after, $original))->toBeTrue()
        ->and(substr($after, strlen($original)))->toMatch('/\AREVERB_APP_ID=[1-9]\d{7}\nREVERB_APP_KEY=[a-z0-9]{20}\nREVERB_APP_SECRET=[a-z0-9]{40}\n\z/')
        ->and(decoct(fileperms($dir.'/.env') & 0777))->toBe('640')
        // names only: no value is ever printed
        ->and($run->output().$run->errorOutput())->not->toMatch('/REVERB_APP_(KEY|SECRET)=|[a-z0-9]{20}/');

    // the second run has nothing to add
    $run = Process::path($dir)->input('')->run(['bash', 'docker/setup.sh', '--secrets-only']);
    expect($run->successful())->toBeTrue()
        ->and(File::get($dir.'/.env'))->toBe($after);

    File::deleteDirectory($dir);
});

it('keeps secrets that are already set, and never asks a question', function () {
    $env = existingServerEnv()."REVERB_APP_ID=424242\nREVERB_APP_KEY=mykey\nREVERB_APP_SECRET=mysecret\n";
    $dir = setupSandbox($env);

    $run = Process::path($dir)->timeout(30)->input('')->run(['bash', 'docker/setup.sh', '--secrets-only']);

    expect($run->successful())->toBeTrue()->and(File::get($dir.'/.env'))->toBe($env);
    File::deleteDirectory($dir);
});

it('needs an existing .env for --secrets-only instead of creating one', function () {
    $dir = setupSandbox('');
    File::delete($dir.'/.env');

    $run = Process::path($dir)->input('')->run(['bash', 'docker/setup.sh', '--secrets-only']);

    expect($run->failed())->toBeTrue()->and(File::exists($dir.'/.env'))->toBeFalse();
    File::deleteDirectory($dir);
});

it('keeps the existing CADDY_* lines when re-run without choosing a proxy mode', function () {
    foreach ([
        ['CADDY_CONFIG' => 'Caddyfile', 'CADDY_HTTP_BIND' => '8080', 'CADDY_HTTPS_BIND' => '8443'],
        ['CADDY_CONFIG' => 'Caddyfile.behind-proxy', 'CADDY_HTTP_BIND' => '0.0.0.0:9000', 'CADDY_HTTPS_BIND' => '127.0.0.1:9443'],
    ] as $caddy) {
        $dir = setupSandbox(existingServerEnv($caddy));

        // no terminal, so no question can be asked; -n for the same reason when a terminal does exist
        $run = Process::path($dir)->timeout(30)->input('')->run(['bash', 'docker/setup.sh', '--non-interactive']);
        $after = File::get($dir.'/.env');

        expect($run->successful())->toBeTrue($run->output().$run->errorOutput());
        foreach ($caddy as $key => $value) {
            expect($after)->toContain("\n$key=$value\n");
        }
        expect($after)->toContain('REVERB_APP_ID=');

        File::deleteDirectory($dir);
    }
});

it('runs setup.sh --secrets-only in the auto-updater after the merge and before the compose check', function () {
    $deploy = file_get_contents(base_path('docker/deploy/auto-deploy.sh'));

    $merge = strpos($deploy, 'step "git merge --ff-only');
    $secrets = strpos($deploy, '--secrets-only </dev/null');
    $check = strpos($deploy, 'step "check the compose configuration"');

    expect($merge)->toBeInt()->and($secrets)->toBeInt()->and($check)->toBeInt()
        ->and($merge)->toBeLessThan($secrets)
        ->and($secrets)->toBeLessThan($check);

    // the dry run only announces it: the call sits after the dry-run `return 0`
    $dryRunEnd = strpos($deploy, '[dry-run] nothing was changed.');
    expect($dryRunEnd)->toBeLessThan($secrets)
        ->and($deploy)->toContain('[dry-run] would run: git merge --ff-only; docker/setup.sh --secrets-only');
});
