<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Typed representation of an extension's extension.json manifest.
 */
final readonly class ExtensionManifest
{
	/**
	 * @param string                                  $id             e.g. "vendor/extension-name"
	 * @param string                                  $name           Human-readable name
	 * @param string                                  $description    Short description
	 * @param string                                  $version        Semver version
	 * @param array<string,string>                    $requires       version constraints: totalcms, php, etc
	 * @param string                                  $entrypoint     Relative path to the ExtensionInterface class
	 * @param string|null                             $settingsSchema  Relative path to settings JSON schema
	 * @param string                                  $minEdition      Minimum edition required (lite, standard, pro)
	 * @param array<string,string>                    $author         Author info (name, url)
	 * @param string                                  $license        License identifier
	 * @param list<array{label: string, url: string}> $links          Card-level links (admin pages, docs, etc.)
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $description,
		public string $version,
		public array $requires,
		public string $entrypoint,
		public ?string $settingsSchema,
		public string $minEdition,
		public array $author,
		public string $license,
		public array $links = [],
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			id: (string)($data['id'] ?? ''),
			name: (string)($data['name'] ?? ''),
			description: (string)($data['description'] ?? ''),
			version: (string)($data['version'] ?? '0.0.0'),
			requires: is_array($data['requires'] ?? null) ? $data['requires'] : [],
			entrypoint: (string)($data['entrypoint'] ?? 'Extension.php'),
			settingsSchema: isset($data['settings_schema']) ? (string)$data['settings_schema'] : null,
			minEdition: (string)($data['min_edition'] ?? 'lite'),
			author: is_array($data['author'] ?? null) ? $data['author'] : [],
			license: (string)($data['license'] ?? 'proprietary'),
			links: self::parseLinks($data['links'] ?? null),
		);
	}

	/**
	 * Normalize the manifest's links field. Accepts a list of {label, url}
	 * objects; silently drops malformed entries.
	 *
	 * @return list<array{label: string, url: string}>
	 */
	private static function parseLinks(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [];
		}

		$links = [];
		foreach ($raw as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$label = (string)($entry['label'] ?? '');
			$url   = (string)($entry['url'] ?? '');
			if ($label === '' || $url === '') {
				continue;
			}
			$links[] = ['label' => $label, 'url' => $url];
		}

		return $links;
	}

	public function requiresTotalCmsVersion(): string
	{
		return (string)($this->requires['totalcms'] ?? '>=3.0.0');
	}

	public function requiresPhpVersion(): string
	{
		return (string)($this->requires['php'] ?? '>=8.2');
	}

	/**
	 * @return array<string,string> Extension ID => version constraint
	 */
	public function requiredExtensions(): array
	{
		$extensions = $this->requires['extensions'] ?? [];

		return is_array($extensions) ? $extensions : [];
	}

	public function vendor(): string
	{
		$parts = explode('/', $this->id, 2);

		return $parts[0];
	}

	public function shortName(): string
	{
		$parts = explode('/', $this->id, 2);

		return $parts[1] ?? $this->id;
	}
}
