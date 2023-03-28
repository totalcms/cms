<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final class TemplateSaver
{
    private TemplateRepository $storage;

    public function __construct(TemplateRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Save a Template.
     *
     * @param string $name
     * @param string $contents
     *
     * @return TemplateData
     */
    public function saveTemplate(string $name, string $contents): TemplateData
    {
        $template = TemplateFactory::generateTemplate($name, $contents);

        $this->storage->saveTemplate($template);

        return $template;
    }
}
