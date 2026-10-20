<?php

/*
 * Enables Flarum extensions through Flarum's own ExtensionManager (so migrations,
 * assets and dependency checks all happen, exactly as with the admin panel toggle).
 *
 * Usage:  php enable-extensions.php flarum-tags flarum-embed ...
 *
 * Prints one line per extension: "enabled: <id>", "already enabled: <id>" or
 * "not installed: <id>" (stderr). Extensions whose dependencies are enabled later
 * in the same list are retried.
 */

$site = require '/opt/flarum/site.php';
$app = $site->bootApp();

/** @var Flarum\Extension\ExtensionManager $manager */
$manager = $app->getContainer()->make(Flarum\Extension\ExtensionManager::class);

$pending = array_slice($argv, 1);

for ($pass = 0; $pass < 5 && $pending; $pass++) {
    $retry = [];

    foreach ($pending as $id) {
        if ($manager->getExtension($id) === null) {
            fwrite(STDERR, "not installed: $id\n");
            continue;
        }

        if ($manager->isEnabled($id)) {
            echo "already enabled: $id\n";
            continue;
        }

        try {
            $manager->enable($id);
            echo "enabled: $id\n";
        } catch (Flarum\Extension\Exception\MissingDependenciesException $e) {
            $retry[] = $id;
        }
    }

    $pending = $retry;
}

foreach ($pending as $id) {
    fwrite(STDERR, "dependencies missing: $id\n");
}
