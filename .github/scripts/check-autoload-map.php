<?php

/**
 * Fails the release when autoload-map.php doesn't match what the zip bundles: every
 * mapped namespace must exist in the zip, and every bundled PSR-4 namespace must be
 * mapped for both the zip and the Composer layout.
 *
 * Usage: php check-autoload-map.php <staged copy root>
 */

declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');
$map = require "$root/autoload-map.php";
$lock = json_decode((string) file_get_contents("$root/composer.lock"), true, 512, JSON_THROW_ON_ERROR);
$errors = [];

$normalise = static fn (string $path): string => rtrim($path, '/') . '/';

foreach ($map as $prefix => $paths) {
    if (array_filter((array) $paths, static fn (string $path): bool => is_dir("$root/$path")) === []) {
        $errors[] = "$prefix maps to nothing in the zip";
    }
}

foreach ($lock['packages'] as $package) {
    $name = $package['name'];
    foreach ($package['autoload'] ?? [] as $type => $entries) {
        if ($type === 'exclude-from-classmap') {
            continue;
        }
        if ($type !== 'psr-4') {
            $errors[] = "$name uses $type autoloading, which autoload-map.php can't express";
            continue;
        }
        foreach ($entries as $prefix => $dirs) {
            $mapped = array_map($normalise, (array) ($map[$prefix] ?? []));
            foreach ((array) $dirs as $dir) {
                foreach (["vendor/$name/$dir", "../../$name/$dir"] as $expected) {
                    if (! in_array($normalise($expected), $mapped, true)) {
                        $errors[] = "$prefix from $name is not mapped to {$normalise($expected)}";
                    }
                }
            }
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        echo "::error file=autoload-map.php::$error\n";
    }
    exit(1);
}

echo 'autoload-map.php covers: ' . implode(', ', array_keys($map)) . "\n";
