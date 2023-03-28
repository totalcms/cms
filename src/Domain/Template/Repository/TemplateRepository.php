<?php

namespace App\Domain\Template\Repository;

use App\Domain\Storage\StorageRepository;
use App\Domain\Template\Data\TemplateData;
use App\Domain\Template\Service\TemplateFactory;

/**
 * Repository.
 */
final class TemplateRepository extends StorageRepository
{
    public const DEFAULT_TEMPLATE_DIR    = __DIR__ . '/../../../../templates/';
    public const FILE_EXT                = '.twig';
    private const CUSTOM_TEMPLATE_DIR    = 'templates/';

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
        $templateData = $this->fetchCustomTemplate($template) ?? $this->fetchDefaultTemplate($template);

        if ($templateData === null) {
            throw new \DomainException(sprintf('Template "%s" not found', $template));
        }

        return $templateData;
    }

    /**
     * fetch a default template.
     *
     * @param string $template
     *
     * @return ?TemplateData
     */
    public function fetchDefaultTemplate(string $template): ?TemplateData
    {
        $templateFile = self::DEFAULT_TEMPLATE_DIR . $template . self::FILE_EXT;
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
        $templateFile = self::CUSTOM_TEMPLATE_DIR . $template . self::FILE_EXT;
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
        $templateFile = self::CUSTOM_TEMPLATE_DIR . $template->name . self::FILE_EXT;

        $this->filesystem->write($templateFile, $template->contents);
    }
}
