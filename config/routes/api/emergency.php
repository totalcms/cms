<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Emergency\EmergencyCacheClearAction;
use TotalCMS\Action\Emergency\EmergencyLicenseCacheClearAction;
use TotalCMS\Middleware\Response\NoCacheMiddleware;
use TotalCMS\Middleware\Security\EmergencyRateLimitMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// Emergency endpoints intentionally bypass authentication — they exist for
	// the self-service case where the admin UI itself can't load. To prevent
	// abuse (sustained cache-flushing as a low-grade DoS), EmergencyRateLimit-
	// Middleware caps each IP at 1 call per 15 minutes. A legitimately stuck
	// operator can still recover; an attacker just spins their wheels.
	$app->group('/emergency', function (RouteCollectorProxy $group): void {
		$group->get('/cache/clear', EmergencyCacheClearAction::class)->setName('emergency-cache-clear');
		$group->get('/cache/clear-license', EmergencyLicenseCacheClearAction::class)->setName('emergency-license-cache-clear');
	})
		->add(EmergencyRateLimitMiddleware::class)
		->add(NoCacheMiddleware::class);
};
