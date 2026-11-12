<?php

declare(strict_types=1);

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Seo\Service\SocialImageBuilder;
use TotalCMS\Domain\Seo\Service\SocialImageMismatchLog;

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
		private SocialImageMismatchLog $mismatches,
		private EditionFeatureService $editions,
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

		// The record exists but drew no overlay for this hash: a stale or
		// mis-signed share URL. Leave a trace (Share Cards shows the cause).
		// Below Pro no card has an overlay, so there is nothing to trace.
		if ($hash !== '' && !$card->hasOverlay() && $this->editions->can(EditionFeature::IMAGE_LAYERS)) {
			$this->mismatches->note($args['collection'], $args['id'], $hash);
		}

		$params = ['fm' => $format] + $card->params;

		// An image inside a card, such as a record's own SEO image (`seo.image`),
		// is found by property and path, as the `/{prop}/{path}` image route does.
		$parts    = explode('.', $card->imageProperty, 2);
		$property = $parts[0];
		$path     = $card->gallery ? 'first' : ($parts[1] ?? null);

		return $this->respond($request, $response, fn (): ResponseInterface => $path !== null
			? $this->imageGenerator->forPublicRoute()->generateGalleryImage($card->imageCollection, $card->imageId, $property, $path, $params, $request)
			: $this->imageGenerator->forPublicRoute()->generateImage($card->imageCollection, $card->imageId, $property, $params, $request));
	}
}
