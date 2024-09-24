<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\ImageWorks;
use TotalCMS\Middleware\RobotsRemoveTagMiddleware;

return function (App $app) {
	$app->group('/imageworks', function (RouteCollectorProxy $group) {
		$group->get('/{id}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch-short');
		$group->get('/{collection}/{id}/{property}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch');
		$group->get('/{id}/{filename}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch-short');
		$group->get('/{collection}/{id}/{property}/{action:first|last|random|featured}', ImageWorks\ImageWorksGalleryFetchDynamicAction::class)->setName('gallery-image-fetch-dynamic');
		$group->get('/{collection}/{id}/{property}/{filename}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch');
	});
};
