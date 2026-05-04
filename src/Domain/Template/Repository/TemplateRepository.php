<?php

namespace TotalCMS\Domain\Template\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Service\TemplateFactory;
use TotalCMS\Support\PathResolver;

/**
 * Repository.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class TemplateRepository extends StorageRepository
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

	/**
	 * Request-level cache for templates.
	 *
	 * @var array<string,TemplateData|null>
	 */
	private array $requestCache = [];

	/**
	 * Parse path into folder and template name.
	 *
	 * @return array{0: string|null, 1: string}
	 */
	public static function parsePath(string $path): array
	{
		$lastSlash = strrpos($path, '/');

		if ($lastSlash === false) {
			// No folder, just template name
			return [null, $path];
		}

		// Split into folder and template
		$folder   = substr($path, 0, $lastSlash);
		$template = substr($path, $lastSlash + 1);

		return [$folder, $template];
	}

	/**
	 * generate a custom template path.
	 */
	public function customPath(string $template, ?string $folder = null): string
	{
		$basePath = self::BUILDER_DIR;

		if ($folder !== null && $folder !== '') {
			// Sanitize folder path to prevent directory traversal
			$folder = str_replace(['..', '\\'], ['', '/'], $folder);
			$folder = trim($folder, '/');
			$basePath .= $folder . '/';
		}

		return $basePath . $template . self::FILE_EXT;
	}

	/**
	 * Generate a designer metadata companion file path.
	 */
	public function designerMetaPath(string $template, ?string $folder = null): string
	{
		$basePath = self::BUILDER_DIR;

		if ($folder !== null && $folder !== '') {
			$folder = str_replace(['..', '\\'], ['', '/'], $folder);
			$folder = trim($folder, '/');
			$basePath .= $folder . '/';
		}

		return $basePath . $template . self::DESIGNER_META_EXT;
	}

	/**
	 * Fetch designer metadata for a template.
	 */
	public function fetchDesignerMeta(string $template, ?string $folder = null): ?DesignerMetadata
	{
		$metaPath = $this->designerMetaPath($template, $folder);

		if (!$this->filesystem->fileExists($metaPath)) {
			return null;
		}

		$contents = $this->filesystem->read($metaPath);

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
		$metaPath = $this->designerMetaPath($template, $folder);
		$json     = json_encode($meta->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
		$this->filesystem->write($metaPath, $json);
	}

	/**
	 * Delete designer metadata companion file.
	 */
	public function deleteDesignerMeta(string $template, ?string $folder = null): void
	{
		$metaPath = $this->designerMetaPath($template, $folder);

		if ($this->filesystem->fileExists($metaPath)) {
			$this->filesystem->delete($metaPath);
		}
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
		return $this->filesystem->fileExists($this->customPath($template, $folder));
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

		$templateFile = $this->customPath($template, $folder);

		if (!$this->filesystem->fileExists($templateFile)) {
			$this->requestCache[$cacheKey] = null;

			return null;
		}

		$contents = $this->filesystem->read($templateFile);

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
		$templateFile = $this->customPath($template->id, $folder);

		$this->filesystem->write($templateFile, $template->contents);

		// Invalidate cache for this template
		$cacheKey = self::CACHE_KEY_BUILDER . ($folder ?? '') . ':' . $template->id;
		unset($this->requestCache[$cacheKey]);
	}

	/**
	 * delete a template.
	 */
	public function deleteTemplate(string $template, ?string $folder = null): bool
	{
		$templateFile = $this->customPath($template, $folder);

		$deleted = $this->filesystem->delete($templateFile);

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
		$basePath = self::BUILDER_DIR;

		if ($folder !== null && $folder !== '') {
			// Sanitize folder path to prevent directory traversal
			$folder = str_replace(['..', '\\'], ['', '/'], $folder);
			$folder = trim($folder, '/');
			$basePath .= $folder . '/';
		}

		if ($recursive) {
			// Use flysystem's listContents with recursive flag
			$contents = $this->filesystem->flysystem()->listContents($basePath, true);

			$files = [];
			foreach ($contents as $item) {
				if (!$item->isFile() || !str_ends_with($item->path(), self::FILE_EXT)) {
					continue;
				}
				$relativePath = substr($item->path(), strlen($basePath));
				// Skip TemplateSnapshotRepository's history snapshots — they're
				// stored as real .twig files but aren't editable templates,
				// they're version-history payloads. Surfacing them in admin
				// sidebars / quick-nav / pickers would be confusing and would
				// also break the editor since their paths don't round-trip
				// through `fetchBuilderTemplate()`.
				if (str_starts_with($relativePath, '.history/')) {
					continue;
				}
				$files[] = substr($relativePath, 0, -strlen(self::FILE_EXT));
			}

			// Sort alphabetically
			sort($files);

			return $files;
		}

		$files = $this->filesystem->listFiles($basePath);

		return array_map(fn (string $file): string => basename($file, self::FILE_EXT), $files);
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
