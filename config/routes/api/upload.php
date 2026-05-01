<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Upload;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/upload', function (RouteCollectorProxy $group): void {
		$group->post('/{collection}/{id}/{property}', Upload\UploadFileAction::class)->setName('upload-post');
		$group->post('/{collection}/{id}/{property}/{path:.+}', Upload\UploadFileAction::class)->setName('upload-post-nested');
		$group->get('/{collection}/{id}/{property}', Upload\ListUploadFilesAction::class)->setName('upload-list');
		$group->get('/{collection}/{id}/{property}/{path:.+}', Upload\GetFileAction::class)->setName('upload-get');
		$group->delete('/{collection}/{id}/{property}/{path:.+}', Upload\DeleteFileAction::class)->setName('upload-delete');
	});
};
