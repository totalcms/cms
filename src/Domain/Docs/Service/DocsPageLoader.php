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
	 * Every markdown page under the docs directory as a page path
	 * (`site-builder/overview`), sorted, without the landing `index`.
	 *
	 * @return list<string>
	 */
	public function markdownPages(): array
	{
		$dir = $this->docsDir();
		if (!is_dir($dir)) {
			return [];
		}

		$pages    = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'md') {
				continue;
			}
			$page = substr($file->getPathname(), strlen($dir) + 1, -3);
			if ($page !== 'index') {
				$pages[] = $page;
			}
		}
		sort($pages);

		return $pages;
	}

	/**
	 * The sidebar menu from `menu.php` as the template consumes it: the
	 * nested groups, untouched.
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

	/**
	 * The menu flattened to its leaf pages, each labelled with the top-level
	 * group it sits under — whether a direct `sub` entry or nested inside
	 * `groups`. Backs the quick-nav index and the search index's group
	 * labels, so neither can drift from the sidebar.
	 *
	 * @return list<array{group:string,title:string,path:string}>
	 */
	public function pages(): array
	{
		$pages = [];
		foreach ($this->menu() as $group) {
			$groupTitle = is_string($group['title'] ?? null) ? $group['title'] : '';
			$subgroups  = is_array($group['groups'] ?? null) ? $group['groups'] : [];
			$leaves     = [$group['sub'] ?? null];
			foreach ($subgroups as $subgroup) {
				$leaves[] = is_array($subgroup) ? ($subgroup['sub'] ?? null) : null;
			}

			foreach ($leaves as $sub) {
				if (!is_array($sub)) {
					continue;
				}
				foreach ($sub as $page) {
					if (is_array($page) && is_string($page['title'] ?? null) && is_string($page['path'] ?? null)) {
						$pages[] = ['group' => $groupTitle, 'title' => $page['title'], 'path' => $page['path']];
					}
				}
			}
		}

		return $pages;
	}
}
