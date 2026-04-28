<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Stream;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/stream', function (RouteCollectorProxy $group): void {
		// Stream an uploaded file (styled text uploads with optional auth)
		$group->get('/upload/{collection}/{id}/{property}/{name}', Stream\StreamUploadAction::class)->setName('stream-upload');

		// Stream a file
		$group->get('/{collection}/{id}/{property}', Stream\StreamFileAction::class)->setName('stream-file');

		// Stream a file from the depot
		$group->get('/{collection}/{id}/{property}/{name}', Stream\StreamFileFromDepotAction::class)->setName('stream-file-depot');
	});
};
