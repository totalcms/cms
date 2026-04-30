<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\OpenApi\DocRedirectAction;
use TotalCMS\Action\OpenApi\DocVersion3Action;

return function (RouteCollectorProxyInterface $app): void {
	// Swagger API documentation
	$app->get('/docs/api', DocRedirectAction::class); // redirect
	$app->get('/docs/api/v3', DocVersion3Action::class)->setName('api-docs');
};
