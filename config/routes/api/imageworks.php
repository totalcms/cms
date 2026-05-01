<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\ImageWorks;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/imageworks', function (RouteCollectorProxy $group): void {
		// Upload-fetch route MUST register before the greedy gallery route below —
		// the literal `/upload/` segment would otherwise be misread as a collection
		// name by `/{collection}/{id}/{property}/{path:.+}.{format}`.
		$group->get('/upload/{collection}/{id}/{property}/{path:.+}', ImageWorks\ImageWorksUploadFetchAction::class)->setName('uploads-image-fetch');

		$group->get('/{id}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch-short');
		$group->get('/{collection}/{id}/{property}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch');
		$group->get('/{id}/{name}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch-short');
		$group->get('/{collection}/{id}/{property}/{action:first|last|random|featured}', ImageWorks\ImageWorksGalleryFetchDynamicAction::class)->setName('gallery-image-fetch-dynamic');
		// `{path:.+}.{format}` is greedy so it matches both gallery image URLs
		// (single segment: `/prop/name.jpg`) and deck-nested image URLs
		// (multi-segment: `/prop/itemId/childKey.jpg`). The action dispatches on
		// the resolved property's data shape (CardData / DeckData / GalleryData).
		$group->get('/{collection}/{id}/{property}/{path:.+}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch');
	});
};
