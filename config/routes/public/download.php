<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Download;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/download', function (RouteCollectorProxy $group): void {
		// Download an uploaded file (styled text uploads with optional auth)
		$group->get('/upload/{collection}/{id}/{property}/{path:.+}', Download\DownloadUploadAction::class)->setName('download-upload');

		// Download a file
		$group->get('/{collection}/{id}/{property}', Download\DownloadFileAction::class)->setName('download-file');
		$group->post('/{collection}/{id}/{property}', Download\DownloadFileAction::class)->setName('download-file-password');

		// Depot file OR nested file (card child / deck-item child). The greedy
		// `{path:.+}` segment captures both shapes: depot uses the legacy
		// single-filename form, nested uses dotted-property segments — for
		// example `/download/coll/id/mycard/file` (card) or
		// `/download/coll/id/mydeck/one/file` (deck). The action dispatches on
		// filesystem state.
		$group->get('/{collection}/{id}/{property}/{path:.+}', Download\DownloadFileFromDepotAction::class)->setName('download-file-depot');
		$group->post('/{collection}/{id}/{property}/{path:.+}', Download\DownloadFileFromDepotAction::class)->setName('download-file-depot-password');
	});
};
