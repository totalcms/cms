<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Action\OpenApi\DocRedirectAction;
use TotalCMS\Action\OpenApi\DocVersion3Action;

return function (App $app): void {
	// Swagger API documentation
	$app->get('/docs/api', DocRedirectAction::class); // redirect
	$app->get('/docs/api/v3', DocVersion3Action::class)->setName('api-docs');
};
