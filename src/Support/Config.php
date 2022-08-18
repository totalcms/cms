<?php

namespace App\Support;

/**
 * Config.
 */
final class Config
{
    public string $template = '';
    public string $dataDir  = '';
    public array $logger    = [];
    public array $error     = [];

    public function __construct(array $settings)
    {
        $this->template = $settings['template'];
        $this->dataDir  = $settings['datadir'];
        $this->logger   = $settings['logger'];
        $this->error    = $settings['error'];
    }
}
