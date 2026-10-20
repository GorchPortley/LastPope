<?php

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Test database
|--------------------------------------------------------------------------
|
| Wave resolves the active theme (and with it the Folio page directories) from the
| database while the application boots, so an in-memory database that is migrated
| after the boot would leave every page route returning 404. The test database is
| therefore a SQLite file that is migrated and seeded once, before the first test
| boots the application. RefreshDatabase then wraps every test in a transaction.
|
*/

$database = __DIR__.'/../database/testing.sqlite';

foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'APP_ENV' => 'testing'] as $name => $value) {
    putenv("$name=$value");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

@unlink($database);
touch($database);

passthru(
    escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../artisan').' migrate:fresh --seed --force --no-interaction',
    $exitCode,
);

if ($exitCode !== 0) {
    fwrite(STDERR, "Could not prepare the test database.\n");
    exit(1);
}
