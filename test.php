<?php

require_once 'src/Bundler.php';

$instance = new \Vbuck\MagentoModuleBundler\Bundler(
    '/Volumes/CaseSensitive/projects/walmart/walmart-bopis-env-cloud'
);
$result = $instance->bundle(
    [
        'walmart/*',
    ],
    '/Volumes/CaseSensitive/projects/walmart/test/project/artifacts',
    \Vbuck\MagentoModuleBundler\Bundler::BEHAVIOR_INDIVIDUAL_BUNDLES,
    \Vbuck\MagentoModuleBundler\Bundler::OUTPUT_TYPE_COMPOSER
);

var_dump(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
