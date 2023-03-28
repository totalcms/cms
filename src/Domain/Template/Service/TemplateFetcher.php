<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final class TemplateFetcher
{
    private TemplateRepository $storage;

    public function __construct(TemplateRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * fetch a template.
     *
     * @param string $template
     *
     * @return TemplateData
     */
    public function fetchTemplate(string $template): TemplateData
    {
        return $this->storage->fetchTemplate($template);
    }
}
