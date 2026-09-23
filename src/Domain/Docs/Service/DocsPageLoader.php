<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Docs\Service;

use TotalCMS\Support\PathResolver;

/**
 * Maps a `/admin/docs/{page}` path onto `resources/docs`: rejects traversal,
 * decides whether the target is a JSON index, a co-located image, a markdown
 * page or a legacy HTML page, and loads the sidebar menu.
 */
class DocsPageLoader
{
	private const IMAGE_MIMES = [
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'svg'  => 'image/svg+xml',
		'webp' => 'image/webp',
	];

	public function __construct(private readonly ?string $docsDir = null)
	{
	}

	public function docsDir(): string
	{
		return $this->docsDir ?? PathResolver::packageRoot() . '/resources/docs';
	}

	/**
	 * Normalize a requested page path. Anything that could escape the docs
	 * directory, or carries characters a doc path never has, falls back to
	 * the index. Dots stay legal for image filenames; `..` is rejected first.
	 */
	public function sanitize(string $page): string
	{
		$page = str_replace('\\', '/', $page);
		$page = (string)preg_replace('#/+#', '/', $page);
		$page = trim($page, '/');

		if (str_contains($page, '..')) {
			return 'index';
		}

		if ($page !== '' && !preg_match('#^[a-zA-Z0-9_/.-]+$#', $page)) {
			return 'index';
		}

		return $page === '' ? 'index' : $page;
	}

	/** Locate what a (sanitized) page path points at. */
	public function resolve(string $page): DocsResource
	{
		$dir = $this->docsDir();

		if (file_exists("{$dir}/{$page}.json")) {
			return new DocsResource(DocsResource::JSON, $page, "{$dir}/{$page}.json", 'application/json');
		}

		$ext = strtolower(pathinfo($page, PATHINFO_EXTENSION));
		if (isset(self::IMAGE_MIMES[$ext])) {
			// An image URL that does not exist is missing, never a page.
			return file_exists("{$dir}/{$page}")
				? new DocsResource(DocsResource::IMAGE, $page, "{$dir}/{$page}", self::IMAGE_MIMES[$ext])
				: new DocsResource(DocsResource::MISSING, $page);
		}

		if (file_exists("{$dir}/{$page}.md")) {
			return new DocsResource(DocsResource::MARKDOWN, $page, "{$dir}/{$page}.md", 'text/markdown');
		}

		if (file_exists("{$dir}/{$page}.html")) {
			return new DocsResource(DocsResource::HTML, $page, "{$dir}/{$page}.html", 'text/html');
		}

		return new DocsResource(DocsResource::MISSING, $page);
	}

	/**
	 * The sidebar menu from `menu.php`, shared with `bin/build-docs-index.php`
	 * so search results carry the same group labels.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function menu(): array
	{
		$menuFile = $this->docsDir() . '/menu.php';
		if (!file_exists($menuFile)) {
			return [];
		}
		$menu = require $menuFile;
		if (!is_array($menu)) {
			return [];
		}

		$normalized = [];
		foreach ($menu as $group) {
			if (!is_array($group)) {
				continue;
			}
			$entry = [];
			foreach ($group as $key => $value) {
				if (is_string($key)) {
					$entry[$key] = $value;
				}
			}
			$normalized[] = $entry;
		}

		return $normalized;
	}
}
