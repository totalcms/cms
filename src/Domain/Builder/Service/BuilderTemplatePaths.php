<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Single source of truth for the Site Builder template hierarchy: where
 * templates are read from (and in what precedence), where the admin/runtime
 * writes them, and whether editing is locked because they're managed by git.
 *
 * Read precedence (first match wins), site/builder templates only:
 *   1. <project-root>/builder   — committed, dev source of truth
 *   2. <datadir>/builder        — runtime / admin-edited
 *   3. <package>/resources/builder/defaults — shipped fallback (the floor)
 *
 * The admin writes to the "active primary": the project dir when it exists,
 * otherwise the datadir dir. This keeps the two editable layers effectively
 * mutually exclusive, so a git-first project never accrues a tcms-data
 * override layer and an admin-first project is byte-for-byte today's behavior.
 *
 * See docs/planning/builder-git-workflow.md.
 */
readonly class BuilderTemplatePaths
{
	public const LAYER_PROJECT = 'project';
	public const LAYER_DATA    = 'data';
	public const LAYER_BUILTIN = 'built-in';

	private const BUILDER_DIRNAME = 'builder';
	private const DEFAULTS_REL    = 'resources/builder/defaults';

	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * The project-level builder directory (may not exist). Defaults to
	 * `<project-root>/builder`; an operator may point it elsewhere via
	 * `builder.projectTemplates` (absolute, or relative to the project root).
	 */
	public function projectDir(): string
	{
		$override = $this->config->builder['projectTemplates'] ?? null;
		if (is_string($override) && $override !== '') {
			return str_starts_with($override, '/')
				? $override
				: PathResolver::projectRoot() . '/' . $override;
		}

		return PathResolver::projectRoot() . '/' . self::BUILDER_DIRNAME;
	}

	/**
	 * The datadir builder directory — today's location, and the fallback
	 * write target for admin-first projects.
	 */
	public function dataDir(): string
	{
		return $this->config->datadir . '/' . self::BUILDER_DIRNAME;
	}

	/**
	 * The shipped built-in defaults directory — the lowest-precedence read
	 * layer (the floor). Ships in Phase 4; absent until then.
	 */
	public function defaultsDir(): string
	{
		return PathResolver::packageRoot() . '/' . self::DEFAULTS_REL;
	}

	/**
	 * Whether the project's builder templates are git-managed — i.e. the
	 * project-level builder directory physically exists.
	 */
	public function isProjectManaged(): bool
	{
		return is_dir($this->projectDir());
	}

	/**
	 * The directory the admin/runtime writes templates into: the project dir
	 * when git-managed, otherwise the datadir dir.
	 */
	public function writeTarget(): string
	{
		return $this->isProjectManaged() ? $this->projectDir() : $this->dataDir();
	}

	/**
	 * The ordered read layers, highest precedence first, existing dirs only,
	 * each tagged with its source label.
	 *
	 * @return list<array{layer:string,dir:string}>
	 */
	public function readLayersLabeled(): array
	{
		$candidates = [
			[self::LAYER_PROJECT, $this->projectDir()],
			[self::LAYER_DATA, $this->dataDir()],
			[self::LAYER_BUILTIN, $this->defaultsDir()],
		];

		$layers = [];
		foreach ($candidates as [$layer, $dir]) {
			if (is_dir($dir)) {
				$layers[] = ['layer' => $layer, 'dir' => $dir];
			}
		}

		return $layers;
	}

	/**
	 * The ordered read layers, highest precedence first, existing dirs only.
	 *
	 * @return list<string>
	 */
	public function readLayers(): array
	{
		return array_map(static fn (array $l): string => $l['dir'], $this->readLayersLabeled());
	}

	/**
	 * Resolve a builder-relative path (e.g. `pages/about.twig`, no leading
	 * `builder/`) to the first existing file across the read layers, with the
	 * source layer it was found in. Null when absent everywhere.
	 *
	 * @return array{layer:string,path:string}|null
	 */
	public function resolveRead(string $relativePath): ?array
	{
		foreach ($this->readLayersLabeled() as $layer) {
			$candidate = $layer['dir'] . '/' . $relativePath;
			if (is_file($candidate)) {
				return ['layer' => $layer['layer'], 'path' => $candidate];
			}
		}

		return null;
	}

	/**
	 * Absolute file path a builder-relative path writes to, under the active
	 * primary (write target).
	 */
	public function writePath(string $relativePath): string
	{
		return $this->writeTarget() . '/' . $relativePath;
	}

	/**
	 * Absolute path list for the Twig loader: reserved admin templates first
	 * (protected, unshadowable), then the builder read layers.
	 *
	 * @return list<string>
	 */
	public function loaderPaths(): array
	{
		return [
			rtrim(TemplateRepository::reservedTemplateDir(), '/'),
			...$this->readLayers(),
		];
	}

	/**
	 * Whether admin/runtime template editing is locked. Explicit
	 * `builder.lockTemplates` wins; otherwise auto: locked only when the
	 * project is git-managed AND running in production. An admin-first site
	 * (nothing in git to protect) is never auto-locked.
	 */
	public function locked(): bool
	{
		$explicit = $this->config->builder['lockTemplates'] ?? null;
		if (is_bool($explicit)) {
			return $explicit;
		}

		return $this->isProjectManaged() && $this->config->env === 'prod';
	}
}
