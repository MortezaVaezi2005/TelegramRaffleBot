<?php

$config = new class extends Amp\CodeStyle\Config {
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'void_return' => true,
        ]);
    }
};

$config->getFinder()
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/test')
    ->in(__DIR__ . '/examples');

$cacheDir = getenv('TRAVIS') ? getenv('HOME') . '/.php-cs-fixer' : __DIR__;

$config->setCacheFile($cacheDir . '/.php_cs.cache');

return $config;
