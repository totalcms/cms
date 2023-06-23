<?php

use Slim\App;
use TotalCMS\Action\Admin\AdminIndexAction;

return function (App $app) {
    // Display Admin Interface
    $app->get('/admin', AdminIndexAction::class)->setName('admin-index');
};
