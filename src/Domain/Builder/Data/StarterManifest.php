<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class StarterManifest
{
	public string $name;
	public string $description;
	public string $version;
	public string $directory;

	/** @var list<array{id:string,title:string,path:string,layout:string,sort:int}> */
	public array $pages;

	/** @param array<string,mixed> $data */
	public function __construct(array $data, string $directory)
	{
		$this->name        = (string)($data['name'] ?? 'Unknown');
		$this->description = (string)($data['description'] ?? '');
		$this->version     = (string)($data['version'] ?? '1.0.0');
		$this->directory   = $directory;

		$pages = [];
		foreach (($data['pages'] ?? []) as $page) {
			if (!is_array($page)) {
				continue;
			}
			$pages[] = [
				'id'     => (string)($page['id'] ?? ''),
				'title'  => (string)($page['title'] ?? ''),
				'path'   => (string)($page['path'] ?? ''),
				'layout' => (string)($page['layout'] ?? 'default'),
				'sort'   => (int)($page['sort'] ?? 0),
			];
		}
		$this->pages = $pages;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'name'        => $this->name,
			'description' => $this->description,
			'version'     => $this->version,
			'pages'       => count($this->pages),
		];
	}
}
