<?php

use Slim\App;
use TotalCMS\Action\PreflightAction;

return function (App $app) {
	$app->options('/', PreflightAction::class);

	(require __DIR__ . '/routes/admin.php')($app);
	(require __DIR__ . '/routes/assets.php')($app);
	(require __DIR__ . '/routes/auth.php')($app);
	(require __DIR__ . '/routes/cache.php')($app);
	(require __DIR__ . '/routes/collections.php')($app);
	(require __DIR__ . '/routes/docs.php')($app);
	(require __DIR__ . '/routes/download.php')($app);
	(require __DIR__ . '/routes/imageworks.php')($app);
	(require __DIR__ . '/routes/import.php')($app);
	(require __DIR__ . '/routes/schemas.php')($app);
	(require __DIR__ . '/routes/templates.php')($app);
	(require __DIR__ . '/routes/upload.php')($app);
};
