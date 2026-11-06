<?php

declare(strict_types=1);

/**
 * Render one template in a process of its own, the way a production request
 * does: debug off, Development Mode off, the compiled cache on disk. A test
 * can't do this in-process, because Twig reuses a compiled class already
 * loaded and so never reaches the cache a second time.
 *
 * Usage: php render-once.php <template dir> <cache dir> <template>
 */

use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;
use TotalCMS\Support\ContainerFactory;

require __DIR__ . '/../../bootstrap.php';

[, $templates, $cache, $template] = $argv;

$container        = ContainerFactory::build();
$config           = $container->get(Config::class);
$config->debug    = false;
$config->cachedir = $cache;

$twig = $container->get(TwigEngine::class);
$twig->addExtensionTemplatePath($templates, 'reload');

echo $twig->render('@reload/' . $template);
