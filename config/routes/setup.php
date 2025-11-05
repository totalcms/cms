<?php

use Slim\App;
use TotalCMS\Action\Setup;

return function (App $app): void {
	// Data path setup (runs before authentication)
	$app->get('/setup/data-path', Setup\DataPathSetupAction::class)->setName('setup-data-path');
	$app->post('/setup/data-path', Setup\DataPathSetupSubmitAction::class)->setName('setup-data-path-submit');
};
