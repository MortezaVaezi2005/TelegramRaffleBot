<?php

$config = new class extends Amp\CodeStyle\Config {
    public function getRules(): array
    {
        return array_merge(
            parent::getRules(),
            [
                'phpdoc_to_property_type' => true,
                'phpdoc_to_param_type' => true,
            ]
        );
    }  
};
$config->getFinder()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

$cacheDir = getenv('TRAVIS') ? getenv('HOME') . '/.php-cs-fixer' : __DIR__;

$config->setCacheFile($cacheDir . '/.php_cs.cache');

return $config;
