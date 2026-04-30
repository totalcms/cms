<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Download;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/download', function (RouteCollectorProxy $group): void {
		// Download an uploaded file (styled text uploads with optional auth)
		$group->get('/upload/{collection}/{id}/{property}/{name}', Download\DownloadUploadAction::class)->setName('download-upload');

		// Download a file
		$group->get('/{collection}/{id}/{property}', Download\DownloadFileAction::class)->setName('download-file');
		$group->post('/{collection}/{id}/{property}', Download\DownloadFileAction::class)->setName('download-file-password');

		// Download a file from the depot
		$group->get('/{collection}/{id}/{property}/{name}', Download\DownloadFileFromDepotAction::class)->setName('download-file-depot');
		$group->post('/{collection}/{id}/{property}/{name}', Download\DownloadFileFromDepotAction::class)->setName('download-file-depot-password');
	});
};
