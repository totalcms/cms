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

    public function processBufferMacros(array $data = []): string
    {
        $content = $this->buffer->end();

        try {
            return $this->twigEngine->renderString($content, $data);
        } catch (\Throwable $th) {
            return $th->getMessage() . ':' . $th->getTraceAsString();
        }
    }

    public function processMacros(string $templateName, array $data = []): string
    {
        try {
            return $this->twigEngine->render($templateName, $data);
        } catch (\Throwable $th) {
            // TODO: Handle exception
            // return $th->getMessage() . ':' . $th->getTraceAsString();
            return '';
        }
    }
}
