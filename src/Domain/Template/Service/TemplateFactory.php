<?php

namespace App\Domain\Template\Service;

use App\Domain\Template\Data\TemplateData;

/**
 * Service.
 */
final class TemplateFactory
{
    /**
     * create a template object.
     *
     * @param string $name
     * @param string $template
     *
     * @return TemplateData
     */
    public static function generateTemplate(string $name, string $template): TemplateData
    {
        $templateData           = new TemplateData();
        $templateData->name     = $name;
        $templateData->contents = $template;

        return $templateData;
    }
}
