<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Builder\Util\NestedFileTree;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateLister;

/**
 * The Site Builder admin helpers behind `cms.admin.templates*()`,
 * `cms.admin.builder*Tree()` and `cms.admin.builderRouteForCollection()`:
 * template listings by folder and as trees, the git-managed lock, and the
 * builder page whose route serves a pretty-URL collection.
 *
 * AdminTwigAdapter is the Twig-facing entry point and delegates here.
 */
readonly class BuilderTemplateRenderer
{
	public function __construct(
		private TemplateLister $templateLister,
		private BuilderTemplatePaths $paths,
		private BuilderConfigService $builderConfig,
		private IndexReader $indexReader,
		private CollectionFetcher $collectionFetcher,
	) {
	}


	/**
	 * Whether builder template editing is locked because templates are
	 * git-managed on this environment. Admin views use this to show a
	 * read-only banner and hide the save/delete controls.
	 */
	public function templatesLocked(): bool
	{
		return $this->paths->locked();
	}


	/**
	 * Group templates by folder for display in admin sidebar.
	 *
	 * @return array<string,array<array<string,string>>>
	 */
	public function templatesByFolder(): array
	{
		// Get all templates recursively
		$templates = $this->templateLister->listBuilderTemplates(null, true);

		$folders = [];

		foreach ($templates as $path) {
			// Parse path to get folder and template name
			[$folder, $templateId] = TemplatePath::parse($path);

			// Determine group name
			$groupName = 'Templates';
			if ($folder !== null) {
				// Convert folder path to group name (e.g., "pages/blog" -> "Pages / Blog")
				$parts     = explode('/', str_replace('-', ' ', $folder));
				$groupName = implode(' / ', array_map(ucwords(...), $parts));
			}

			// Create template entry
			if (!array_key_exists($groupName, $folders)) {
				$folders[$groupName] = [];
			}

			$resolved = $this->paths->resolveRead($path . TemplateRepository::FILE_EXT);

			$folders[$groupName][] = [
				'id'     => $templateId,
				'folder' => $folder ?? '',
				'path'   => $path, // Full path for linking
				'source' => $resolved['layer'] ?? '',
			];
		}

		// Sort folders alphabetically, but keep "Templates" (root) at the bottom
		uksort($folders, function ($a, $b): int {
			if ($a === 'Templates') {
				return 1;
			}
			if ($b === 'Templates') {
				return -1;
			}

			return strcmp($a, $b);
		});

		return $folders;
	}


	/**
	 * Get the builder file tree organized by category.
	 *
	 * @return array<string,list<array{id:string,path:string}>>
	 */
	public function builderFileTree(): array
	{
		$tree       = [];
		$categories = TemplateRepository::BUILDER_CATEGORIES;

		foreach ($categories as $category) {
			$templates       = $this->templateLister->listBuilderTemplates($category, true);
			$tree[$category] = [];
			foreach ($templates as $templatePath) {
				$tree[$category][] = [
					'id'   => $templatePath,
					'path' => $category . '/' . $templatePath,
				];
			}
		}

		return $tree;
	}

	/**
	 * Get the builder file tree organized by category, with templates that
	 * contain forward slashes ("blog/post") nested into folders. Companion
	 * to {@see builderFileTree()} which returns the same data flat.
	 *
	 * Each node is either a folder `{type:'folder', name, children:[...]}`
	 * or a file `{type:'file', name, id, path}` where `id` is the relative
	 * template id (e.g. "blog/post") and `path` includes the category prefix
	 * (e.g. "pages/blog/post"). Folders sort before files; both alphabetical.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	public function builderNestedFileTree(): array
	{
		$tree = [];

		foreach (TemplateRepository::BUILDER_CATEGORIES as $category) {
			$templates       = $this->templateLister->listBuilderTemplates($category, true);
			$tree[$category] = NestedFileTree::build(array_values($templates), $category);
		}

		return $tree;
	}


	/**
	 * Check if a builder page route covers a collection's URL pattern.
	 *
	 * Returns the matching builder page data, or null if no match.
	 *
	 * @return array{id:string,title:string,route:string}|null
	 */
	public function builderRouteForCollection(string $collectionId): ?array
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof CollectionData || $collection->url === '' || !$collection->prettyUrl) {
			return null;
		}

		// Convert collection URL pattern to builder route format
		$expectedRoute = $this->collectionUrlToBuilderRoute($collection->url);

		// Fetch builder pages
		$pagesCollectionId = $this->builderConfig->getPagesCollectionId();
		if (!$this->builderConfig->pagesCollectionExists()) {
			return null;
		}

		try {
			$index = $this->indexReader->fetchIndex($pagesCollectionId);
		} catch (\Exception) {
			return null;
		}

		foreach ($index->objects as $object) {
			$page = new PageData($object);
			if (!$page->isPublished() || $page->route === '') {
				continue;
			}

			if ($this->routeMatchesPattern($page->route, $expectedRoute)) {
				return ['id' => $page->id, 'title' => $page->title, 'route' => $page->route];
			}
		}

		return null;
	}

	/**
	 * Convert a collection URL template to a builder route pattern.
	 *
	 * /blog                         → /blog/{id}
	 * /blog/{{ category }}/{{ id }} → /blog/{category}/{id}
	 */
	private function collectionUrlToBuilderRoute(string $url): string
	{
		// Strip query string
		$path = strval(parse_url($url, PHP_URL_PATH));
		$path = rtrim($path, '/');

		// Replace {{ field }} or {{ field | filter }} with {field}
		$route = (string)preg_replace('/\{\{\s*(\w+)(?:\s*\|[^}]*)?\s*\}\}/', '{$1}', $path);

		// If no {param} tokens, it's a simple pretty URL — append {id}
		if (!str_contains($route, '{')) {
			// Strip .php extension if present
			if (str_ends_with($route, '.php')) {
				$route = dirname($route);
			}
			$route = rtrim($route, '/') . '/{id}';
		}

		return $route;
	}

	/**
	 * Check if a builder page route matches the expected pattern.
	 *
	 * Normalizes both routes and compares structure (same number of segments,
	 * static segments match, dynamic segments align).
	 */
	private function routeMatchesPattern(string $pageRoute, string $expectedRoute): bool
	{
		$pageRoute     = rtrim($pageRoute, '/');
		$expectedRoute = rtrim($expectedRoute, '/');

		$pageSegments     = explode('/', ltrim($pageRoute, '/'));
		$expectedSegments = explode('/', ltrim($expectedRoute, '/'));

		if (count($pageSegments) !== count($expectedSegments)) {
			return false;
		}

		foreach ($pageSegments as $i => $segment) {
			$expected          = $expectedSegments[$i];
			$segIsDynamic      = str_starts_with($segment, '{') && str_ends_with($segment, '}');
			$expectedIsDynamic = str_starts_with($expected, '{') && str_ends_with($expected, '}');

			// Both dynamic — match
			if ($segIsDynamic && $expectedIsDynamic) {
				continue;
			}

			// Both static — must be equal
			if (!$segIsDynamic && !$expectedIsDynamic && $segment === $expected) {
				continue;
			}

			// One dynamic, one static — no match
			return false;
		}

		return true;
	}
}
