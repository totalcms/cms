<?php

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Service.
 */
final class TemplateLister
{
    private TemplateRepository $storage;

    public function __construct(TemplateRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * List reserved templates.
     *
     * @return array
     */
    public function listReservedTemplates(): array
    {
        return $this->storage->listReservedTemplates();
    }

    /**
     * List custom templates.
     *
     * @return array
     */
    public function listCustomTemplates(): array
    {
        return $this->storage->listCustomTemplates();
    }

    /**
     * List all templates.
     *
     * @return array
     */
    public function listAllTemplates(): array
    {
        return array_merge($this->listReservedTemplates(), $this->listCustomTemplates());
    }
}
