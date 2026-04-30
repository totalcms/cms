<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Upload;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/upload', function (RouteCollectorProxy $group): void {
		$group->post('/{collection}/{id}/{property}', Upload\UploadFileAction::class)->setName('upload-post');
		$group->get('/{collection}/{id}/{property}', Upload\ListUploadFilesAction::class)->setName('upload-list');
		$group->get('/{collection}/{id}/{property}/{name}', Upload\GetFileAction::class)->setName('upload-get');
		$group->delete('/{collection}/{id}/{property}/{name}', Upload\DeleteFileAction::class)->setName('upload-delete');
	});
};
