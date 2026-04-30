<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\ImageWorks;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/imageworks', function (RouteCollectorProxy $group): void {
		$group->get('/{id}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch-short');
		$group->get('/{collection}/{id}/{property}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch');
		$group->get('/{id}/{name}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch-short');
		$group->get('/{collection}/{id}/{property}/{action:first|last|random|featured}', ImageWorks\ImageWorksGalleryFetchDynamicAction::class)->setName('gallery-image-fetch-dynamic');
		$group->get('/{collection}/{id}/{property}/{name}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch');
		$group->get('/upload/{collection}/{id}/{property}/{name}', ImageWorks\ImageWorksUploadFetchAction::class)->setName('uploads-image-fetch');
	});
};
