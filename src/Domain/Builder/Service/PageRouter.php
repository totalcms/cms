<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

readonly class PageRouter
{
	public function __construct(
		private BuilderConfigService $builderConfig,
		private IndexReader $indexReader,
		private CollectionLister $collectionLister,
		private ObjectUrlBuilder $urlBuilder,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/**
	 * Match a request URI against builder page routes and collection URLs.
	 */
	public function match(string $requestUri): ?RouteMatch
	{
		$path = $this->normalizePath($requestUri);

		// 1. Try builder page routes (highest priority)
		$match = $this->matchBuilderPage($path);
		if ($match !== null) {
			return $match;
		}

		// 2. Try collection URL patterns
		return $this->matchCollectionUrl($path);
	}

	/**
	 * Match against builder page objects.
	 * Static routes first (exact match), then dynamic routes (pattern match).
	 */
	private function matchBuilderPage(string $path): ?RouteMatch
	{
		$collectionId = $this->builderConfig->getPagesCollectionId();

		if (!$this->builderConfig->pagesCollectionExists()) {
			return null;
		}

		$index = $this->indexReader->fetchIndex($collectionId);

		$staticRoutes  = [];
		$dynamicRoutes = [];

		/** @var array<string,mixed> $object */
		foreach ($index->objects as $object) {
			$page = new PageData($object);

			if (!$page->isPublished()) {
				continue;
			}

			if ($page->route === '' || $page->template === '') {
				continue;
			}

			if ($this->isDynamicRoute($page->route)) {
				$dynamicRoutes[] = $page;
			} else {
				$staticRoutes[] = $page;
			}
		}

		// Static routes: exact match
		foreach ($staticRoutes as $page) {
			$route = $this->normalizePath($page->route);
			if ($route === $path) {
				return $this->buildPageMatch($page);
			}
		}

		// Dynamic routes: longest pattern first for specificity
		usort($dynamicRoutes, fn (PageData $a, PageData $b): int => strlen($b->route) <=> strlen($a->route));

		foreach ($dynamicRoutes as $page) {
			$params = $this->matchDynamicRoute($path, $page->route);
			if ($params !== null) {
				return $this->buildPageMatch($page, $params);
			}
		}

		return null;
	}

	/**
	 * Match against collection URL patterns.
	 * Checks all collections that have a URL field set.
	 */
	private function matchCollectionUrl(string $path): ?RouteMatch
	{
		$collections = $this->collectionLister->listAllCollections();

		foreach ($collections as $collection) {
			if ($collection->url === '') {
				continue;
			}

			$match = $this->tryCollectionMatch($path, $collection);
			if ($match !== null) {
				return $match;
			}
		}

		return null;
	}

	/**
	 * Try to match a path against a single collection's URL pattern.
	 */
	private function tryCollectionMatch(string $path, CollectionData $collection): ?RouteMatch
	{
		$url = $collection->url;

		// Non-pretty URL: query string format (?id=xxx) — handled by the stub, not the router
		if (!$collection->prettyUrl) {
			return null;
		}

		// Template URL: /blog/{{ category }}/{{ id }}
		if ($this->urlBuilder->isTemplateUrl($url)) {
			return $this->matchTemplateUrl($path, $collection);
		}

		// Simple pretty URL: /blog/{id}
		$baseUrl = rtrim($url, '/');
		$pattern = '/^' . preg_quote($baseUrl, '/') . '\/([^\/]+)$/';

		if (preg_match($pattern, $path, $matches) !== 1) {
			return null;
		}

		$objectId = $matches[1];

		return $this->buildCollectionMatch($collection, $objectId);
	}

	/**
	 * Match a template URL pattern (e.g., /blog/{{ category }}/{{ id }}).
	 * Reverses the ObjectUrlBuilder logic to create a matching regex.
	 */
	private function matchTemplateUrl(string $path, CollectionData $collection): ?RouteMatch
	{
		$url = $collection->url;

		// Auto-append {{ id }} if not present (same as ObjectUrlBuilder)
		if (!str_contains($url, '{{ id') && !str_contains($url, '{{id')) {
			$url = rtrim($url, '/') . '/{{ id }}';
		}

		// Extract field names from the template
		$fields = $this->urlBuilder->extractTemplateFields($url);

		// Convert template to regex: replace {{ field }} with capture groups
		$regex = preg_quote($url, '/');
		$regex = (string)preg_replace('/\\\{\\\{\s*\w+(?:\s*\\\|[^}]*)?\s*\\\}\\\}/', '([^\/]+)', $regex);
		$regex = '/^' . $regex . '$/';

		if (preg_match($regex, $path, $matches) !== 1) {
			return null;
		}

		// Map captured groups to field names
		$params = [];
		foreach ($fields as $i => $field) {
			$params[$field] = $matches[$i + 1] ?? '';
		}

		// The object ID should be in the params
		$objectId = $params['id'] ?? '';
		if ($objectId === '') {
			return null;
		}

		return $this->buildCollectionMatch($collection, $objectId, $params);
	}

	/**
	 * Build a RouteMatch for a collection URL match.
	 *
	 * @param array<string,string> $params
	 */
	private function buildCollectionMatch(CollectionData $collection, string $objectId, array $params = []): ?RouteMatch
	{
		try {
			$object = $this->objectFetcher->fetchObject($collection->id, $objectId);
		} catch (\Throwable) {
			return null;
		}

		// Use the collection's template (stored in builder/templates/)
		$templatePath = 'templates/' . $collection->id . '.twig';

		return new RouteMatch(
			template:   $templatePath,
			layout:     'default',
			pageData:   $object->toArray(),
			params:     $params,
			collection: $collection->id,
		);
	}

	/**
	 * Check if a route contains dynamic parameters like {id}.
	 */
	private function isDynamicRoute(string $route): bool
	{
		return str_contains($route, '{');
	}

	/**
	 * Try to match a path against a dynamic route pattern.
	 *
	 * @return array<string,string>|null
	 */
	private function matchDynamicRoute(string $path, string $routePattern): ?array
	{
		$pattern = $this->routeToRegex($routePattern);
		$names   = $this->extractParamNames($routePattern);

		if (preg_match($pattern, $path, $matches) !== 1) {
			return null;
		}

		$params = [];
		foreach ($names as $i => $name) {
			$params[$name] = $matches[$i + 1] ?? '';
		}

		return $params;
	}

	/**
	 * Convert a route pattern like /products/{category}/{id}
	 * into a regex like /^\/products\/([^\/]+)\/([^\/]+)$/
	 */
	private function routeToRegex(string $route): string
	{
		$route   = $this->normalizePath($route);
		$escaped = preg_quote($route, '/');
		$regex   = (string)preg_replace('/\\\{[^}]+\\\}/', '([^\/]+)', $escaped);

		return '/^' . $regex . '$/';
	}

	/**
	 * Extract parameter names from a route pattern.
	 *
	 * @return list<string>
	 */
	private function extractParamNames(string $route): array
	{
		preg_match_all('/\{(\w+)\}/', $route, $matches);

		return $matches[1];
	}

	/**
	 * Build a RouteMatch from a matched PageData.
	 *
	 * @param array<string,string> $params
	 */
	private function buildPageMatch(PageData $page, array $params = []): RouteMatch
	{
		$templatePath = 'pages/' . $page->template . '.twig';

		return new RouteMatch(
			template:   $templatePath,
			layout:     $page->layout,
			pageData:   $page->toArray(),
			params:     $params,
			collection: null,
		);
	}

	/**
	 * Normalize a URL path: strip query string, trim trailing slash, ensure leading slash.
	 */
	private function normalizePath(string $uri): string
	{
		$path = parse_url($uri, PHP_URL_PATH);
		if (!is_string($path)) {
			$path = '/';
		}

		$path = rtrim($path, '/');

		if ($path === '') {
			$path = '/';
		}

		return $path;
	}
}
