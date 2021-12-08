<?php

/**
 * Build utility
 *
 * To Use:
 *
 *     sudo php -dphar.readonly=0 build.php [/output/path/]
 *
 * Build process will place package into bin/module-bundler
 */

if (ini_get('phar.readonly')) {
    die('phar.readonly must be disabled' . PHP_EOL);
}

define('BASE_DIR', dirname(__FILE__));
define('COMPILE_NAME', 'module-bundler.phar');
define('COMPILE_SRC', BASE_DIR . '/src');
define('COMPILE_DEST', BASE_DIR . '/build');
define('OUTPUT_PATH', empty($argv[1]) ? (__DIR__ . '/bin') : $argv[1]);
define('PHP_PATH', '/usr/bin/env php');
define('FINAL_PATH', COMPILE_DEST . '/' . COMPILE_NAME);
define('BUILD_PATH', COMPILE_DEST . '/artifacts');

// Prepare environment
// ------------------------------------------------------------------------------------------------------------------ //

cleanBuildPath();
installTools();
prepareBuildPath();

// Run build
// ------------------------------------------------------------------------------------------------------------------ //

$package = new Phar(FINAL_PATH);
$package->startBuffering();

$processor = new PhpMinify([
    'source' => COMPILE_SRC,
    'target' => BUILD_PATH . '/src',
    'extensions' => ['php']
]);
$processor->run();

$package->buildFromDirectory(BUILD_PATH, '#^((?!([tT]est(s)*/)|(doc/)|(bin/)).)*.(php|json)$#');

$stub = $package->createDefaultStub('/src/bootstrap.php');
$package->setStub(sprintf('#!%s%s%s', PHP_PATH, PHP_EOL, $stub));

$package->stopBuffering();

echo sprintf("Build complete => %s%s", FINAL_PATH, PHP_EOL);

if (!empty(OUTPUT_PATH)) {
    copyBuild(FINAL_PATH, OUTPUT_PATH);
    echo sprintf("Additional path => %s%s", realpath(OUTPUT_PATH), PHP_EOL);
}

// Utilities
// ------------------------------------------------------------------------------------------------------------------ //

function cleanBuildPath() {
    shell_exec(sprintf('rm -rf %s/*', COMPILE_DEST));
}

function copyBuild($source, $target) {
    if (is_dir($target)) {
        $fileName = basename($source, '.phar');
    } elseif (is_dir(dirname($target))) {
        $fileName = basename($target, '.phar');
        $target = dirname($target);
    }

    if (!is_dir($target)) {
        mkdir($target, true);
    }

    $destination = rtrim($target, '/') . '/' . $fileName;
    copy($source, $destination);
    chmod($destination, 0755);
}

function installTools() {
    $toolsPath = COMPILE_DEST . '/tools';
    mkdir($toolsPath, 0777, true);

    $phpMinifyBundlePath = $toolsPath . '/php-minify.zip';
    $phpMinifySourcePath = $toolsPath;

    shell_exec(
        sprintf(
            'wget -q -O %s %s',
            $phpMinifyBundlePath,
            'https://github.com/basselin/php-minify/archive/master.zip'
        )
    );

    shell_exec(
        sprintf('unzip %s -d %s', $phpMinifyBundlePath, $phpMinifySourcePath)
    );

    require_once $phpMinifySourcePath . '/php-minify-master/phpminify.php';
}

function prepareBuildPath() {
    shell_exec(
        sprintf(
            'mkdir -p %s;
            cp -f %s/composer.{json,lock} %s/;
            composer install --quiet --no-dev --ignore-platform-reqs --working-dir="%s"',
            BUILD_PATH,
            BASE_DIR,
            BUILD_PATH,
            BUILD_PATH
        )
    );
}