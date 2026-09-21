<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// cc-gpt 壳(design/21 §8):examples 内的 namespace,composer PSR-4 覆盖不到。
// 取 composer 正在用的 loader(composer 2.x:getRegisteredLoaders;2.2.15+ 才有单数便捷方法)。
// 映射级:文件按 namespace 短路径存放(namespace CcGpt;→ src/CcGpt/),前缀直接对 src/CcGpt
$registeredLoaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
$ccGptLoader = reset($registeredLoaders);
$ccGptLoader->addPsr4('CcGpt\\', __DIR__ . '/../examples/cc-gpt/src/CcGpt');

date_default_timezone_set('PRC');
