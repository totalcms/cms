<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Collection\Utilities\PaginationGenerator;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\ImageWorks\Service\ImageDimensionCalculator;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Service\DepotBrowserRenderer;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

/**
 * Twig sub-adapter for frontend rendering helpers.
 *
 * Accessed in Twig as `cms.render.*`.
 * Provides methods that generate HTML output for frontend use.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class RenderTwigAdapter
{
	private ?TwigEnvironment $captionTwig = null;
	private ?DataViewQueryService $resolvedDataViewQueryService = null;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly HtmxRenderer $htmxRenderer,
		private readonly Config $config,
		private readonly DataTwigAdapter $data,
		private readonly MediaTwigAdapter $media,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionLister $collectionLister,
		private readonly SchemaFetcher $schemaFetcher,
		public readonly GridRenderer $grid,
		LoggerFactory $loggerFactory,
		private readonly DepotBrowserRenderer $depotBrowserRenderer = new DepotBrowserRenderer(),
		private readonly ?IndexQueryService $indexQueryService = null,
		/** @var (\Closure(): DataViewQueryService)|null */
		private readonly ?\Closure $dataViewQueryServiceFactory = null,
		/** @var (\Closure(): TwigEngine)|null */
		private readonly ?\Closure $twigEngineFactory = null,
	) {
		$this->logger = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');
	}

	private function getDataViewQueryService(): ?DataViewQueryService
	{
		if ($this->resolvedDataViewQueryService === null && $this->dataViewQueryServiceFactory !== null) {
			$this->resolvedDataViewQueryService = ($this->dataViewQueryServiceFactory)();
		}

		return $this->resolvedDataViewQueryService;
	}

	/**
	 * Generate an HTMX trigger element for paginated content loading.
	 *
	 * The initial page is rendered server-side by the user's template.
	 * This outputs the HTMX element that triggers loading page 2+.
	 *
	 * Usage in Twig:
	 * ```twig
	 * {{ cms.render.loadMore('blog', {
	 *     template: 'blog/card',
	 *     limit: 10,
	 *     sort: 'date:desc',
	 *     include: 'published:true',
	 *     trigger: 'revealed'
	 * }) }}
	 * ```
	 *
	 * @param string              $collection Collection identifier
	 * @param array<string,mixed> $options    Options: template (required), limit, sort, include, exclude, search, trigger, buttonLabel, buttonClass
	 */
	public function loadMore(string $collection, array $options = []): string
	{
		$template = (string)($options['template'] ?? '');
		if ($template === '') {
			return '<!-- cms.render.loadMore: "template" option is required -->';
		}

		$limit = max(1, (int)($options['limit'] ?? 20));
		$load  = !empty($options['load']);

		$empty = (string)($options['empty'] ?? '');
		if ($empty !== '' && $this->indexQueryService !== null) {
			$params = $this->buildCountParams($options);
			$result = $this->indexQueryService->query($collection, $params);
			if ($result->total === 0) {
				return $this->buildEmptyHtml($empty);
			}
		}

		if ($load && $this->twigEngineFactory !== null && $this->indexQueryService !== null) {
			return $this->loadItems($collection, $template, $limit, $options);
		}

		$baseUrl = $this->config->api . '/collections/' . $collection . '/query';

		return $this->buildTrigger($baseUrl, $options);
	}

	/**
	 * Generate an HTMX trigger element for paginated DataView loading.
	 *
	 * Usage in Twig:
	 * ```twig
	 * {{ cms.render.loadMoreDataView('my-view', {
	 *     template: 'cards/item',
	 *     limit: 6,
	 *     sort: 'date:desc',
	 *     trigger: 'revealed'
	 * }) }}
	 * ```
	 *
	 * @param string              $viewId  DataView identifier
	 * @param array<string,mixed> $options Options: template (required), limit, sort, include, exclude, search, trigger, buttonLabel, buttonClass
	 */
	public function loadMoreDataView(string $viewId, array $options = []): string
	{
		$template = (string)($options['template'] ?? '');
		if ($template === '') {
			return '<!-- cms.render.loadMoreDataView: "template" option is required -->';
		}

		$limit = max(1, (int)($options['limit'] ?? 20));
		$load  = !empty($options['load']);

		$empty = (string)($options['empty'] ?? '');
		if ($empty !== '' && $this->getDataViewQueryService() !== null) {
			$params = $this->buildCountParams($options);
			$result = $this->getDataViewQueryService()->query($viewId, $params);
			if ($result->total === 0) {
				return $this->buildEmptyHtml($empty);
			}
		}

		if ($load && $this->twigEngineFactory !== null && $this->getDataViewQueryService() !== null) {
			return $this->loadDataViewItems($viewId, $template, $limit, $options);
		}

		$baseUrl = $this->config->api . '/dataviews/' . $viewId . '/query';

		return $this->buildTrigger($baseUrl, $options);
	}

	/**
	 * Query collection items and render them server-side, appending the HTMX trigger if more exist.
	 *
	 * @param array<string,mixed> $options
	 */
	private function loadItems(string $collection, string $template, int $limit, array $options): string
	{
		/** @var IndexQueryService $queryService */
		$queryService = $this->indexQueryService;
		$params       = $this->buildLoadParams($options, $limit);
		$result       = $queryService->query($collection, $params);

		$html = $this->renderItems($result->items, $template, $collection);

		if ($result->hasMore()) {
			$baseUrl = $this->config->api . '/collections/' . $collection . '/query';
			$html .= $this->buildTrigger($baseUrl, $options);
		}

		return $html;
	}

	/**
	 * Query DataView items and render them server-side, appending the HTMX trigger if more exist.
	 *
	 * @param array<string,mixed> $options
	 */
	private function loadDataViewItems(string $viewId, string $template, int $limit, array $options): string
	{
		/** @var DataViewQueryService $queryService */
		$queryService = $this->getDataViewQueryService();
		$params       = $this->buildLoadParams($options, $limit);
		$result       = $queryService->query($viewId, $params);

		$html = $this->renderItems($result->items, $template);

		if ($result->hasMore()) {
			$baseUrl = $this->config->api . '/dataviews/' . $viewId . '/query';
			$html .= $this->buildTrigger($baseUrl, $options);
		}

		return $html;
	}

	/**
	 * Render items using the TwigEngine.
	 *
	 * @param array<int,array<string,mixed>> $items
	 */
	private function renderItems(array $items, string $template, string $collection = ''): string
	{
		/** @var \Closure(): TwigEngine $factory */
		$factory    = $this->twigEngineFactory;
		$twigEngine = $factory();
		$html       = '';

		// Ensure template has .twig extension
		if (!str_ends_with($template, '.twig')) {
			$template .= '.twig';
		}

		foreach ($items as $item) {
			$data = ['object' => $item];
			if ($collection !== '') {
				$data['collection'] = $collection;
			}
			$html .= $twigEngine->render($template, $data);
		}

		return $html;
	}

	/**
	 * Build query params for a load query (page 1).
	 *
	 * @param array<string,mixed> $options
	 * @return array<string,string>
	 */
	private function buildLoadParams(array $options, int $limit): array
	{
		$params = ['limit' => (string)$limit, 'offset' => '0'];
		$optionalKeys = ['sort', 'include', 'exclude', 'search'];
		foreach ($optionalKeys as $key) {
			if (isset($options[$key]) && (string)$options[$key] !== '') {
				$params[$key] = (string)$options[$key];
			}
		}

		return $params;
	}

	/**
	 * Build the HTMX trigger from options and delegate to HtmxRenderer.
	 *
	 * @param string              $baseUrl Full base URL for the query endpoint
	 * @param array<string,mixed> $options User-provided options
	 */
	private function buildTrigger(string $baseUrl, array $options): string
	{
		$template    = (string)($options['template'] ?? '');
		$limit       = max(1, (int)($options['limit'] ?? 20));
		$trigger     = (string)($options['trigger'] ?? 'revealed');
		$buttonLabel = (string)($options['buttonLabel'] ?? 'Load More');
		$buttonClass = (string)($options['buttonClass'] ?? '');

		// Build query params — offset starts at limit because page 1 is server-rendered
		$queryParams = [
			'format'   => 'html',
			'template' => $template,
			'offset'   => (string)$limit,
			'limit'    => (string)$limit,
		];

		// Add optional params
		$optionalKeys = ['sort', 'include', 'exclude', 'search'];
		foreach ($optionalKeys as $key) {
			if (isset($options[$key]) && (string)$options[$key] !== '') {
				$queryParams[$key] = (string)$options[$key];
			}
		}

		// Pass trigger, buttonLabel, and buttonClass through so the chain preserves them
		if ($trigger !== 'revealed') {
			$queryParams['trigger'] = $trigger;
		}
		if ($buttonLabel !== 'Load More') {
			$queryParams['buttonLabel'] = $buttonLabel;
		}
		if ($buttonClass !== '') {
			$queryParams['buttonClass'] = $buttonClass;
		}

		$transition = !empty($options['transition']);
		if ($transition) {
			$queryParams['transition'] = '1';
		}

		return $this->htmxRenderer->buildInitialTrigger($baseUrl, $queryParams, $trigger, $buttonLabel, $buttonClass, $transition);
	}

	/**
	 * Build minimal query params for an empty-check count query.
	 *
	 * @param array<string,mixed> $options
	 * @return array<string,string>
	 */
	private function buildCountParams(array $options): array
	{
		$params = ['limit' => '1', 'offset' => '0'];
		$optionalKeys = ['sort', 'include', 'exclude', 'search'];
		foreach ($optionalKeys as $key) {
			if (isset($options[$key]) && (string)$options[$key] !== '') {
				$params[$key] = (string)$options[$key];
			}
		}

		return $params;
	}

	private function buildEmptyHtml(string $message): string
	{
		return '<div class="cms-no-results">' . $message . '</div>';
	}

	/** @param array<string,string> $getData */
	public function paginationSimple(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array $getData     = [],
	): string {
		return PaginationGenerator::simplePagination(...func_get_args());
	}

	/** @param array<string,string> $getData */
	public function paginationFull(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array $getData     = [],
	): string {
		return PaginationGenerator::fullPagination(...func_get_args());
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function image(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
			'loading'    => 'lazy',
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		$imagePath = $this->media->imagePath($idOrObject, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		// Performance optimization: Extract image data from object if passed
		if (is_array($idOrObject)) {
			$image = $idOrObject[$options['property']] ?? [];
		} else {
			$image = $this->data->raw($options['collection'], $idOrObject, $options['property']);
		}

		// Calculate dimensions for layout stability (prevents CLS)
		$dimensions = ImageDimensionCalculator::calculateFromImageData($image, $imageworks);

		$html = HTMLUtils::inlineElement('img', [
			'src'           => $imagePath,
			'alt'           => $this->alt($idOrObject, $options),
			'width'         => $dimensions['width'],
			'height'        => $dimensions['height'],
			'class'         => $options['class'] ?? null,
			'loading'       => $options['loading'] ?? null,
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		]);

		if (!empty($image['link'])) {
			$html = HTMLUtils::element('a', $html, ['href' => $image['link']]);
		}

		return $html;
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $thumbSettings
	 * @param array<string,string|int> $fullSettings
	 * @param array<string,mixed> $options
	 */
	public function gallery(string|array|null $idOrObject, array $thumbSettings = [], array $fullSettings = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if ($thumbSettings === []) {
			$thumbSettings = ['w' => 300, 'h' => 200];
		}

		$gallery = '';

		if (in_array($idOrObject, [null, '', []], true)) {
			return $gallery;
		}

		// Performance optimization: Accept full object to avoid re-fetching
		if (is_array($idOrObject)) {
			// Object passed directly - extract ID and gallery data
			$id     = $idOrObject['id'] ?? '';
			$images = $idOrObject[$options['property']] ?? [];
		} else {
			// Original behavior: ID string passed, fetch object data
			$id     = $idOrObject;
			$images = $this->data->raw($options['collection'], $id, $options['property']);
		}

		if (in_array($images, [null, '', []], true)) {
			return $gallery;
		}

		// Sort images if sort option is provided
		if (isset($options['sort']) && $options['sort'] !== '') {
			$images = $this->sortGalleryImages($images, $options['sort']);
		}

		// Check if captions should be shown on grid thumbnails and in lightbox
		// When set to a string, it's used as a template (e.g., "{{alt}} | {{exif.camera}}")
		$showGridCaptions  = !empty($options['gridCaptions']);
		$gridCaptionTpl    = isset($options['gridCaptions']) && $options['gridCaptions'] !== true ? trim((string)$options['gridCaptions']) : '';
		$showCaptions      = !empty($options['captions']);
		$captionTpl        = isset($options['captions']) && $options['captions'] !== true ? trim((string)$options['captions']) : '';

		// Check if only featured images should be shown in grid (but all in lightbox)
		$featuredOnly = isset($options['featuredOnly']) && $options['featuredOnly'];
		$allImages    = $images; // Keep all images for lightbox
		if ($featuredOnly) {
			$images = array_filter($images, fn (array $img): bool => !empty($img['featured']));
			$images = array_values($images); // Re-index array
		}

		// Uses direct image data to avoid redundant galleryImageData() lookups per image
		foreach ($images as $image) {
			// Calculate dimensions for layout stability (prevents CLS)
			$thumbDimensions = ImageDimensionCalculator::calculateFromImageData($image, $thumbSettings);

			// Build full-size URL once and reuse for both href and data-src
			$fullUrl = $this->buildGalleryUrl($id, $image, $fullSettings, $options);

			$img = HTMLUtils::inlineElement('img', [
				'src'           => $this->buildGalleryUrl($id, $image, $thumbSettings, $options),
				'alt'           => $this->altFromImageData($image),
				'width'         => $thumbDimensions['width'],
				'height'        => $thumbDimensions['height'],
				'loading'       => 'lazy',
				'draggable'     => 'false',
				'oncontextmenu' => 'return false;',
			]);
			$link = HTMLUtils::element('a', $img, [
				'href' => $fullUrl,
			]);

			// Always wrap in figure for semantic HTML5
			$figureContent = $link;
			if ($showGridCaptions) {
				$captionText = $this->captionFromImageData($image, $gridCaptionTpl);
				if ($captionText !== '') {
					$captionHtml = $gridCaptionTpl !== '' ? $captionText : htmlspecialchars($captionText);
					$caption     = HTMLUtils::element('figcaption', $captionHtml, ['class' => 'cms-gallery-caption']);
					$figureContent .= $caption;
				}
			}

			// Calculate the actual dimensions after ImageWorks processing
			$processedDimensions = ImageDimensionCalculator::calculateFromImageData($image, $fullSettings);

			$figureAttrs = [
				'class'        => 'cms-gallery-item',
				'data-src'     => $fullUrl,
				'data-lg-size' => "{$processedDimensions['width']}-{$processedDimensions['height']}",
			];

			// Add lightbox caption via data-sub-html attribute
			if ($showCaptions) {
				$captionText = $this->captionFromImageData($image, $captionTpl);
				if ($captionText !== '') {
					$figureAttrs['data-sub-html'] = $captionTpl !== '' ? $captionText : htmlspecialchars($captionText);
				}
			}

			// Add image name for mapping when using featuredOnly mode
			if ($featuredOnly) {
				$figureAttrs['data-gallery-image'] = $image['name'];
			}

			$figure = HTMLUtils::element('figure', $figureContent, $figureAttrs);
			$gallery .= $figure;
		}

		// Build dynamic elements for all images when featuredOnly is enabled
		$dynamicTemplate = '';
		if ($featuredOnly && count($allImages) > count($images)) {
			$dynamicEl = [];
			foreach ($allImages as $img) {
				$item = [
					'src'    => $this->buildGalleryUrl($id, $img, $fullSettings, $options),
					'thumb'  => $this->buildGalleryUrl($id, $img, $thumbSettings, $options),
					'lgSize' => "{$img['width']}-{$img['height']}",
					'name'   => $img['name'],
				];
				if ($showCaptions) {
					$captionText = $this->captionFromImageData($img, $captionTpl);
					if ($captionText !== '') {
						$item['subHtml'] = $captionTpl !== '' ? $captionText : htmlspecialchars($captionText);
					}
				}
				$dynamicEl[] = $item;
			}
			$dynamicTemplate = sprintf(
				'<template class="cms-gallery-dynamic">%s</template>',
				(string)json_encode($dynamicEl)
			);
		}

		// Don't add these to the gallery settings
		unset($options['collection']);
		unset($options['property']);
		unset($options['captions']); // Remove captions option from JS settings
		unset($options['gridCaptions']); // Remove gridCaptions option from JS settings
		unset($options['featuredOnly']); // Remove featuredOnly from JS settings
		unset($options['sort']); // Remove sort option from JS settings

		// Prevent lightGallery from using alt/title as caption fallback
		$options['getCaptionFromTitleOrAlt'] = false;

		// Extract custom class before encoding settings
		$customClass = '';
		if (isset($options['class'])) {
			$customClass = $options['class'];
			unset($options['class']);
		}

		// Extract maxVisible and viewAllText before encoding settings
		$maxVisible = 0;
		if (isset($options['maxVisible']) && $options['maxVisible'] > 0) {
			$maxVisible = (int)$options['maxVisible'];
			unset($options['maxVisible']);
		}

		$viewAllText = null;
		if (isset($options['viewAllText'])) {
			$viewAllText = $options['viewAllText'];
			unset($options['viewAllText']);
		}

		// Build CSS classes - always include 'cms-gallery', add custom class if provided
		$cssClasses = 'cms-gallery';
		if (!empty($customClass)) {
			$cssClasses .= ' ' . $customClass;
		}

		$attributes = [
			'class'         => $cssClasses,
			'data-settings' => (string)json_encode($options),
		];

		// Add max-visible attribute if provided
		if ($maxVisible > 0) {
			$attributes['data-max-visible'] = (string)$maxVisible;
			if ($viewAllText !== null) {
				$attributes['data-view-all-text'] = htmlspecialchars((string)$viewAllText);
			}
		}

		$output = HTMLUtils::element('div', $gallery, $attributes);

		// Append dynamic template if featuredOnly mode has additional images
		if ($dynamicTemplate !== '') {
			$output .= $dynamicTemplate;
		}

		return $output;
	}

	/**
	 * Generate a dynamic gallery that can be triggered programmatically.
	 * Returns a template tag with JSON data for JavaScript initialization.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,string|int> $thumbSettings
	 * @param array<string,string|int> $fullSettings
	 * @param array<string,mixed> $options
	 */
	public function galleryLauncher(string|array $idOrObject, array $thumbSettings = [], array $fullSettings = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if ($thumbSettings === []) {
			$thumbSettings = ['w' => 300, 'h' => 200];
		}

		// Performance optimization: Extract gallery data from object if passed
		if (is_array($idOrObject)) {
			$id     = $idOrObject['id'] ?? '';
			$images = $idOrObject[$options['property']] ?? [];
		} else {
			$id     = $idOrObject;
			$images = $this->data->raw($options['collection'], $id, $options['property']);
		}

		// Sort images if sort option is provided
		if (isset($options['sort']) && $options['sort'] !== '') {
			$images = $this->sortGalleryImages($images, $options['sort']);
		}

		// Check if captions should be shown in subHtml
		$showCaptions = !empty($options['captions']);
		$captionTpl   = isset($options['captions']) && $options['captions'] !== true ? trim((string)$options['captions']) : '';

		// Build dynamicEl array for lightGallery
		// Uses direct image data to avoid redundant galleryImageData() lookups per image
		$dynamicEl = [];
		foreach ($images as $image) {
			$item = [
				'src'    => $this->buildGalleryUrl($id, $image, $fullSettings, $options),
				'thumb'  => $this->buildGalleryUrl($id, $image, $thumbSettings, $options),
				'lgSize' => "{$image['width']}-{$image['height']}",
				'name'   => $image['name'], // Include name for image-based index lookup
			];

			// Add subHtml if captions are enabled and meaningful alt text exists
			if ($showCaptions) {
				$captionText = $this->captionFromImageData($image, $captionTpl);
				if ($captionText !== '') {
					$item['subHtml'] = $captionTpl !== '' ? $captionText : htmlspecialchars($captionText);
				}
			}

			$dynamicEl[] = $item;
		}

		// Generate unique gallery ID (allow override via options)
		$galleryId = $options['galleryId'] ?? "{$options['collection']}-{$id}";

		// Remove options that shouldn't be in JS settings
		unset($options['collection']);
		unset($options['property']);
		unset($options['captions']);
		unset($options['gridCaptions']);
		unset($options['galleryId']);
		unset($options['sort']);

		// Prevent lightGallery from using alt/title as caption fallback
		$options['getCaptionFromTitleOrAlt'] = false;

		// Build template attributes
		$attributes = [
			'data-gallery-id' => $galleryId,
			'data-settings'   => (string)json_encode($options),
		];

		// Convert attributes to HTML string
		$attributesString = '';
		foreach ($attributes as $key => $value) {
			$attributesString .= sprintf(' %s="%s"', $key, htmlspecialchars((string)$value, ENT_QUOTES));
		}

		// Return template tag with JSON content
		return sprintf(
			'<template%s>%s</template>',
			$attributesString,
			htmlspecialchars((string)json_encode($dynamicEl), ENT_QUOTES)
		);
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryImage(string|array|null $idOrObject, string|int|null $name, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (in_array($idOrObject, [null, '', []], true) || $name === null || $name === '') {
			return '';
		}

		$imagePath = $this->media->galleryPath($idOrObject, $name, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		$image = $this->media->galleryImageData($idOrObject, $name, $options);
		$link  = $image['link'] ?? '';

		// Calculate dimensions for layout stability (prevents CLS)
		$dimensions = ImageDimensionCalculator::calculateFromImageData($image ?? [], $imageworks);

		// Determine gallery ID and image name for launcher integration
		$id        = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		$imageName = $image['name'] ?? (is_string($name) ? $name : '');

		$imgAttrs = [
			'src'                => $imagePath,
			'alt'                => $this->galleryAlt($idOrObject, $name, $options),
			'width'              => $dimensions['width'],
			'height'             => $dimensions['height'],
			'draggable'          => 'false',
			'oncontextmenu'      => 'return false;',
			'data-gallery'       => "{$options['collection']}-{$id}",
			'data-gallery-image' => $imageName,
			'class'              => $options['class'] ?? null,
			'loading'            => $options['loading'] ?? null,
		];

		$html = HTMLUtils::inlineElement('img', $imgAttrs);

		if (!empty($link)) {
			$html = HTMLUtils::element('a', $html, ['href' => $link]);
		}

		return $html;
	}

	/**
	 * Get an alt tag for an image.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function alt(string|array $idOrObject, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		// Performance optimization: Extract image data from object if passed
		if (is_array($idOrObject)) {
			$image = $idOrObject[$options['property']] ?? null;
		} else {
			$image = $this->data->raw($options['collection'], $idOrObject, $options['property']);
		}

		if (!is_array($image)) {
			return '';
		}

		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return $image['name'] ?? '';
	}

	/**
	 * Get an alt tag for a gallery image.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function galleryAlt(string|array $idOrObject, string|int $name, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$image = $this->media->galleryImageData($idOrObject, $name, $options);

		if (!is_array($image)) {
			return '';
		}

		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return $image['name'] ?? '';
	}

	/**
	 * Get caption text for a gallery image.
	 * Same fallback chain as galleryAlt() but WITHOUT the filename fallback,
	 * since filenames make poor visible captions.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function galleryCaption(string|array $idOrObject, string|int $name, array $options = [], string $template = ''): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$image = $this->media->galleryImageData($idOrObject, $name, $options);

		if (!is_array($image)) {
			return '';
		}

		// Template mode: render using lightweight Twig engine
		if ($template !== '') {
			return $this->renderCaptionTemplate($template, $image);
		}

		// Default fallback chain (no filename)
		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return '';
	}

	/**
	 * Render a depot file browser.
	 *
	 * @param array<string,mixed> $options
	 */
	public function depotBrowser(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection'  => 'depot',
			'property'    => 'depot',
			'filter'      => false,
			'preview'     => false,
			'comments'    => false,
			'download'    => true,
			'tags'        => false,
			'folders'     => true,
			'humanize'    => true,
			'class'       => '',
			'reverseSort' => false,
			'filterTags'  => [],
		], $options);

		$collection = $options['collection'];
		$property   = $options['property'];

		$depot = $this->data->raw($collection, $id, $property);
		if (!is_array($depot)) {
			return '';
		}

		$downloadUrl = fn (string $objId, string $name, array $opts): string => $this->media->depotDownload(
			$objId,
			$name,
			array_merge(['collection' => $collection, 'property' => $property], $opts),
		);

		$streamUrl = fn (string $objId, string $name, array $opts): string => $this->media->depotStream(
			$objId,
			$name,
			array_merge(['collection' => $collection, 'property' => $property], $opts),
		);

		return $this->depotBrowserRenderer->render($id, $depot, $options, $downloadUrl, $streamUrl);
	}

	/**
	 * Render the clone dialog for a collection.
	 */
	public function cloneDialog(string $collection): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof \TotalCMS\Domain\Collection\Data\CollectionData) {
			return '';
		}

		$schemaData    = $this->schemaFetcher->fetchSchema($collectionData->schema);
		$labelSingular = $collectionData->labelSingular !== '' ? $collectionData->labelSingular : 'Object';

		$header = HTMLUtils::element('h3', 'Clone ' . $labelSingular);

		$collections = $this->collectionLister->listCollectionsWithSchema($schemaData->id);

		$options = '';
		foreach ($collections as $coll) {
			$attrs = ['value' => $coll->id];
			if ($coll->id === $collectionData->id) {
				$attrs['selected'] = '';
			}
			$options .= HTMLUtils::element('option', $coll->name, $attrs);
		}

		$label           = HTMLUtils::element('label', 'Clone into Collection', ['for' => 'clone-collection']);
		$input           = HTMLUtils::element('select', $options, ['id' => 'clone-collection', 'type' => 'text', 'name' => 'collection']);
		$collectionField = HTMLUtils::element('div', $label . $input);

		$label   = HTMLUtils::element('label', 'New ' . $labelSingular . ' ID', ['for' => 'clone-id']);
		$input   = HTMLUtils::inlineElement('input', [
			'id'             => 'clone-id',
			'type'           => 'text',
			'name'           => 'id',
			'autocapitalize' => 'off',
			'class'          => 'slugify-input',
		]);
		$idField = HTMLUtils::element('div', $label . $input);

		$form = new \TotalCMS\Domain\Admin\SimpleForm(
			api     : $this->config->api,
			route   : '',
			method  : 'POST',
			label   : 'Clone ' . $labelSingular,
			class   : 'clone-object-form',
			refresh : true,
		);
		$content = $form->build($header . $collectionField . $idField);

		return HTMLUtils::dialog($content, 'dialog-clone-object small');
	}

	/**
	 * Build an ImageWorks URL for a gallery image using pre-loaded image data.
	 * Avoids redundant galleryImageData() lookups when image data is already available.
	 *
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	private function buildGalleryUrl(string $id, array $image, array $imageworks, array $options): string
	{
		$imageworks = $this->media->resolvePresetFormat($imageworks);

		return MediaTwigAdapter::buildImageworksGalleryAPI($this->config->api, $id, $image['name'] ?? '', $image, $imageworks, $options);
	}

	/**
	 * Get alt text for a gallery image using pre-loaded image data.
	 * Avoids redundant galleryImageData() lookups when image data is already available.
	 *
	 * @param array<string,mixed> $image
	 */
	private function altFromImageData(array $image): string
	{
		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return $image['name'] ?? '';
	}

	/**
	 * Get caption text for a gallery image using pre-loaded image data.
	 * Avoids redundant galleryImageData() lookups when image data is already available.
	 *
	 * @param array<string,mixed> $image
	 */
	private function captionFromImageData(array $image, string $template = ''): string
	{
		if ($template !== '') {
			return $this->renderCaptionTemplate($template, $image);
		}

		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return '';
	}

	/**
	 * Sort gallery images using CollectionSorter.
	 *
	 * @param array<array<string,mixed>> $images
	 * @param string|array<array<string,mixed>> $sort Sort option: string property name (prefix with '-' for reverse) or array of rule arrays
	 *
	 * @return array<array<string,mixed>>
	 */
	private function sortGalleryImages(array $images, string|array $sort): array
	{
		if (is_string($sort)) {
			$reverse  = str_starts_with($sort, '-');
			$property = $reverse ? substr($sort, 1) : $sort;
			$rules    = [['property' => $property, 'reverse' => $reverse]];
		} else {
			$rules = $sort;
		}

		$sorter = new CollectionSorter($images);

		return $sorter->sortByRules($rules);
	}

	/**
	 * Get a lightweight Twig environment for rendering caption templates.
	 * This is separate from the main TwigEngine to avoid circular dependencies.
	 */
	private function getCaptionTwig(): TwigEnvironment
	{
		if (!$this->captionTwig instanceof TwigEnvironment) {
			$this->captionTwig = new TwigEnvironment(new ArrayLoader(), [
				'autoescape'       => false,
				'strict_variables' => false,
			]);
		}

		return $this->captionTwig;
	}

	/**
	 * Render a caption template using a lightweight Twig environment.
	 * Image data fields are available directly (e.g., {{ alt }}, {{ exif.camera }}).
	 * Returns empty string if all output is whitespace/separators.
	 *
	 * @param array<string,mixed> $image
	 */
	private function renderCaptionTemplate(string $template, array $image): string
	{
		try {
			$template = str_replace(['{', '}'], ['{{', '}}'], $template);
			$twig     = $this->getCaptionTwig();
			$tmpl     = $twig->createTemplate($template);
			$result   = trim($tmpl->render($image));

			// If the result has no meaningful text content, treat as empty
			if (trim(strip_tags($result)) === '') {
				return '';
			}

			return $result;
		} catch (\Exception $e) {
			$this->logger->warning('Gallery caption template error: ' . $e->getMessage(), ['template' => $template]);

			return '';
		}
	}
}
