<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\Builder\BuilderEventsAction;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/livereload', function (RouteCollectorProxy $group): void {
		// SSE stream that wakes connected pages when a Builder template or page
		// record is saved. Public route — the action gates on Dev Mode, so the
		// stream only runs when the operator has explicitly enabled it.
		$group->get('/events', BuilderEventsAction::class)->setName('livereload-events');
	});
};
