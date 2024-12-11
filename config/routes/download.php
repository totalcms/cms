<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Download;

return function (App $app) {
	$app->group('/download', function (RouteCollectorProxy $group) {
		// Download a file
		$group->get('/{collection}/{id}/{property}', Download\DownloadFileAction::class)->setName('download-file');
		$group->post('/{collection}/{id}/{property}', Download\DownloadFileAction::class)->setName('download-file-password');

		// Download a file from the depot
		$group->get('/{collection}/{id}/{property}/{name}', Download\DownloadFileFromDepotAction::class)->setName('download-file-depot');
		$group->post('/{collection}/{id}/{property}/{name}', Download\DownloadFileFromDepotAction::class)->setName('download-file-depot-password');
	});
};
