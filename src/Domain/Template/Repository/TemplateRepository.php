<?php

namespace TotalCMS\Domain\Template\Repository;

use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Service\TemplateFactory;

/**
 * Repository.
 */
final class TemplateRepository extends StorageRepository
{
    public const RESERVED_TEMPLATE_DIR    = __DIR__ . '/../../../../templates/';
    public const FILE_EXT                 = '.twig';
    public const CUSTOM_TEMPLATE_DIR      = 'templates/';

    /**
     * generate a custom template path.
     *
     * @param string $template
     *
     * @return string
     */
    public function customPath(string $template): string
    {
        return self::CUSTOM_TEMPLATE_DIR . $template . self::FILE_EXT;
    }

    /**
     * generate a reserved template path.
     *
     * @param string $template
     *
     * @return string
     */
    public function reservedPath(string $template): string
    {
        return self::RESERVED_TEMPLATE_DIR . $template . self::FILE_EXT;
    }

    /**
     * test if a template exists.
     *
     * @param string $template
     *
     * @throws \DomainException
     *
     * @return bool
     */
    public function templateExists(string $template): bool
    {
        return $this->reservedTemplateExists($template) || $this->customTemplateExists($template);
    }

    /**
     * test if a custom template exists.
     *
     * @param string $template
     *
     * @throws \DomainException
     *
     * @return bool
     */
    public function customTemplateExists(string $template): bool
    {
        return $this->filesystem->fileExists($this->customPath($template));
    }

    /**
     * test if a reserved template exists.
     *
     * @param string $template
     *
     * @throws \DomainException
     *
     * @return bool
     */
    public function reservedTemplateExists(string $template): bool
    {
        return file_exists($this->reservedPath($template));
    }

    /**
     * fetch a template.
     *
     * @param string $template
     *
     * @throws \DomainException
     *
     * @return TemplateData
     */
    public function fetchTemplate(string $template): TemplateData
    {
        // Custom template takes precedence
        $templateData = $this->fetchCustomTemplate($template) ?? $this->fetchReservedTemplate($template);

        if ($templateData === null) {
            throw new \DomainException(sprintf('Template "%s" not found', $template));
        }

        return $templateData;
    }

    /**
     * fetch a reserved template.
     *
     * @param string $template
     *
     * @return ?TemplateData
     */
    public function fetchReservedTemplate(string $template): ?TemplateData
    {
        $templateFile = $this->reservedPath($template);
        $contents     = null;

        if (file_exists($templateFile)) {
            $contents = file_get_contents($templateFile);
        }

        if (empty($contents)) {
            return null;
        }

        return TemplateFactory::generateTemplate($template, $contents);
    }

    /**
     * fetch a custom template.
     *
     * @param string $template
     *
     * @return ?TemplateData
     */
    public function fetchCustomTemplate(string $template): ?TemplateData
    {
        $templateFile = $this->customPath($template);
        $contents     = null;

        if ($this->filesystem->fileExists($templateFile)) {
            $contents = $this->filesystem->read($templateFile);
        }

        if (empty($contents)) {
            return null;
        }

        return TemplateFactory::generateTemplate($template, $contents);
    }

    /**
     * save a template.
     *
     * @param TemplateData $template
     *
     * @return void
     */
    public function saveTemplate(TemplateData $template): void
    {
        $templateFile = $this->customPath($template->id);

        $this->filesystem->write($templateFile, $template->contents);
    }

    /**
     * delete a template.
     *
     * @param string $template
     *
     * @return bool
     */
    public function deleteTemplate(string $template): bool
    {
        $templateFile = $this->customPath($template);

        return $this->filesystem->delete($templateFile);
    }

    /**
     * List custom templates.
     *
     * @return array
     */
    public function listCustomTemplates(): array
    {
        $files = $this->filesystem->listFiles(self::CUSTOM_TEMPLATE_DIR);

        return array_map(function (string $file) {
            return basename($file, self::FILE_EXT);
        }, $files);
    }

    /**
     * List reserved templates.
     *
     * @return array
     */
    public function listReservedTemplates(): array
    {
        $files = glob(self::RESERVED_TEMPLATE_DIR . '*' . self::FILE_EXT);

        return array_map(function (string $file) {
            return basename($file, self::FILE_EXT);
        }, $files);
    }
}
