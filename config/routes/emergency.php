<?php

use Slim\App;
use TotalCMS\Action\Emergency\EmergencyCacheClearAction;

return function (App $app): void {
	// Emergency cache clear endpoint - bypasses normal authentication
	// Only accessible from localhost with emergency key for security
	$app->get('/emergency/cache/clear', EmergencyCacheClearAction::class)->setName('emergency-cache-clear');
};
