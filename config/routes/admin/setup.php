<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Setup;
use TotalCMS\Middleware\Response\NoCacheMiddleware;
use TotalCMS\Middleware\SetupLocaleMiddleware;

return function (App $app): void {
	$app->group('/setup', function (RouteCollectorProxy $group): void {
		// Welcome screen with language selection
		$group->get('', Setup\WelcomeAction::class)->setName('setup-welcome');

		// Step 1: Environment check
		$group->get('/environment', Setup\EnvironmentCheckAction::class)->setName('setup-environment');

		// Step 2: Data path selection
		$group->get('/data-path', Setup\DataPathSetupAction::class)->setName('setup-data-path');
		$group->post('/data-path', Setup\DataPathSetupSubmitAction::class)->setName('setup-data-path-submit');

		// Step 3: Admin account creation
		$group->get('/account', Setup\AccountSetupAction::class)->setName('setup-account');
		$group->post('/account', Setup\AccountSetupSubmitAction::class)->setName('setup-account-submit');

		// Step 4: License
		$group->get('/license', Setup\LicenseSetupAction::class)->setName('setup-license');

		// Step 5: Server configuration hints (rewrite rules + cron command)
		$group->get('/server-config', Setup\ServerConfigAction::class)->setName('setup-server-config');

		// Step 6: Complete
		$group->get('/complete', Setup\SetupCompleteAction::class)->setName('setup-complete');
	})->add(SetupLocaleMiddleware::class)->add(NoCacheMiddleware::class);
};
