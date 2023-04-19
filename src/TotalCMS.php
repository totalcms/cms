<?php

namespace TotalCMS;

use DI\Container;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Twig\TwigEngine;

// ---------------------------------------------------------------------------------
// Entry point for Total CMS PHP API
// ---------------------------------------------------------------------------------
class TotalCMS
{
    private BufferController $buffer;
    private Container $container;
    private TwigEngine $twigEngine;

    public function __construct()
    {
        // Build PHP-DI Container instance
        $this->container = new Container(require __DIR__ . '/../config/container.php');

        $this->buffer     = $this->container->get(BufferController::class);
        $this->twigEngine = $this->container->get(TwigEngine::class);
    }

    public function startBuffer(): void
    {
        $this->buffer->start();
    }

    public function endBuffer(): void
    {
        $this->buffer->end();
    }

    public function processBufferMacros(): string
    {
        $content = $this->buffer->end();

        return $this->twigEngine->renderString($content);
    }

    public function processMacros(string $content): string
    {
        return $this->twigEngine->render($content);
    }
}
