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
	 * @param string                                  $reviewNote     Developer-authored note shown on the pre-enable review screen,
	 *                                                                explaining what the extension does and why it needs its capabilities
	 * @param string                                  $version        Semver version
	 * @param array<string,string>                    $requires       version constraints: totalcms, php, etc
	 * @param string                                  $entrypoint     Relative path to the ExtensionInterface class
	 * @param string|null                             $settingsSchema  Relative path to settings JSON schema
	 * @param string                                  $minEdition      Minimum edition required (lite, standard, pro)
	 * @param array<string,string>                    $author         Author info (name, url)
	 * @param string                                  $license        License identifier
	 * @param list<array{label: string, url: string}> $links          Card-level links (admin pages, docs, etc.)
	 * @param string                                  $icon           Relative path to icon image file
	 * @param bool                                    $bundled        True when shipped with the T3 package (in resources/extensions/)
	 *                                                                rather than installed by the user. Bundled extensions can be
	 *                                                                disabled but not removed. Set by ExtensionDiscovery — not declared
	 *                                                                in the manifest JSON.
	 * @param bool                                    $project        True when loaded from the project-level `extensions/` directory
	 *                                                                (next to tcms-data) rather than `tcms-data/extensions/`. Project
	 *                                                                extensions are site-owned source code — typically committed to the
	 *                                                                site's git repo — so they can be disabled but not removed through
	 *                                                                the CLI/admin. Set by ExtensionDiscovery — not declared in the
	 *                                                                manifest JSON.
	 * @param bool                                    $hidden         When true, the extension is excluded from the admin Extensions
	 *                                                                page and `extension:list` CLI output. It still loads, registers,
	 *                                                                and boots normally — it's just invisible to operators. Useful for
	 *                                                                bundled page-middleware that's enabled/disabled per page, not
	 *                                                                globally via the Extension Manager.
	 * @param bool                                    $defaultEnabled Declared in the manifest JSON as `default_enabled`.
	 *                                                                Only honoured for bundled extensions with no saved
	 *                                                                state — see ExtensionStateRepository::isEnabled().
	 *                                                                A third-party extension declaring this has no
	 *                                                                effect: bundled status is derived from discovery
	 *                                                                path, never from manifest JSON, so it cannot be
	 *                                                                self-declared.
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
		public string $icon = '',
		public bool $bundled = false,
		public bool $hidden = false,
		public string $reviewNote = '',
		public bool $project = false,
		public bool $defaultEnabled = false,
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
			icon: (string)($data['icon'] ?? ''),
			hidden: (bool)($data['hidden'] ?? false),
			reviewNote: (string)($data['reviewNote'] ?? ''),
			defaultEnabled: (bool)($data['default_enabled'] ?? false),
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

	/**
	 * Return a copy with the bundled flag set. Used by ExtensionDiscovery
	 * when it finds a manifest under `resources/extensions/` rather than
	 * the user's `tcms-data/extensions/`. Manifest JSON itself never declares
	 * `bundled` — it's derived from the discovery path.
	 */
	public function withBundled(bool $bundled): self
	{
		return new self(
			id: $this->id,
			name: $this->name,
			description: $this->description,
			version: $this->version,
			requires: $this->requires,
			entrypoint: $this->entrypoint,
			settingsSchema: $this->settingsSchema,
			minEdition: $this->minEdition,
			author: $this->author,
			license: $this->license,
			links: $this->links,
			icon: $this->icon,
			bundled: $bundled,
			hidden: $this->hidden,
			reviewNote: $this->reviewNote,
			project: $this->project,
			defaultEnabled: $this->defaultEnabled,
		);
	}

	/**
	 * Return a copy with the project flag set. Used by ExtensionDiscovery when
	 * it finds a manifest under the project-level `extensions/` directory
	 * rather than `tcms-data/extensions/`. Manifest JSON itself never declares
	 * `project` — it's derived from the discovery path.
	 */
	public function withProject(bool $project): self
	{
		return new self(
			id: $this->id,
			name: $this->name,
			description: $this->description,
			version: $this->version,
			requires: $this->requires,
			entrypoint: $this->entrypoint,
			settingsSchema: $this->settingsSchema,
			minEdition: $this->minEdition,
			author: $this->author,
			license: $this->license,
			links: $this->links,
			icon: $this->icon,
			bundled: $this->bundled,
			hidden: $this->hidden,
			reviewNote: $this->reviewNote,
			project: $project,
			defaultEnabled: $this->defaultEnabled,
		);
	}

	/**
	 * Return a copy with the version overridden. Used by ExtensionDiscovery
	 * to force bundled extensions to report the running T3 version — they
	 * ship in the package and can never have a different version than core,
	 * so any per-extension version in the manifest would be a fiction.
	 */
	public function withVersion(string $version): self
	{
		return new self(
			id: $this->id,
			name: $this->name,
			description: $this->description,
			version: $version,
			requires: $this->requires,
			entrypoint: $this->entrypoint,
			settingsSchema: $this->settingsSchema,
			minEdition: $this->minEdition,
			author: $this->author,
			license: $this->license,
			links: $this->links,
			icon: $this->icon,
			bundled: $this->bundled,
			hidden: $this->hidden,
			reviewNote: $this->reviewNote,
			project: $this->project,
			defaultEnabled: $this->defaultEnabled,
		);
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
