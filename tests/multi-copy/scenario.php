<?php

/**
 * Loads several bundled copies of WP Wireframe into one PHP process and checks
 * which copy serves the framework classes. Exits 1 on any failed check.
 *
 * Usage: php scenario.php <label> <mode> <expected-winner-dir> [flags] <autoload.php>...
 *   mode `normal`: require every copy, then fire plugins_loaded.
 *   mode `early`:  require the first copy, use a class, require the rest, use another, then fire.
 *   mode `during`: require every copy from a plugins_loaded callback at the default priority.
 *   mode `after`:  require the first copy, fire plugins_loaded, then require the rest.
 *   --class=<FQCN>           also expect this class to load from the winner.
 *   --major-warning=<text>   expect one major-version warning containing each given text.
 *
 * WF_PLUGIN_DIR is the fake plugins directory the copies live under.
 */

declare(strict_types=1);

const WP_DEBUG = true;
define('WP_PLUGIN_DIR', realpath((string) getenv('WF_PLUGIN_DIR')));

$hooks = [];
$didActions = [];
$doingItWrong = [];

function add_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['hooks'][$hook][$priority][] = $callback;
}

function did_action(string $hook): int
{
    return $GLOBALS['didActions'][$hook] ?? 0;
}

function do_action(string $hook): void
{
    $GLOBALS['didActions'][$hook] = did_action($hook) + 1;
    $byPriority = $GLOBALS['hooks'][$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    $GLOBALS['doingItWrong'][] = [$function, $message];
}

function plugin_basename(string $file): string
{
    return trim((string) preg_replace('#^' . preg_quote(WP_PLUGIN_DIR, '#') . '/#', '', $file), '/');
}

[, $label, $mode, $expected] = $argv;
$expected = realpath($expected);
$autoloaders = $extraClasses = $majorWarning = [];
foreach (array_slice($argv, 4) as $arg) {
    if (str_starts_with($arg, '--class=')) {
        $extraClasses[] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--major-warning=')) {
        $majorWarning[] = substr($arg, 16);
    } else {
        $autoloaders[] = $arg;
    }
}
$failures = [];

/**
 * Record a failed check.
 *
 * @param bool   $ok      Whether the check passed.
 * @param string $message What went wrong.
 */
function check(bool $ok, string $message): void
{
    if (! $ok) {
        $GLOBALS['failures'][] = $message;
    }
}

/**
 * File a class was loaded from.
 *
 * @param string $class Fully qualified class name.
 * @return string
 */
function fileOf(string $class): string
{
    return (string) (new ReflectionClass($class))->getFileName();
}

$early = [];
switch ($mode) {
    case 'early':
        require $autoloaders[0];
        $early['Wireframe\Config'] = dirname($autoloaders[0], 2);
        class_exists(Wireframe\Config::class);
        foreach (array_slice($autoloaders, 1) as $autoloader) {
            require $autoloader;
        }
        $early['Wireframe\Settings'] = $expected;
        class_exists(Wireframe\Settings::class);
        do_action('plugins_loaded');
        break;
    case 'during':
        add_action('plugins_loaded', static function () use ($autoloaders): void {
            foreach ($autoloaders as $autoloader) {
                require $autoloader;
            }
        });
        do_action('plugins_loaded');
        break;
    case 'after':
        require $autoloaders[0];
        do_action('plugins_loaded');
        foreach (array_slice($autoloaders, 1) as $autoloader) {
            require $autoloader;
        }
        break;
    default:
        foreach ($autoloaders as $autoloader) {
            require $autoloader;
        }
        do_action('plugins_loaded');
}

$active = Wireframe\Versions::active();
check($active !== null && $active['dir'] === $expected, sprintf('active copy is %s, expected %s', $active['dir'] ?? 'none', $expected));

foreach ($early as $class => $dir) {
    check(str_starts_with(fileOf($class), realpath($dir) . '/'), "$class should have loaded early from $dir, got " . fileOf($class));
}
$earlyNotices = array_column(array_filter($doingItWrong, static fn ($call) => $call[0] !== 'Wireframe\Versions'), 0);
check($earlyNotices === ($mode === 'early' ? ['Wireframe\Config'] : []), 'early-use notices: ' . json_encode($earlyNotices));

$majorNotices = array_column(array_filter($doingItWrong, static fn ($call) => $call[0] === 'Wireframe\Versions'), 1);
if ($majorWarning === []) {
    check($majorNotices === [], 'unexpected major-version warning: ' . json_encode($majorNotices));
} else {
    check(count($majorNotices) === 1, 'expected one major-version warning, got ' . json_encode($majorNotices));
    foreach ($majorWarning as $text) {
        check(str_contains($majorNotices[0] ?? '', $text), "major-version warning lacks \"$text\": " . json_encode($majorNotices));
    }
}

foreach (Wireframe\Versions::registered() as $copy) {
    $plugin = explode('/', plugin_basename($copy['dir']))[0];
    check($copy['source'] === $plugin, "source for {$copy['dir']} is {$copy['source']}, expected $plugin");
}

foreach ($extraClasses as $class) {
    check(class_exists($class) && str_starts_with(fileOf($class), "$expected/"), "$class not served by the winner");
}

$src = $expected . '/src';
$classes = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() !== 'php' || str_starts_with($path, "$src/assets/")) {
        continue;
    }
    $class = 'Wireframe\\' . str_replace('/', '\\', substr($path, strlen($src) + 1, -4));
    if (! class_exists($class) && ! interface_exists($class)) {
        check(false, "$class did not load");
        continue;
    }
    if (! isset($early[$class])) {
        check(fileOf($class) === $path, "$class loaded from " . fileOf($class));
    }
    $classes++;
}

foreach (['Wireframe\Framework\Access\PageFlags', 'Wireframe\Framework\Fields\UploadField'] as $class) {
    check(class_exists($class) && fileOf($class) === $src . '/' . str_replace('\\', '/', substr($class, 10)) . '.php', "$class not served by the winner");
}

$rakit = fileOf(Rakit\Validation\Validator::class);
check(
    str_starts_with($rakit, "$expected/vendor/") || str_starts_with($rakit, dirname($expected, 2) . '/magewirephp/'),
    "Rakit loaded from $rakit"
);

if ($failures !== []) {
    echo "FAIL  $label\n      - " . implode("\n      - ", $failures) . "\n";
    exit(1);
}

$versions = implode(', ', array_map(static fn ($copy) => "{$copy['source']} {$copy['version']}", Wireframe\Versions::registered()));
if ($majorNotices !== []) {
    $versions .= '] warning ["' . $majorNotices[0] . '"';
}
printf("PASS  %-52s registered [%s], active %s, %d classes\n", $label, $versions, $active['version'], $classes);
