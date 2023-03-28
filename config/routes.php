<?php

use App\Action\PreflightAction;
use Slim\App;

return function (App $app) {
    $app->options('/', PreflightAction::class);

    (require __DIR__ . '/routes/collections.php')($app);
    (require __DIR__ . '/routes/docs.php')($app);
    (require __DIR__ . '/routes/download.php')($app);
    (require __DIR__ . '/routes/froala.php')($app);
    (require __DIR__ . '/routes/imageworks.php')($app);
    (require __DIR__ . '/routes/import.php')($app);
    (require __DIR__ . '/routes/schemas.php')($app);
    (require __DIR__ . '/routes/templates.php')($app);
};
