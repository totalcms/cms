<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Support\Config;

/**
 * Load-more and HTML-fragment helpers behind `cms.render.loadMore*()`,
 * `cms.render.*Url()` and friends: the HTMX trigger for page 2+, the
 * standalone load-more button, the optional server-rendered first page, and
 * the URLs templates put on their own hx-get / hx-post elements.
 *
 * RenderTwigAdapter is the Twig-facing entry point and delegates here. The
 * query services and the Twig engine arrive lazily (closures) because the
 * engine depends on the adapters that depend on this.
 */
class LoadMoreRenderer
{
	private ?DataViewQueryService $resolvedDataViewQueryService = null;

	public function __construct(
		private readonly HtmxRenderer $htmxRenderer,
		private readonly Config $config,
		private readonly ?IndexQueryService $indexQueryService = null,
		/** @var (\Closure(): DataViewQueryService)|null */
		private readonly ?\Closure $dataViewQueryServiceFactory = null,
		/** @var (\Closure(): TwigEngine)|null */
		private readonly ?\Closure $twigEngineFactory = null,
	) {
	}

	private function getDataViewQueryService(): ?DataViewQueryService
	{
		if (!$this->resolvedDataViewQueryService instanceof DataViewQueryService && $this->dataViewQueryServiceFactory instanceof \Closure) {
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
		if ($empty !== '' && $this->indexQueryService instanceof IndexQueryService) {
			$params = $this->buildCountParams($options);
			$result = $this->indexQueryService->query($collection, $params);
			if ($result->total === 0) {
				return $this->buildEmptyHtml($empty);
			}
		}

		if ($load && $this->twigEngineFactory instanceof \Closure && $this->indexQueryService instanceof IndexQueryService) {
			return $this->loadItems($collection, $template, $limit, $options);
		}

		$baseUrl = $this->config->api . '/api/collections/' . $collection . '/query';

		return $this->buildTrigger($baseUrl, $options);
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
		return $this->config->api . '/api/collections/' . rawurlencode($collection) . '/query?' . http_build_query($this->fragmentParams($options, 'queryUrl'));
	}

	/**
	 * URL for an HTML fragment of a Data View query. Same options as queryUrl().
	 *
	 * @param array<string,mixed> $options
	 */
	public function viewQueryUrl(string $viewId, array $options = []): string
	{
		return $this->config->api . '/api/dataviews/' . rawurlencode($viewId) . '/query?' . http_build_query($this->fragmentParams($options, 'viewQueryUrl'));
	}

	/**
	 * URL for one object rendered through a template (quick views, expandable
	 * rows, inline detail).
	 *
	 * @param array<string,mixed> $options template (required)
	 */
	public function objectFragmentUrl(string $collection, string $id, array $options = []): string
	{
		$template = (string)($options['template'] ?? '');
		if ($template === '') {
			throw new \InvalidArgumentException('cms.render.objectFragmentUrl: the "template" option is required.');
		}

		return $this->config->api . '/api/collections/' . rawurlencode($collection) . '/' . rawurlencode($id)
			. '?' . http_build_query(['format' => 'html', 'template' => $template]);
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
		$url = $this->config->api . '/api/collections/' . rawurlencode($collection);
		$id  = (string)($options['id'] ?? '');
		if ($id !== '') {
			$url .= '/' . rawurlencode($id);
		}
		$template = (string)($options['template'] ?? '');

		return $template === '' ? $url : $url . '?' . http_build_query(['template' => $template]);
	}

	/**
	 * Target for an hx-post that increments a number property (likes,
	 * "was this helpful"). Anonymous callers need the collection's
	 * `increment` public operation.
	 */
	public function incrementUrl(string $collection, string $id, string $property, int $amount = 1): string
	{
		$url = $this->config->api . '/api/collections/' . rawurlencode($collection) . '/' . rawurlencode($id) . '/' . rawurlencode($property) . '/increment';

		return $amount === 1 ? $url : $url . '/' . $amount;
	}

	/**
	 * Query parameters for the HTML fragment endpoints. Only keys the caller
	 * set are emitted, so the URL says exactly what the template asked for.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,string>
	 */
	private function fragmentParams(array $options, string $helper): array
	{
		$template = (string)($options['template'] ?? '');
		if ($template === '') {
			throw new \InvalidArgumentException("cms.render.{$helper}: the \"template\" option is required.");
		}

		$params = ['format' => 'html', 'template' => $template];
		foreach (['limit', 'offset', 'sort', 'include', 'exclude', 'search', 'mode'] as $key) {
			if (isset($options[$key]) && (string)$options[$key] !== '') {
				$params[$key] = (string)$options[$key];
			}
		}

		return $params;
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
		if ($empty !== '' && $this->getDataViewQueryService() instanceof DataViewQueryService) {
			$params = $this->buildCountParams($options);
			$result = $this->getDataViewQueryService()->query($viewId, $params);
			if ($result->total === 0) {
				return $this->buildEmptyHtml($empty);
			}
		}

		if ($load && $this->twigEngineFactory instanceof \Closure && $this->getDataViewQueryService() instanceof DataViewQueryService) {
			return $this->loadDataViewItems($viewId, $template, $limit, $options);
		}

		$baseUrl = $this->config->api . '/api/dataviews/' . $viewId . '/query';

		return $this->buildTrigger($baseUrl, $options);
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
		$template = (string)($options['template'] ?? '');
		if ($template === '') {
			return '<!-- cms.render.loadMoreButton: "template" option is required -->';
		}

		$target = (string)($options['target'] ?? '');
		if ($target === '') {
			return '<!-- cms.render.loadMoreButton: "target" option is required -->';
		}

		$baseUrl = $this->config->api . '/api/collections/' . $collection . '/query';

		return $this->buildButtonTrigger($baseUrl, $options);
	}

	/**
	 * Generate a standalone HTMX button for loading DataView items into an external container.
	 *
	 * @param string              $viewId  DataView identifier
	 * @param array<string,mixed> $options Options: template (required), target (required), limit, offset, load, sort, include, exclude, search, buttonLabel, buttonClass, transition, id
	 */
	public function loadMoreDataViewButton(string $viewId, array $options = []): string
	{
		$template = (string)($options['template'] ?? '');
		if ($template === '') {
			return '<!-- cms.render.loadMoreDataViewButton: "template" option is required -->';
		}

		$target = (string)($options['target'] ?? '');
		if ($target === '') {
			return '<!-- cms.render.loadMoreDataViewButton: "target" option is required -->';
		}

		$baseUrl = $this->config->api . '/api/dataviews/' . $viewId . '/query';

		return $this->buildButtonTrigger($baseUrl, $options);
	}

	/**
	 * Build the external load-more button from options and delegate to HtmxRenderer.
	 *
	 * @param string              $baseUrl Full base URL for the query endpoint
	 * @param array<string,mixed> $options User-provided options
	 */
	private function buildButtonTrigger(string $baseUrl, array $options): string
	{
		$template    = (string)($options['template'] ?? '');
		$target      = (string)($options['target'] ?? '');
		$limit       = max(1, (int)($options['limit'] ?? 20));
		$offset      = max(0, (int)($options['offset'] ?? 0));
		$buttonLabel = (string)($options['buttonLabel'] ?? 'Load More');
		$buttonClass = (string)($options['buttonClass'] ?? '');
		$load        = !empty($options['load']);
		$transition  = !empty($options['transition']);

		// Generate deterministic button ID
		$buttonId = (string)($options['id'] ?? '');
		if ($buttonId === '') {
			$buttonId = 'cms-lmb-' . substr(md5($template . $target), 0, 8);
		}

		$queryParams = [
			'format'   => 'html',
			'template' => $template,
			'offset'   => (string)$offset,
			'limit'    => (string)$limit,
			'mode'     => 'append',
			'buttonId' => $buttonId,
			'target'   => $target,
		];

		// Add optional params
		$optionalKeys = ['sort', 'include', 'exclude', 'search'];
		foreach ($optionalKeys as $key) {
			if (isset($options[$key]) && (string)$options[$key] !== '') {
				$queryParams[$key] = (string)$options[$key];
			}
		}

		// Pass buttonLabel and buttonClass through so the OOB chain preserves them
		if ($buttonLabel !== 'Load More') {
			$queryParams['buttonLabel'] = $buttonLabel;
		}
		if ($buttonClass !== '') {
			$queryParams['buttonClass'] = $buttonClass;
		}
		if ($transition) {
			$queryParams['transition'] = '1';
		}

		return $this->htmxRenderer->buildButton($baseUrl, $queryParams, $buttonLabel, $buttonClass, $transition, $load);
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
			$baseUrl = $this->config->api . '/api/collections/' . $collection . '/query';
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
			$baseUrl = $this->config->api . '/api/dataviews/' . $viewId . '/query';
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

		// Every user template lives in the `templates` builder category.
		$template = TemplatePath::loaderPath($template);

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
	 *
	 * @return array<string,string>
	 */
	private function buildLoadParams(array $options, int $limit): array
	{
		$params       = ['limit' => (string)$limit, 'offset' => '0'];
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
	 *
	 * @return array<string,string>
	 */
	private function buildCountParams(array $options): array
	{
		$params       = ['limit' => '1', 'offset' => '0'];
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
}
