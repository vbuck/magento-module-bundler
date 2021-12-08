<?php
/**
 * @author Rick Buczynski <richard.buczynski@gmail.com>
 * @license MIT
 */

declare(strict_types=1);

namespace Vbuck\MagentoModuleBundler;

/**
 * Module bundling utility. Bundles Magento modules from Composer vendor spaces into ZIP artifacts.
 *
 * In some cases, a module must be installed using the `app/code` space, even though it is only configured for Composer
 * based installation. This utility helps to bundle one or more Composer packages into a single ZIP archive.
 */
class Bundler
{
    const BEHAVIOR_INDIVIDUAL_BUNDLES = 1;
    const BEHAVIOR_SINGLE_BUNDLE = 2;
    const ERROR_CREATE_ARCHIVE = 'Failed to create archive for package: %s';
    const ERROR_NO_MODULE = 'Failed to locate module at path: %s';
    const WARNING_LIB_PATH_DETECTED = 'Lib path detected. It must be registered with your autoloader before use.';
    const WARNING_MISSING_VENDOR_PATH = 'The Composer vendor directory was not found in the given base path.';

    /** @var string */
    private $basePath;

    /**
     * @param string $basePath
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = \rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->checkBasePath($this->basePath);
    }

    /**
     * Bundle packages into ZIP artifacts.
     *
     * Returns a report as an array indexed by the given packages, as:
     *
     * [
     *     ['id' => string, 'path' => string, 'bundle' => string, 'state' => boolean, 'name' => string, 'message' => string],
     *     […],
     * ]
     *
     * @param string[] $packages An array of package names, search strings, or paths.
     * @param string $outputPath A directory in which to output the bundles.
     * @param int $behavior How to bundle the paths, where 1 = individual artifacts, 2 = single bundle
     * @return array A status report in the order of provided packages.
     */
    public function bundle(
        array $packages = [],
        string $outputPath = '',
        int $behavior = self::BEHAVIOR_INDIVIDUAL_BUNDLES
    ) : array {
        $results = [];
        $manifest = [];

        foreach ($packages as $key => $search) {
            foreach ($this->expandPath($search) as $sourcePath) {
                $result = $this->createMutableResult($search, false);

                if (!$this->resolveLibPath($sourcePath, $result)
                    && !$this->resolveModulePath($sourcePath, $result)) {
                    throw new \InvalidArgumentException(\sprintf(self::ERROR_NO_MODULE, $sourcePath));
                }

                if ($result->state === 'skip') {
                    continue;
                }

                $results[] = $result;
                $manifest[$result->path] = [
                    'source' => $sourcePath,
                    'result' => $result,
                ];
            }
        }

        try {
            $this->createBundles($manifest, $behavior, $outputPath);
            $this->setResultState($results, true);
        } catch (\Exception $error) {
            $this->setResultState($results, false, $error->getMessage(), $error);
        }

        return $results;
    }

    /**
     * @param string $path
     */
    private function checkBasePath(string $path)
    {
        $testPath = $path . DIRECTORY_SEPARATOR . 'vendor';

        if (!\file_exists($testPath)) {
            echo 'Warning: ' . static::WARNING_MISSING_VENDOR_PATH . PHP_EOL;
        }
    }

    /**
     * Generate a bundle from the given manifest.
     *
     * @param array $manifest
     * @param int $behavior
     * @param string $outputPath
     * @throws \Exception
     */
    private function createBundles(array $manifest, int $behavior, string $outputPath) : void
    {
        @\mkdir($outputPath, 0755, true);
        $pathTemplate = \rtrim($outputPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '{name}.zip';
        $zip = new \ZipArchive();
        $name = null;

        foreach ($manifest as $target => $info) {
            if ($behavior === self::BEHAVIOR_INDIVIDUAL_BUNDLES) {
                $zip = new \ZipArchive();
                $name = $info['result']->name;
            } else if (!$name) {
                $name = uniqid('bundle_');
            }

            $path = \str_replace('{name}', $name, $pathTemplate);
            $status = $zip->open($path, \ZipArchive::CREATE);

            if ($status !== true) {
                throw new \Exception(
                    \sprintf(self::ERROR_CREATE_ARCHIVE, $name),
                    (int) $status
                );
            }

            foreach ($this->generateFileList($info['source']) as $filePath) {
                $archivePath = \str_replace($info['source'], $target, $filePath);

                if (\is_dir($filePath)) {
                    $zip->addEmptyDir($archivePath);
                } else {
                    $zip->addFile($filePath, $archivePath);
                }
            }

            $zip->close();
            $info['result']->bundlePath = $path;
        }
    }

    /**
     * @param string $key The user-provided identifier of the package.
     * @param bool $state The state of the process.
     * @param string $path The target app-code installation path.
     * @param string $bundlePath The output path of the bundle in which the package was added.
     * @param string $name The name of the Magento module.
     * @param string $message A message describing the state of the process.
     * @return \stdClass
     */
    private function createMutableResult(
        string $key,
        bool $state = false,
        string $path = '',
        string $bundlePath = '',
        string $name = '',
        string $message = ''
    ) : \stdClass {
        $result = new \stdClass();

        $result->key = $key;
        $result->path = $path;
        $result->bundlePath = $bundlePath;
        $result->state = $state;
        $result->name = $name;
        $result->message = $message;

        return $result;
    }

    /**
     * Attempt the expand the given search string into a valid path to a package.
     *
     * Paths tried:
     * - Exact match (search is an absolute path; ex: /path/to/vendor/package)
     * - Relative path (search is relative to the app root; ex: vendor/namespace/component)
     * - Package match (search is a specific package name; ex: vendor/package-name)
     * - Wildcard match (search is a package name with wildcard; ex: vendor/package-* or package-*)
     *
     * @param string $search
     * @return array
     */
    private function expandPath(string $search = '') : array
    {
        $results = [];
        $tryPaths = [
            // Exact match
            $search,
            // Relative path
            $this->basePath . DIRECTORY_SEPARATOR
                . \ltrim($search, DIRECTORY_SEPARATOR),
            // Package match
            $this->basePath . DIRECTORY_SEPARATOR
                . 'vendor' . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $search),
        ];

        // Wildcard match
        if (\strstr($search, '*') !== false) {
            $tryPaths[] = $this->basePath . DIRECTORY_SEPARATOR
                . 'vendor' . DIRECTORY_SEPARATOR . $search;
            $tryPaths[] = $this->basePath . DIRECTORY_SEPARATOR
                . 'vendor' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $search;
        }

        foreach ($tryPaths as $path) {
            if (\file_exists($path)) {
                $results[] = $path;
            } else if (($matches = \glob($path))) {
                $results += $matches;
            }
        }

        return \array_unique($results);
    }

