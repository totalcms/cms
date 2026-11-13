<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Seo\Data\OverlayPreview;
use TotalCMS\Domain\Seo\Data\SocialCard;
use TotalCMS\Domain\Seo\Data\SocialCardReason;

/**
 * The overlay designer's preview: a draft overlay drawn on a record the user
 * picked (OverlayPreviewOptions lists the records to pick from).
 *
 * A preview goes through SocialImageBuilder::preview(), so it is the card
 * the share route would draw with that overlay stored, refusals included.
 * Nothing is saved; each draft is one more ImageWorks cache file.
 */
readonly class OverlayPreviewer
{
	/** Draw sizes: the dialog's preview, a preset's thumbnail, the form row's thumbnail. */
	public const SIZES = [
		'full'  => ['w' => 1200, 'h' => 630],
		'thumb' => ['w' => 600, 'h' => 315],
		'row'   => ['w' => 240, 'h' => 126],
	];

	public function __construct(
		private SeoContextFactory $contexts,
		private MetaBuilder $metaBuilder,
		private SocialImageBuilder $socialImages,
		private ObjectFetcher $objects,
		private ImageGenerator $imageGenerator,
	) {
	}

	/**
	 * The card a draft overlay gives a record, drawn. With no overlay to draw
	 * (any reason but Drawn) the image is the plain one a share would show.
	 *
	 * @param array<array-key,mixed> $overlay
	 */
	public function preview(string $collection, string $id, array $overlay, string $size = 'full'): OverlayPreview
	{
		try {
			$subject = $this->objects->fetchObject($collection, $id)->toArray();
		} catch (\Throwable) {
			return new OverlayPreview(SocialCardReason::NoRecord);
		}

		$ctx     = $this->contexts->make($subject, ['collection' => $collection]);
		$outcome = $this->socialImages->preview($ctx, $this->metaBuilder->build($ctx), $overlay);
		$card    = $outcome->card;
		if (!$card instanceof SocialCard) {
			return new OverlayPreview($outcome->reason, $outcome->detail, $outcome->layer, $ctx->fields->imageAsIs);
		}

		$params = ['fm' => 'jpg'] + (self::SIZES[$size] ?? self::SIZES['full']) + $card->params;

		return new OverlayPreview($outcome->reason, $outcome->detail, $outcome->layer, $ctx->fields->imageAsIs, $this->draw($card, $params));
	}

	/** @param array<string,mixed> $params */
	private function draw(SocialCard $card, array $params): ?string
	{
		[$property, $path] = $card->imageTarget();

		try {
			$response = $path !== null
				? $this->imageGenerator->generateGalleryImage($card->imageCollection, $card->imageId, $property, $path, $params)
				: $this->imageGenerator->generateImage($card->imageCollection, $card->imageId, $property, $params);
		} catch (\Throwable) {
			return null;
		}

		$body = (string)$response->getBody();
		$type = $response->getHeaderLine('Content-Type');

		return $body === '' ? null : 'data:' . ($type !== '' ? $type : 'image/jpeg') . ';base64,' . base64_encode($body);
	}
}
