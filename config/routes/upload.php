<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Froala;

return function (App $app) {
	$app->group('/upload', function (RouteCollectorProxy $group) {
		$group->post('/{collection}/{id}/{property}', Froala\FroalaUploadFileAction::class)->setName('upload-post');
		$group->get('/{collection}/{id}/{property}/{name}', Froala\FroalaGetFileAction::class)->setName('upload-get');
		$group->delete('/{collection}/{id}/{property}/{name}', Froala\FroalaDeleteFileAction::class)->setName('upload-delete');
	});
};
