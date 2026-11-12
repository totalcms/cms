<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Support\Config;

/**
 * The one render path behind every ImageWorks URL, the warmer, the favicon
 * and Twig's data URIs.
 *
 * Finding the source image is ImageSourceResolver's job, shaping the params
 * is ImageRenderParams', the HTTP headers are ImageResponder's, and drawing
 * the watermark layers is MarkLayersManipulator's. This class builds the
 * Glide render and its layers, and holds cache misses to the render slots on
 * the public route.
 */
class ImageGenerator
{
	/** Set by forPublicRoute(): rounds sizes to the ladder. */
	private ?SizeLadder $sizeLadder = null;
	/** Set by forPublicRoute(): cache misses wait for a render slot. */
	private bool $limitRenders = false;

	public function __construct(
		private readonly ImageSourceResolver $sources,
		private readonly ImageRenderParams $renderParams,
		private readonly ImageResponder $responder,
		private readonly GlideFactory $glideFactory,
		private readonly WatermarkFactory $watermarkFactory,
		private readonly Config $config,
		private ?RenderSlots $renderSlots = null,
	) {
	}

	/**
	 * The generator as the public `/imageworks/` routes use it: sizes round
	 * up to the SizeLadder, and, with $limitRenders, a cache miss waits for
	 * one of the RenderSlots. In-process callers (Twig, the favicon) keep the
	 * exact sizes they ask for. The warmer takes the ladder without the slot
	 * limit, so it warms the very files the route will look for.
	 */
	public function forPublicRoute(bool $limitRenders = true): self
	{
		$route               = clone $this;
		$route->sizeLadder   = SizeLadder::fromConfig($this->config);
		$route->limitRenders = $limitRenders;

		return $route;
	}

	/** @param array<string,mixed> $params */
	public function generateImage(
		string $collection,
		string $id,
		string $property,
		array $params,
		?ServerRequestInterface $request = null,
	): ResponseInterface {
		$imageData = $this->sources->image($collection, $id, $property);

		return $this->respond(new ImageLocation($collection, $id, $property), $imageData, $params, $request);
	}

	/** @param array<string,mixed> $params */
	public function generateGalleryImage(
		string $collection,
		string $id,
		string $property,
		string $path,
		array $params,
		?ServerRequestInterface $request = null,
	): ResponseInterface {
		[$imageData, $subpath] = $this->sources->pathImage($collection, $id, $property, $path);

		return $this->respond(new ImageLocation($collection, $id, $property, $subpath), $imageData, $params, $request);
	}

	/**
	 * An image by file name, for uploads that have no image property record
	 * to read sizes or colors from.
	 *
	 * @param array<string,mixed> $params
	 */
	public function generateUploadImage(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $params,
		?ServerRequestInterface $request = null,
		?string $subpath = null,
	): ResponseInterface {
		$imageData       = new ImageData();
		$imageData->name = $name;

		return $this->respond(new ImageLocation($collection, $id, $property, $subpath), $imageData, $params, $request);
	}

	/**
	 * Whether the file the public route would serve for these params is
	 * already cached. `$path` is null for an image property, or what the
	 * route passes as `{path}`: a gallery image's name without its extension,
	 * or a nested image's path (`photo`, `item-3/photo`, `poster`). `$params`
	 * are shaped as the route shapes them (`fm` included).
	 *
	 * @param array<string,mixed> $params
	 */
	public function cacheState(string $collection, string $id, string $property, ?string $path, array $params): CacheState
	{
		[$imageData, $subpath] = $path === null
			? [$this->sources->image($collection, $id, $property), null]
			: $this->sources->pathImage($collection, $id, $property, $path);

		$location = new ImageLocation($collection, $id, $property, $subpath);
		$params   = $this->renderParams->clean($params, $imageData, $location, $this->sizeLadder);

		if ($params === []) {
			return CacheState::Original;
		}

		return $this->isCached($this->glideRender($location, $imageData, $params), $imageData)
			? CacheState::Cached
			: CacheState::Missing;
	}

	/** @param array<string,mixed> $params */
	private function respond(ImageLocation $location, ImageData $imageData, array $params, ?ServerRequestInterface $request): ResponseInterface
	{
		$params    = $this->renderParams->clean($params, $imageData, $location, $this->sizeLadder);
		$imagePath = $location->path($imageData->name);

		if ($params === []) {
			return $this->responder->original($imagePath, $request);
		}

		return $this->responder->processed($imagePath, $request, $params, function () use ($location, $imageData, $params): ResponseInterface {
			$render = $this->glideRender($location, $imageData, $params);

			$make = fn (): ResponseInterface => $render['glide']->getImageResponse($imageData->name, $render['params']);

			// A cached file costs no render, so it never waits for a slot.
			return $this->limitRenders && !$this->isCached($render, $imageData)
				? ($this->renderSlots ??= RenderSlots::fromConfig($this->config))->run($make)
				: $make();
		});
	}

	/**
	 * The one step that turns the cleaned params into what Glide renders:
	 * the mark layers (the URL's, then the property's watermark on top),
	 * the Glide server, and the final Glide params. The route and
	 * cacheState() both go through here, so a cache check keys exactly the
	 * file the route makes. The layers carry their files' versions, so a
	 * replaced logo or profile photo is a new cache key.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array{glide: Server, params: array<string,mixed>}
	 */
	private function glideRender(ImageLocation $location, ImageData $imageData, array $params): array
	{
		$settings = $params['marksettings'] ?? [];
		$marks    = $this->watermarkFactory->prepare(MarkLayers::withSettings($params, is_array($settings) ? $settings : []));

		$glide = $this->glideFactory->create(
			source    : $location->path(),
			imageData : $imageData,
			marks     : $marks === [] ? null : $this->watermarkFactory->manipulator(),
		);

		$glideParams = ImageRenderParams::withoutWatermarks($params);
		if ($marks !== []) {
			$glideParams['marks'] = $marks;
		}

		return ['glide' => $glide, 'params' => $glideParams];
	}

	/**
	 * Whether the file a render would serve is already in the cache.
	 *
	 * @param array{glide: Server, params: array<string,mixed>} $render
	 */
	private function isCached(array $render, ImageData $imageData): bool
	{
		return $render['glide']->cacheFileExists($imageData->name, $render['params']);
	}
}
