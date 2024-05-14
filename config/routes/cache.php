<?php

use Slim\App;
use TotalCMS\Action\Cache\CacheDeleteAction;

return function (App $app) {
    $app->delete('/cache', CacheDeleteAction::class)->setName('cache-delete');
};
