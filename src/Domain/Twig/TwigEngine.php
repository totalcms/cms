<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Extension\DebugExtension;
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
        $debug             = $cacheDir === false ? true : false;                        // enable debug is no cache dir

        $loader     = new TwigFilesystemLoader($internalTemplates, $customTemplates);
        $this->twig = new TwigEnvironment($loader, [
            'cache' => $cacheDir,
            'debug' => $debug,
        ]);
        $this->twig->addExtension($extension);
        if ($debug) {
            $this->twig->addExtension(new DebugExtension());
        }
    }

    public function render(string $templateName, array $data = []): string
    {
        try {
            $string = $this->twig->render($templateName, $data);
        } catch (\Exception $e) {
            // Handle exception
            $string = '<!--' . $e->getMessage() . '-->';
        }

        return $string;
    }

    public function renderString(string $template, array $data = []): string
    {
        $twig = $this->twig->createTemplate($template);

        return $twig->render($data);
    }
}
