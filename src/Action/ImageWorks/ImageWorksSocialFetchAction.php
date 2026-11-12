<?php

declare(strict_types=1);

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Seo\Service\SocialImageBuilder;

/**
 * A record's share image: `/imageworks/social/{collection}/{id}.jpg?v=…`,
 * the URL `cms.seo.head()` prints when an overlay applies. The card is
 * rebuilt from the record and drawn only when `v` is the hash the site
 * printed; any other `v` gets the plain 1200×630 image (see SocialImageBuilder).
 *
 * The route has the shape of `/imageworks/{collection}/{id}/{property}.jpg`
 * and registers first, so a site with a collection named `social` would
 * lose its image URLs. A request that is not a share URL (no `v`, or no
 * such record) goes on to the image route for that collection.
 */
readonly class ImageWorksSocialFetchAction
{
	use ImageWorksResponder;

	public function __construct(
		private SocialImageBuilder $socialImages,
		private ImageGenerator $imageGenerator,
		private ImageWorksImageFetchAction $imageFetch,
		private CollectionFetcher $collections,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$format = strtolower($args['format'] ?? '');
		if (!in_array($format, GlideFactory::IMG_TYPES, true)) {
			throw new HttpNotFoundException($request);
		}

		$hash = $request->getQueryParams()['v'] ?? null;
		$card = is_string($hash) ? $this->socialImages->forRecord($args['collection'], $args['id'], $hash) : null;
		if ($card === null) {
			if ($this->collections->collectionExists('social')) {
				return ($this->imageFetch)($request, $response, ['collection' => 'social', 'id' => $args['collection'], 'property' => $args['id'], 'format' => $args['format'] ?? '']);
			}

			throw new HttpNotFoundException($request);
		}

		$params = ['fm' => $format] + $card->params;

		return $this->respond($request, $response, fn (): ResponseInterface => $card->gallery
			? $this->imageGenerator->forPublicRoute()->generateGalleryImage($card->imageCollection, $card->imageId, $card->imageProperty, 'first', $params, $request)
			: $this->imageGenerator->forPublicRoute()->generateImage($card->imageCollection, $card->imageId, $card->imageProperty, $params, $request));
	}
}
