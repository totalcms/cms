<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Collection\Utilities\PaginationGenerator;
use TotalCMS\Domain\Twig\Service\CloneDialogRenderer;
use TotalCMS\Domain\Twig\Service\DepotBrowserRenderer;
use TotalCMS\Domain\Twig\Service\GalleryRenderer;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\ImageRenderer;
use TotalCMS\Domain\Twig\Service\LoadMoreRenderer;
use TotalCMS\Domain\Twig\Service\VideoRenderer;

/**
 * Twig sub-adapter for frontend rendering helpers.
 *
 * Accessed in Twig as `cms.render.*`.
 * Provides methods that generate HTML output for frontend use.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class RenderTwigAdapter
{
	private ?VideoRenderer $videoRenderer = null;

	/**
	 * Each concern is its own renderer (see src/Domain/Twig/Service); this
	 * class only exposes them as `cms.render.*`. The grid renderer is public
	 * because templates address it directly as `cms.render.grid.*`.
	 */
	public function __construct(
		private readonly DataTwigAdapter $data,
		private readonly MediaTwigAdapter $media,
		public readonly GridRenderer $grid,
		private readonly LoadMoreRenderer $loadMore,
		private readonly ImageRenderer $image,
		private readonly GalleryRenderer $gallery,
		private readonly CloneDialogRenderer $cloneDialog,
		private readonly DepotBrowserRenderer $depotBrowserRenderer = new DepotBrowserRenderer(),
	) {
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
		return $this->loadMore->loadMore($collection, $options);
	}

	/**
	 * URL for an HTML fragment of a collection query — the endpoint behind
	 * loadMore(), exposed for hx-get on your own elements (live search,
	 * facets, lazy sections). Returns the raw URL; Twig's autoescape handles
	 * `&` inside attributes.
	 *
	 * ```twig
	 * <input name="search" hx-get="{{ cms.render.queryUrl('blog', {template: 'blog/card', limit: 12}) }}"
	 *        hx-trigger="input changed delay:300ms" hx-target="#results">
	 * ```
	 *
	 * @param array<string,mixed> $options template (required), limit, offset, sort, include, exclude, search, mode
	 */
	public function queryUrl(string $collection, array $options = []): string
	{
		return $this->loadMore->queryUrl($collection, $options);
	}

	/**
	 * URL for an HTML fragment of a Data View query. Same options as queryUrl().
	 *
	 * @param array<string,mixed> $options
	 */
	public function viewQueryUrl(string $viewId, array $options = []): string
	{
		return $this->loadMore->viewQueryUrl($viewId, $options);
	}

	/**
	 * URL for one object rendered through a template (quick views, expandable
	 * rows, inline detail).
	 *
	 * @param array<string,mixed> $options template (required)
	 */
	public function objectFragmentUrl(string $collection, string $id, array $options = []): string
	{
		return $this->loadMore->objectFragmentUrl($collection, $id, $options);
	}

	/**
	 * Target for an hx-post (create) or, with `id`, hx-put / hx-patch (update)
	 * of an object. With `template`, an HTMX request gets that template
	 * rendered against the saved object instead of JSON.
	 *
	 * @param array<string,mixed> $options id, template
	 */
	public function saveUrl(string $collection, array $options = []): string
	{
		return $this->loadMore->saveUrl($collection, $options);
	}

	/**
	 * Target for an hx-post that increments a number property (likes,
	 * "was this helpful"). Anonymous callers need the collection's
	 * `increment` public operation.
	 */
	public function incrementUrl(string $collection, string $id, string $property, int $amount = 1): string
	{
		return $this->loadMore->incrementUrl($collection, $id, $property, $amount);
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
		return $this->loadMore->loadMoreDataView($viewId, $options);
	}

	/**
	 * Generate a standalone HTMX button for loading items into an external container.
	 *
	 * Unlike loadMore() which uses a self-replacing sentinel pattern, the button
	 * uses hx-target + hx-swap="beforeend" so it can be placed anywhere on the page.
	 *
	 * @param string              $collection Collection identifier
	 * @param array<string,mixed> $options    Options: template (required), target (required), limit, offset, load, sort, include, exclude, search, buttonLabel, buttonClass, transition, id
	 */
	public function loadMoreButton(string $collection, array $options = []): string
	{
		return $this->loadMore->loadMoreButton($collection, $options);
	}

	/**
	 * Generate a standalone HTMX button for loading DataView items into an external container.
	 *
	 * @param string              $viewId  DataView identifier
	 * @param array<string,mixed> $options Options: template (required), target (required), limit, offset, load, sort, include, exclude, search, buttonLabel, buttonClass, transition, id
	 */
	public function loadMoreDataViewButton(string $viewId, array $options = []): string
	{
		return $this->loadMore->loadMoreDataViewButton($viewId, $options);
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
		return $this->image->image($idOrObject, $imageworks, $options);
	}

	/**
	 * Render a `video` field property (or a local `file`-field video) as an
	 * embed, `<video>` element, or click-to-play facade. All the work lives in
	 * VideoRenderer; this is the Twig-facing entry point.
	 *
	 * The video options come first because this is a video API; the poster
	 * transform is the secondary knob, so it trails (the mirror of
	 * cms.render.image(), where ImageWorks is the point and leads).
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options collection, property, autoplay, loop, muted, controls, class, poster, facade
	 * @param array<string,string|int> $posterImageworks ImageWorks parameters applied to an uploaded poster
	 */
	public function video(string|array|null $idOrObject, array $options = [], array $posterImageworks = []): string
	{
		$options = array_merge([
			'collection' => 'video',
			'property'   => 'video',
		], $options);

		$this->videoRenderer ??= new VideoRenderer($this->media, $this->data);

		return $this->videoRenderer->render($idOrObject, $options, $posterImageworks);
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $thumbSettings
	 * @param array<string,string|int> $fullSettings
	 * @param array<string,mixed> $options
	 */
	public function gallery(string|array|null $idOrObject, array $thumbSettings = [], array $fullSettings = [], array $options = []): string
	{
		return $this->gallery->gallery($idOrObject, $thumbSettings, $fullSettings, $options);
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
		return $this->gallery->galleryLauncher($idOrObject, $thumbSettings, $fullSettings, $options);
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryImage(string|array|null $idOrObject, string|int|null $name, array $imageworks = [], array $options = []): string
	{
		return $this->gallery->galleryImage($idOrObject, $name, $imageworks, $options);
	}

	/**
	 * Get an alt tag for an image.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function alt(string|array $idOrObject, array $options = []): string
	{
		return $this->image->alt($idOrObject, $options);
	}

	/**
	 * Get an alt tag for a gallery image.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function galleryAlt(string|array $idOrObject, string|int $name, array $options = []): string
	{
		return $this->gallery->galleryAlt($idOrObject, $name, $options);
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
		return $this->gallery->galleryCaption($idOrObject, $name, $options, $template);
	}

	/**
	 * Render a depot file browser.
	 *
	 * @param array<string,mixed> $options
	 */
	public function depotBrowser(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection'        => 'depot',
			'property'          => 'depot',
			'filterPlaceholder' => 'Filter files...',
			'filter'            => false,
			'preview'           => false,
			'comments'          => false,
			'download'          => true,
			'tags'              => false,
			'folders'           => true,
			'humanize'          => true,
			'class'             => '',
			'reverseSort'       => false,
			'filterTags'        => [],
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
		return $this->cloneDialog->render($collection);
	}
}
