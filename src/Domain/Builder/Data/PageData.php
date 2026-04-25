<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class PageData
{
	public string $id;
	public string $title;
	public string $path;
	public string $layout;
	public string $description;
	public bool $draft;
	public int $sort;
	public string $parent;

	/** @param array<string,mixed> $data */
	public function __construct(array $data)
	{
		$this->id          = (string)($data['id'] ?? '');
		$this->title       = (string)($data['title'] ?? '');
		$this->path        = trim((string)($data['path'] ?? ''), '/');
		$this->layout      = (string)($data['layout'] ?? 'default');
		$this->description = (string)($data['description'] ?? '');
		$this->draft       = (bool)($data['draft'] ?? false);
		$this->sort        = (int)($data['sort'] ?? 0);
		$this->parent      = (string)($data['parent'] ?? '');
	}

	/**
	 * Template path relative to tcms-data/templates/.
	 * Homepage (empty path) maps to pages/index.twig.
	 */
	public function templatePath(): string
	{
		if ($this->path === '') {
			return 'pages/index.twig';
		}

		return 'pages/' . $this->path . '.twig';
	}

	/**
	 * Stub path relative to docroot.
	 * Homepage maps to index.php, others to {path}/index.php.
	 */
	public function stubPath(): string
	{
		if ($this->path === '') {
			return 'index.php';
		}

		return $this->path . '/index.php';
	}

	public function isPublished(): bool
	{
		return !$this->draft;
	}

	/**
	 * Number of directory levels in the stub path.
	 * Used to calculate relative path back to tcms-boot.php.
	 */
	public function stubDepth(): int
	{
		if ($this->path === '') {
			return 0;
		}

		return count(explode('/', $this->path));
	}
}
