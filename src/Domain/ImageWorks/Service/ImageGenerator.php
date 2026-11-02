<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Support\Config;

/**
 * The one render path behind every ImageWorks URL, the warmer, the favicon
 * and Twig's data URIs.
 *
 * Finding the source image is ImageSourceResolver's job, shaping the params
 * is ImageRenderParams', the HTTP headers are ImageResponder's, and the
 * two-pass render for both watermarks is DoubleWatermarkRenderer's. This
 * class builds the Glide render and its watermarks, and holds cache misses
 * to the render slots on the public route.
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
		private readonly DoubleWatermarkRenderer $doubleMarks,
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

		return $this->isCached($this->glideRender($location, $imageData, $params), $location, $imageData)
			? CacheState::Cached
			: CacheState::Missing;
	}

	/**
	 * The watermark settings one property resolves to: schema, then
	 * collection, then the object's own override. [] when it has none.
	 *
	 * @return array<string,string|int>
	 */
	public function watermarkSettings(string $collection, string $property, string $id = ''): array
	{
		return $this->renderParams->watermarkSettings($collection, $property, $id);
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

			$make = fn (): ResponseInterface => $render['marks'] !== null
				? $this->doubleMarks->render($render['glide'], $location, $imageData, $render['marks'][0], $render['marks'][1], $render['params'])
				: $render['glide']->getImageResponse($imageData->name, $render['params']);

			// A cached file costs no render, so it never waits for a slot.
			return $this->limitRenders && !$this->isCached($render, $location, $imageData)
				? ($this->renderSlots ??= RenderSlots::fromConfig($this->config))->run($make)
				: $make();
		});
	}

	/**
	 * The one step that turns the cleaned params into what Glide renders:
	 * builds the watermarks, the Glide server and the final Glide params.
	 * The route and cacheState() both go through here, so a cache check keys
	 * exactly the file the route makes. When both an image and a text
	 * watermark apply, `marks` holds them and `params` are the pre-watermark
	 * params for the two-pass render; otherwise `marks` is null and `params`
	 * are final.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array{glide: Server, params: array<string,mixed>, marks: ?array{0: Watermark, 1: Watermark}}
	 */
	private function glideRender(ImageLocation $location, ImageData $imageData, array $params): array
	{
		$imageMark = $this->watermarkFactory->createImageWatermark($params);
		// Use requested width if specified, otherwise use original image width
		$effectiveWidth = isset($params['w']) ? (int)$params['w'] : $imageData->width;
		$textMark       = $this->watermarkFactory->createTextWatermark($params, $effectiveWidth);

		$hasImageMark = $imageMark instanceof Watermark && !$imageMark->isEmpty();
		$hasTextMark  = $textMark  instanceof Watermark && !$textMark->isEmpty();

		$glide = $this->glideFactory->create(
			source        : $location->path(),
			imageData     : $imageData,
			watermarkPath : $hasImageMark ? $imageMark->path : TextWatermarkFactory::WATERMARK_DIR,
		);

		$glideParams = ImageRenderParams::withoutWatermarks($params);

		if ($hasImageMark && $hasTextMark) {
			return ['glide' => $glide, 'params' => $glideParams, 'marks' => [$imageMark, $textMark]];
		}

		if ($hasTextMark) {
			$glideParams = array_merge($glideParams, $textMark->toArray());
		} elseif ($hasImageMark) {
			$glideParams = array_merge($glideParams, $imageMark->toArray());
		}

		return ['glide' => $glide, 'params' => $glideParams, 'marks' => null];
	}

	/**
	 * Whether the file a render would serve is already in the cache. With both
	 * watermarks that file is the text pass's output.
	 *
	 * @param array{glide: Server, params: array<string,mixed>, marks: ?array{0: Watermark, 1: Watermark}} $render
	 */
	private function isCached(array $render, ImageLocation $location, ImageData $imageData): bool
	{
		if ($render['marks'] === null) {
			return $render['glide']->cacheFileExists($imageData->name, $render['params']);
		}

		return $this->doubleMarks->isCached($render['glide'], $location, $imageData, $render['marks'][0], $render['marks'][1], $render['params']);
	}
}
