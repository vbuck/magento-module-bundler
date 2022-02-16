<?php
/**
 * Application bootstrap for with the compiled Phar package.
 *
 * @author Rick Buczynski <richard.buczynski@gmail.com>
 * @license MIT
 */

declare(strict_types=1);

define('DS', DIRECTORY_SEPARATOR);
$appPath = dirname(__DIR__);
$autoloader = $appPath . DS . 'vendor' . DS . 'autoload.php';

if (!file_exists($autoloader)) {
    echo "Cannot run application.\n> Vendor libraries not installed. Please run: composer install";
    exit(1);
}

require_once $autoloader;

$input = mapInput();

if ($input['help']) {
    showHelp();
    exit(0);
}

try {
    $instance = new \Vbuck\MagentoModuleBundler\Bundler($input['base_path']);
    $results = $instance->bundle(
        $input['packages'],
        $input['output_path'],
        (int) $input['behavior'],
        (int) $input['output_type'],
        $input['exclude']
    );
    $hasError = false;

    if (empty($results)) {
        echo 'No packages bundled.' . PHP_EOL;
        exit(0);
    }

    foreach ($results as $artifact) {
        if ($artifact->state === true) {
            $bundleName = \basename($artifact->bundlePath);

            echo "→ Bundled package '{$artifact->name}' into {$bundleName}" . PHP_EOL;
            if ($artifact->message) {
                echo "{$artifact->message}" . PHP_EOL;
            }
        } else {
            echo "→ Failed to bundle package: {$artifact->message}" . PHP_EOL;
            $hasError = true;
        }
    }

    echo PHP_EOL;

    exit((int) $hasError);
} catch (\Exception $error) {
    echo $error->getMessage() . PHP_EOL;
    exit(1);
}

function mapInput() {
    global $argv;
    $input = [
        'base_path' => rtrim(getcwd(), DS),
        'output_path' => rtrim(getcwd(), DS),
        'output_type' => null,
        'packages' => [],
        'exclude' => [],
        'behavior' => null,
        'help' => false,
    ];

    foreach ($argv as $value) {
        if ($value === '--help') {
            $input['help'] = true;
        } else if ($value === '--single-bundle') {
            $input['behavior'] = \Vbuck\MagentoModuleBundler\Bundler::BEHAVIOR_SINGLE_BUNDLE;
        } else if ($value === '--composer-artifact') {
            $input['output_type'] = \Vbuck\MagentoModuleBundler\Bundler::OUTPUT_TYPE_COMPOSER;
        } else {
            preg_match('/^\-\-package=(.*$)/', $value, $match);
            !empty($match[1]) && $input['packages'][] = \trim($match[1], '\'"');

            preg_match('/^\-\-exclude=(.*$)/', $value, $match);
            !empty($match[1]) && $input['excludes'][] = \trim($match[1], '\'"');

            preg_match('/^\-\-app\-root=(.*$)/', $value, $match);
            !empty($match[1]) && $input['base_path'] = \trim($match[1], '\'"');

            preg_match('/^\-\-output\-path=(.*$)/', $value, $match);
            !empty($match[1]) && $input['output_path'] = \trim($match[1], '\'"');
        }
    }

    if (!$input['behavior']) {
        $input['behavior'] = \Vbuck\MagentoModuleBundler\Bundler::BEHAVIOR_INDIVIDUAL_BUNDLES;
    }

    return $input;
}

function showHelp() {
    echo  <<<EOF
Module Bundler Utility

Bundles Magento modules from Composer vendor spaces into ZIP packages.

To use:

    bin/module-bundler --app-root=/path/to/magento \
        --package=/path/to/magento/root \
        --package=vendor/package1 \
        --package=/path/to/other/package \
        --package=package-*
        --exclude=/some/regex-file-pattern.*
        --single-bundle
        --output-path=/path/to/bundles

Options:
    --app-root[=PATH]       Optional path to a Magento installation. Defaults to current working directory.
    --output-path[=PATH]    Optional path to write your bundles. Defaults to current working directory.
    --package[=SEARCH]      A search string for a package. Can be absolute, relative, package name, wildcard.
    --exclude[=SEARCH]      Optionally exclude files from the bundle.
    --single-bundle         Optional flag to bundle all matching packages into a single artifact.
    --composer-artifact     Optional flag to output the bundle as a Composer artifact instead of a Magento bundle.

https://github.com/vbuck/magento-module-bundler
(c) Rick Buczynski <richard.buczynski@gmail.com>

EOF;
}
