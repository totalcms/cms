<?php

namespace TotalCMS\Domain\Template\Repository;

use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Service\TemplateFactory;
use TotalCMS\Support\PathResolver;

/**
 * Reads/writes Site Builder + reserved templates. Pure native file I/O via
 * {@see BuilderTemplatePaths} (the read hierarchy + write target) — it does
 * NOT use the Flysystem-backed StorageRepository like the data repositories,
 * because Twig templates are always local files resolved by absolute path.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class TemplateRepository
{
	public const FILE_EXT            = '.twig';
	public const DESIGNER_META_EXT   = '.designer.json';
	public const BUILDER_DIR         = 'builder/';
	private const CACHE_KEY_BUILDER  = 'builder:';
	private const CACHE_KEY_RESERVED = 'reserved:';

	public const BUILDER_CATEGORIES = [
		'layouts',
		'pages',
		'partials',
		'macros',
		'templates',
		'whitelabel',
	];

	public static function reservedTemplateDir(): string
	{
		return PathResolver::packageRoot() . '/resources/templates/';
	}

	public function __construct(
		private readonly BuilderTemplatePaths $paths,
	) {
	}

	/**
	 * Request-level cache for templates.
	 *
	 * @var array<string,TemplateData|null>
	 */
	private array $requestCache = [];

	/**
	 * Builder-relative path for a template (e.g. `pages/about.twig`) — no
	 * `builder/` prefix. The shared sanitize step for every path builder; also
	 * what the layer resolver joins against each read layer.
	 */
	private function relativeTemplatePath(string $template, ?string $folder, string $ext): string
	{
		$rel = '';

		if ($folder !== null && $folder !== '') {
			// Sanitize folder path to prevent directory traversal
			$folder = str_replace(['..', '\\'], ['', '/'], $folder);
			$folder = trim($folder, '/');
			$rel .= $folder . '/';
		}

		return $rel . $template . $ext;
	}

	/**
	 * Fetch designer metadata for a template.
	 */
	public function fetchDesignerMeta(string $template, ?string $folder = null): ?DesignerMetadata
	{
		$resolved = $this->paths->resolveRead($this->relativeTemplatePath($template, $folder, self::DESIGNER_META_EXT));

		if ($resolved === null) {
			return null;
		}

		$contents = file_get_contents($resolved['path']);
		if ($contents === false) {
			return null;
		}

		/** @var array<string,mixed>|null $data */
		$data = json_decode($contents, true);

		if (!is_array($data)) {
			return null;
		}

		return DesignerMetadata::fromArray($data);
	}

	/**
	 * Save designer metadata companion file.
	 */
	public function saveDesignerMeta(string $template, ?string $folder, DesignerMetadata $meta): void
	{
		$json = json_encode($meta->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
		$this->writeBuilderFile($this->relativeTemplatePath($template, $folder, self::DESIGNER_META_EXT), $json);
	}

	/**
	 * Delete designer metadata companion file.
	 */
	public function deleteDesignerMeta(string $template, ?string $folder = null): void
	{
		$this->deleteBuilderFile($this->relativeTemplatePath($template, $folder, self::DESIGNER_META_EXT));
	}

	/**
	 * Write a builder-relative file to the active primary (write target), via
	 * absolute IO since the project layer can live outside the datadir-rooted
	 * storage adapter. Creates intermediate directories as needed.
	 */
	private function writeBuilderFile(string $relativePath, string $contents): void
	{
		$absolute = $this->paths->writePath($relativePath);
		$dir      = \dirname($absolute);
		if (!is_dir($dir)) {
			mkdir($dir, 0o775, true);
		}
		file_put_contents($absolute, $contents);
	}

	/**
	 * Delete a builder-relative file from the active primary (write target).
	 * Idempotent: returns true when the file is gone afterwards (whether it was
	 * just removed or never existed), mirroring the prior Flysystem delete
	 * (`!fileExists`). TemplateDeleteAction maps false to HTTP 500, so a no-op
	 * delete must still report success.
	 */
	private function deleteBuilderFile(string $relativePath): bool
	{
		$absolute = $this->paths->writePath($relativePath);
		if (is_file($absolute)) {
			unlink($absolute);
		}

		return !is_file($absolute);
	}

	/**
	 * generate a reserved template path.
	 */
	public function reservedPath(string $template): string
	{
		return self::reservedTemplateDir() . $template . self::FILE_EXT;
	}

	/**
	 * test if a template exists.
	 *
	 * @throws \DomainException
	 */
	public function templateExists(string $template): bool
	{
		return $this->reservedTemplateExists($template) || $this->builderTemplateExists($template);
	}

	/**
	 * test if a custom template exists.
	 *
	 * @throws \DomainException
	 */
	public function builderTemplateExists(string $template, ?string $folder = null): bool
	{
		return $this->paths->resolveRead($this->relativeTemplatePath($template, $folder, self::FILE_EXT)) !== null;
	}

	/**
	 * test if a reserved template exists.
	 *
	 * @throws \DomainException
	 */
	public function reservedTemplateExists(string $template): bool
	{
		return file_exists($this->reservedPath($template));
	}

	/**
	 * fetch a template.
	 *
	 * @throws \DomainException
	 */
	public function fetchTemplate(string $template, ?string $folder = null): TemplateData
	{
		// Custom template takes precedence
		$templateData = $this->fetchBuilderTemplate($template, $folder) ?? $this->fetchReservedTemplate($template);

		if (!$templateData instanceof TemplateData) {
			throw new \DomainException(sprintf('Template "%s" not found', $template));
		}

		return $templateData;
	}

	/**
	 * fetch a reserved template.
	 */
	public function fetchReservedTemplate(string $template): ?TemplateData
	{
		$cacheKey = self::CACHE_KEY_RESERVED . $template;

		if (array_key_exists($cacheKey, $this->requestCache)) {
			return $this->requestCache[$cacheKey];
		}

		$templateFile = $this->reservedPath($template);

		if (!file_exists($templateFile)) {
			$this->requestCache[$cacheKey] = null;

			return null;
		}

		$contents = file_get_contents($templateFile);

		if ($contents === false) {
			$this->requestCache[$cacheKey] = null;

			return null;
		}

		// Empty content is valid for templates
		$this->requestCache[$cacheKey] = TemplateFactory::generateTemplate($template, $contents);

		return $this->requestCache[$cacheKey];
	}

	/**
	 * fetch a custom template.
	 */
	public function fetchBuilderTemplate(string $template, ?string $folder = null): ?TemplateData
	{
		$cacheKey = self::CACHE_KEY_BUILDER . ($folder ?? '') . ':' . $template;

		if (array_key_exists($cacheKey, $this->requestCache)) {
			return $this->requestCache[$cacheKey];
		}

		// Walk the read hierarchy (project-root → tcms-data → built-in
		// defaults); the first layer that has the file wins.
		$resolved = $this->paths->resolveRead($this->relativeTemplatePath($template, $folder, self::FILE_EXT));

		if ($resolved === null) {
			$this->requestCache[$cacheKey] = null;

			return null;
		}

		$contents = file_get_contents($resolved['path']);
		if ($contents === false) {
			$this->requestCache[$cacheKey] = null;

			return null;
		}

		// Empty content is valid for templates - allows editing blank templates
		$templateData = TemplateFactory::generateTemplate($template, $contents);

		// Load designer metadata if companion file exists
		$designerMeta = $this->fetchDesignerMeta($template, $folder);
		if ($designerMeta instanceof DesignerMetadata) {
			$templateData->designer = $designerMeta;
		}

		$this->requestCache[$cacheKey] = $templateData;

		return $this->requestCache[$cacheKey];
	}

	/**
	 * save a template.
	 */
	public function saveTemplate(TemplateData $template, ?string $folder = null): void
	{
		$this->writeBuilderFile(
			$this->relativeTemplatePath($template->id, $folder, self::FILE_EXT),
			$template->contents,
		);

		// Invalidate cache for this template
		$cacheKey = self::CACHE_KEY_BUILDER . ($folder ?? '') . ':' . $template->id;
		unset($this->requestCache[$cacheKey]);
	}

	/**
	 * delete a template.
	 */
	public function deleteTemplate(string $template, ?string $folder = null): bool
	{
		$deleted = $this->deleteBuilderFile($this->relativeTemplatePath($template, $folder, self::FILE_EXT));

		// Invalidate cache for this template
		if ($deleted) {
			$cacheKey = self::CACHE_KEY_BUILDER . ($folder ?? '') . ':' . $template;
			unset($this->requestCache[$cacheKey]);

			// Also delete companion designer metadata file
			$this->deleteDesignerMeta($template, $folder);
		}

		return $deleted;
	}

	/**
	 * List custom templates.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @return array<string>
	 */
	public function listBuilderTemplates(?string $folder = null, bool $recursive = false): array
	{
		// Union the template names across every read layer (project-root →
		// tcms-data → built-in defaults), deduped — so a git-managed project's
		// templates appear in admin listings alongside any datadir leftovers.
		//
		// Collect into a flat list and dedupe with array_unique (NOT array keys):
		// a numeric template name like "404" would be coerced to int if used as a
		// key, which then breaks string ops (e.g. NestedFileTree's str_contains).
		$names = [];
		foreach ($this->paths->readLayers() as $layerDir) {
			foreach ($this->listLayerTemplates($layerDir, $folder, $recursive) as $name) {
				$names[] = $name;
			}
		}

		$files = array_values(array_unique($names));
		sort($files, SORT_STRING);

		return $files;
	}

	/**
	 * List template names within a single absolute layer directory, relative to
	 * the (optional) folder. History snapshots are excluded — they're version
	 * payloads, not editable templates, and their paths don't round-trip
	 * through `fetchBuilderTemplate()`.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @return array<string>
	 */
	private function listLayerTemplates(string $layerDir, ?string $folder, bool $recursive): array
	{
		$base = $layerDir;
		if ($folder !== null && $folder !== '') {
			$folder = trim(str_replace(['..', '\\'], ['', '/'], $folder), '/');
			$base .= '/' . $folder;
		}

		if (!is_dir($base)) {
			return [];
		}

		$names = [];

		if ($recursive) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
			);
			foreach ($iterator as $file) {
				if (!$file->isFile() || !str_ends_with((string)$file->getPathname(), self::FILE_EXT)) {
					continue;
				}
				$relativePath = substr((string)$file->getPathname(), strlen($base) + 1);
				if (str_starts_with($relativePath, '.history/')) {
					continue;
				}
				$names[] = substr($relativePath, 0, -strlen(self::FILE_EXT));
			}

			return $names;
		}

		$entries = scandir($base);
		foreach ($entries === false ? [] : $entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (is_file($base . '/' . $entry) && str_ends_with($entry, self::FILE_EXT)) {
				$names[] = basename($entry, self::FILE_EXT);
			}
		}

		return $names;
	}

	/**
	 * List reserved templates.
	 *
	 * @return array<string>
	 */
	public function listReservedTemplates(): array
	{
		$files = glob(self::reservedTemplateDir() . '*' . self::FILE_EXT);

		if ($files === false) {
			throw new \RuntimeException('Failed to list reserved templates');
		}

		return array_map(fn (string $file): string => basename($file, self::FILE_EXT), $files);
	}
}
