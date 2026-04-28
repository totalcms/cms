<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class PageData
{
	public string $id;
	public string $title;
	public string $route;
	public string $template;
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
		$this->route       = (string)($data['route'] ?? '');
		$this->template    = (string)($data['template'] ?? '');
		$this->layout      = (string)($data['layout'] ?? 'default');
		$this->description = (string)($data['description'] ?? '');
		$this->draft       = (bool)($data['draft'] ?? false);
		$this->sort        = (int)($data['sort'] ?? 0);
		$this->parent      = (string)($data['parent'] ?? '');
	}

	public function isPublished(): bool
	{
		return !$this->draft;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'id'          => $this->id,
			'title'       => $this->title,
			'route'       => $this->route,
			'template'    => $this->template,
			'layout'      => $this->layout,
			'description' => $this->description,
			'draft'       => $this->draft,
			'sort'        => $this->sort,
			'parent'      => $this->parent,
		];
	}
}
