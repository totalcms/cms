<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Stream;

return function (App $app): void {
	$app->group('/stream', function (RouteCollectorProxy $group): void {
		// Stream a file
		$group->get('/{collection}/{id}/{property}', Stream\StreamFileAction::class)->setName('stream-file');

		// Stream a file from the depot
		$group->get('/{collection}/{id}/{property}/{name}', Stream\StreamFileFromDepotAction::class)->setName('stream-file-depot');
	});
};
