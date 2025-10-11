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
		$templateFile = $this->reservedPath($template);
		$contents     = null;

		if (file_exists($templateFile)) {
			$contents = file_get_contents($templateFile);
		}

		if ($contents === '' || $contents === null || $contents === false) {
			return null;
		}

		return TemplateFactory::generateTemplate($template, $contents);
	}

	/**
	 * fetch a custom template.
	 */
	public function fetchCustomTemplate(string $template, ?string $folder = null): ?TemplateData
	{
		$templateFile = $this->customPath($template, $folder);
		$contents     = null;

		if ($this->filesystem->fileExists($templateFile)) {
			$contents = $this->filesystem->read($templateFile);
		}

		if ($contents === null || $contents === '') {
			return null;
		}

		return TemplateFactory::generateTemplate($template, $contents);
	}

	/**
	 * save a template.
	 */
	public function saveTemplate(TemplateData $template, ?string $folder = null): void
	{
		$templateFile = $this->customPath($template->id, $folder);

		$this->filesystem->write($templateFile, $template->contents);
	}

	/**
	 * delete a template.
	 */
	public function deleteTemplate(string $template, ?string $folder = null): bool
	{
		$templateFile = $this->customPath($template, $folder);

		return $this->filesystem->delete($templateFile);
	}

	/**
	 * List custom templates.
	 *
	 * @return array<string>
	 */
	public function listCustomTemplates(?string $folder = null): array
	{
		$basePath = self::CUSTOM_TEMPLATE_DIR;

		if ($folder !== null && $folder !== '') {
			// Sanitize folder path to prevent directory traversal
			$folder = str_replace(['..', '\\'], ['', '/'], $folder);
			$folder = trim($folder, '/');
			$basePath .= $folder . '/';
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
