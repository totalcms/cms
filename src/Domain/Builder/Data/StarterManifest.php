<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

readonly class StarterManifest
{
	public string $name;
	public string $description;
	public string $version;

	/** @var list<array{id:string,title:string,route:string,template:string,nav:bool}> */
	public array $pages;

	/** @param array<string,mixed> $data */
	public function __construct(array $data, public string $directory)
	{
		$this->name        = (string)($data['name'] ?? 'Unknown');
		$this->description = (string)($data['description'] ?? '');
		$this->version     = (string)($data['version'] ?? '1.0.0');

		$pages = [];
		foreach (($data['pages'] ?? []) as $page) {
			if (!is_array($page)) {
				continue;
			}
			$id      = (string)($page['id'] ?? '');
			$pages[] = [
				'id'       => $id,
				'title'    => (string)($page['title'] ?? ''),
				'route'    => (string)($page['route'] ?? ('/' . ltrim($page['path'] ?? '', '/'))),
				'template' => (string)($page['template'] ?? $id),
				'nav'      => (bool)($page['nav'] ?? true),
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
