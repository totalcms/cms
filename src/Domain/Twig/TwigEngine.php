<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * Twig template processor.
 */
final class TwigEngine
{
    private TwigEnvironment $twig;

    public function __construct(Config $config, TotalCMSTwigExtension $extension)
    {
        $internalTemplates = TemplateRepository::DEFAULT_TEMPLATE_DIR;
        $customTemplates   = $config->dataDir . '/' . TemplateRepository::CUSTOM_TEMPLATE_DIR;
        $cacheDir          = $config->cacheDir === 'false' ? false : $config->cacheDir;

        $loader     = new TwigFilesystemLoader($internalTemplates, $customTemplates);
        $this->twig = new TwigEnvironment($loader, ['cache' => $cacheDir]);
        $this->twig->addExtension($extension);
    }

    public function render(string $templateName, array $data = []): string
    {
        return $this->twig->render($templateName, $data);
    }

    public function renderString(string $template, array $data = []): string
    {
        $twig = $this->twig->createTemplate($template);

        return $twig->render($data);
    }
}
