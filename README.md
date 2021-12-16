# Magento Module Bundler Utility

Bundles Magento modules from Composer vendor spaces into ZIP artifacts.

In some cases, a module must be installed using the `app/code` space, even though it is only configured for Composer
based installation. This utility helps to bundle one or more Composer packages into a single ZIP archive.

## Use Cases

* Offering multiple options for installation without manual packaging effort.
* Creating bundles of modules from one or more Composer packages for distribution. 

This tool was built to support automated bundling of multiple Composer packages from a meta-package.

## Installation

Works as a Composer package to load with your project:

    composer config repositories.magento-module-bundler vcs https://github.com/vbuck/magento-module-bundler.git
    composer require vbuck/magento-module-bundler:*

Or you can download the standalone CLI tool:

    wget https://raw.githubusercontent.com/vbuck/magento-module-bundler/main/bin/module-bundler
    chmod +x module-bundler
    sudo mv module-bundler /usr/local/bin/

## How to Use

Example scenario:

1. I need to install a module into `app/code` but it only installs via Composer.
2. To perform this action, I first need use Composer to download the package.
3. I must then copy its content into the `app/code` space using the correct vendor and module name as a path.
4. I must then remove the original copy from the vendor space, and optionally clean my Composer files.
5. I can optionally bundle the moved contents into a ZIP archive for distribution.

I want to automate this process using a single command.

### Code Sample

    $instance = new \Vbuck\MagentoModuleBundler\Bundler('/path/to/magento/root');
    $result = $instance->bundle(
        [
            'vendor/package1',
            'vendor/package2',
            '/path/to/other/package',
            'package-*',
        ],
        '/path/to/output',
        \Vbuck\MagentoModuleBundler\Bundler::BEHAVIOR_INDIVIDUAL_BUNDLES, // or BEHAVIOR_SINGLE_BUNDLE
        \Vbuck\MagentoModuleBundler\Bundler::OUTPUT_TYPE_MAGENTO // or OUTPUT_TYPE_COMPOSER
    );
    
    if ($result[0]->state === true) {
     echo "Module {$result[0]->name} has been bundled"; 
    }

### Command-Line Usage

    bin/module-bundler --app-root=/path/to/magento \
        --package=/path/to/magento/root \
        --package=vendor/package1 \
        --package=/path/to/other/package \
        --package=package-*
        --single-bundle
        --output-path=/path/to/bundles
 
    Options:
        --app-root[=PATH]       Optional path to a Magento installation. Defaults to current working directory.
        --output-path[=PATH]    Optional path to write your bundles. Defaults to current working directory.
        --package[=SEARCH]      A search string for a package. Can be absolute, relative, package name, wildcard.
        --single-bundle         Optional flag to bundle all matching packages into a single artifact.
        --composer-artifact     Optional flag to output the bundle as a Composer artifact instead of a Magento bundle.

## Architectural Principles

* Simple: low complexity, few dependencies, "gistable" deployment
* Flexible: can use in a project or as a standalone utility