    private function generateFileList(string $path, $flags = 0) : array
    {
        $result = \glob($path, $flags);

        foreach ($result as $item) {
            if (\is_dir($item)) {
                \array_push(
                    $result,
                    ...$this->generateFileList($item . DIRECTORY_SEPARATOR . '*', $flags)
                );
            }
        }

        return $result;
    }

    /**
     * Determine whether the given path refers to a Composer meta-package.
     *
     * @param string $path An absolute path to the package.
     * @return array
     */
    private function isMetapackage(string $path) : bool
    {
        $configPath = current(
            (array) \glob(
                \rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . 'composer.json'
            )
        );

        if (!$configPath || !\is_readable($configPath)) {
            return false;
        }

        $config = (array) @\json_decode(@\file_get_contents($configPath), true);

        if (!empty($config['type']) && $config['type'] === 'metapackage') {
            return true;
        }

        return false;
    }

    /**
     * Resolve the expected library (non-module) path from the given source path and write it to the result.
     *
     * Works by reading the Composer file and converting its PSR-4 mapping to a Magento library path for bundling.
     *
     * @param string $path An absolute path to the library source; ex: a 3rd-party SDK
     * @param \stdClass $result The processing result.
     * @return bool
     */
    private function resolveLibPath(string $path, \stdClass $result) : bool
    {
        $configPath = current(
            (array) \glob(
                \rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . 'composer.json'
            )
        );

        if (!$configPath || !\is_readable($configPath)) {
            return false;
        }

        $config = (array) @\json_decode(@\file_get_contents($configPath), true);

        if (empty($config['type']) || $config['type'] !== 'library' || empty($config['autoload']['psr-4'])) {
            return false;
        }

        $result->name = \rtrim(\current(\array_keys($config['autoload']['psr-4'])), '\\');
        $result->path = 'lib' . DIRECTORY_SEPARATOR
            . \str_replace('\\', DIRECTORY_SEPARATOR, $result->name);
        $result->message = self::WARNING_LIB_PATH_DETECTED;
        return true;
    }

    /**
     * Resolve the expected module path from the given source path and write it to the result.
     *
     * @param string $path An absolute path to the module source.
     * @param \stdClass $result The processing result.
     * @return bool
     */
    private function resolveModulePath(string $path, \stdClass $result) : bool
    {
        if ($this->isMetapackage($path)) {
            $result->state = 'skip';
            return true;
        }

        $configPath = current(
            (array) \glob(
                \rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . 'etc' . DIRECTORY_SEPARATOR
                . 'module.xml'
            )
        );

        if (!$configPath) {
            return false;
        }

        try {
            $config = new \DOMDocument();
            $config->load($configPath);
            $result->name = $config->getElementsByTagName('module')[0]->getAttribute('name');
            $moduleName = \explode('_', $result->name);
            $result->path = 'app' . DIRECTORY_SEPARATOR
                . 'code' . DIRECTORY_SEPARATOR
                . $moduleName[0] . DIRECTORY_SEPARATOR
                . $moduleName[1];

            return true;
        } catch (\Exception $error) {
            return false;
        }
    }

    /**
     * Set a state for all results in the given set.
     *
     * @param array $results
     * @param bool $state
     * @param string $message
     * @param \Exception|null $context
     */
    private function setResultState(
        array $results,
        bool $state,
        string $message = '',
        \Exception $context = null
    ) : void {
        foreach ($results as $result) {
            $result->state = $state;

            if (!empty($message)) {
                $result->message = $message;

                if ($context && $context->getCode()) {
                    $result->message .= \sprintf(' (code %s)', $context->getCode());
                }
            }
        }
    }
}
