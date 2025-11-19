<?php

use Slim\App;
use TotalCMS\Action\Emergency\EmergencyCacheClearAction;
use TotalCMS\Action\Emergency\EmergencyLicenseCacheClearAction;

return function (App $app): void {
	// Emergency cache clear endpoint - bypasses normal authentication
	// Only accessible from localhost with emergency key for security
	$app->get('/emergency/cache/clear', EmergencyCacheClearAction::class)->setName('emergency-cache-clear');

	// Emergency license cache clear endpoint - for debugging license issues
	// Clears only license cache, then visit /admin/utils/license-manager to refresh
	$app->get('/emergency/cache/clear-license', EmergencyLicenseCacheClearAction::class)->setName('emergency-license-cache-clear');
};
