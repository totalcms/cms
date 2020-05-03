<?php

use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Slim\App;
use App\Action\PreflightAction;

return function (App $app) {
    $app->get('/', \App\Action\HomeAction::class);
    $app->options('/', PreflightAction::class);

    $app->post('/users', \App\Action\UserCreateAction::class);
};
