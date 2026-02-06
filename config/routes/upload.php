<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Upload;

return function (App $app): void {
	$app->group('/upload', function (RouteCollectorProxy $group): void {
		$group->post('/{collection}/{id}/{property}', Upload\UploadFileAction::class)->setName('upload-post');
		$group->get('/{collection}/{id}/{property}/{name}', Upload\GetFileAction::class)->setName('upload-get');
		$group->delete('/{collection}/{id}/{property}/{name}', Upload\DeleteFileAction::class)->setName('upload-delete');
	});
};
