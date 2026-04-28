<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Assets\StaticPublicAssetsAction;

return function (RouteCollectorProxyInterface $app): void {
	$app->get('/assets/{asset:.*}', StaticPublicAssetsAction::class)->setName('pubic-asset');
};
