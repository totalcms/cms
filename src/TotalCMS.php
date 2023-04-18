<?php

namespace TotalCMS;

use TotalCMS\Domain\Buffer\BufferController;

// ---------------------------------------------------------------------------------
// Entry point for Total CMS PHP API
// ---------------------------------------------------------------------------------
class TotalCMS
{
    private $buffer;

    public function __construct()
    {
        $this->buffer         = new BufferController();
        $this->templateEngine = new TemplateEngine();
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

        return $this->processMacros($content);
    }

    public function processMacros(string $content): string
    {
        return $this->templateEngine->render($content);
    }
}
