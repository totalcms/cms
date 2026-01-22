<?php

namespace TotalCMS\Domain\Template\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Service\TemplateFactory;

/**
 * Repository.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class TemplateRepository extends StorageRepository
{
	public const RESERVED_TEMPLATE_DIR    = __DIR__ . '/../../../../resources/templates/';
	public const FILE_EXT                 = '.twig';
	public const CUSTOM_TEMPLATE_DIR      = 'templates/';

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
		$basePath = self::CUSTOM_TEMPLATE_DIR;

		if ($folder !== null && $folder !== '') {
			// Sanitize folder path to prevent directory traversal
			$folder = str_replace(['..', '\\'], ['', '/'], $folder);
			$folder = trim($folder, '/');
			$basePath .= $folder . '/';
		}

		return $basePath . $template . self::FILE_EXT;
	}

	/**
	 * generate a reserved template path.
	 */
	public function reservedPath(string $template): string
	{
		return self::RESERVED_TEMPLATE_DIR . $template . self::FILE_EXT;
	}

	/**
	 * test if a template exists.
	 *
	 * @throws \DomainException
	 */
	public function templateExists(string $template): bool
	{
		return $this->reservedTemplateExists($template) || $this->customTemplateExists($template);
	}

	/**
	 * test if a custom template exists.
	 *
	 * @throws \DomainException
	 */
	public function customTemplateExists(string $template, ?string $folder = null): bool
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
		$templateData = $this->fetchCustomTemplate($template, $folder) ?? $this->fetchReservedTemplate($template);

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
		$cacheKey = 'reserved:' . $template;

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
	public function fetchCustomTemplate(string $template, ?string $folder = null): ?TemplateData
	{
		$cacheKey = 'custom:' . ($folder ?? '') . ':' . $template;

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
		$this->requestCache[$cacheKey] = TemplateFactory::generateTemplate($template, $contents);

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
		$cacheKey = 'custom:' . ($folder ?? '') . ':' . $template->id;
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
			$cacheKey = 'custom:' . ($folder ?? '') . ':' . $template;
			unset($this->requestCache[$cacheKey]);
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
	public function listCustomTemplates(?string $folder = null, bool $recursive = false): array
	{
		$basePath = self::CUSTOM_TEMPLATE_DIR;

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
				if ($item->isFile() && str_ends_with($item->path(), self::FILE_EXT)) {
					// Remove base path and .twig extension
					$relativePath = str_replace(self::CUSTOM_TEMPLATE_DIR, '', $item->path());
					$files[]      = substr($relativePath, 0, -strlen(self::FILE_EXT));
				}
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
		$files = glob(self::RESERVED_TEMPLATE_DIR . '*' . self::FILE_EXT);

		if ($files === false) {
			throw new \RuntimeException('Failed to list reserved templates');
		}

		return array_map(fn (string $file): string => basename($file, self::FILE_EXT), $files);
	}
}
