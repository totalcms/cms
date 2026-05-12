<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Stream;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/stream', function (RouteCollectorProxy $group): void {
		// Stream an uploaded file (styled text uploads with optional auth)
		$group->get('/upload/{collection}/{id}/{property}/{path:.+}', Stream\StreamUploadAction::class)->setName('stream-upload');

		// Stream a file
		$group->get('/{collection}/{id}/{property}', Stream\StreamFileAction::class)->setName('stream-file');

		// Depot file OR nested file (card child / deck-item child). The greedy
		// `{path:.+}` segment captures both shapes — see the matching comment
		// in download.php for the dispatch rationale.
		$group->get('/{collection}/{id}/{property}/{path:.+}', Stream\StreamFileFromDepotAction::class)->setName('stream-file-depot');
	});
};
