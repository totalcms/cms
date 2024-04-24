<?php

namespace TotalCMS\Support;

/**
 * Config.
 */
final class Config
{
    public string $template  = '';
    public string $assetsDir = '';
    public string $dataDir   = '';
    public string $cacheDir  = '';
    public string $tmpDir    = '';
    public string $domain    = '';
    public string $api       = '';
    public array $logger     = [];
    public array $error      = [];
    public array $imageworks = [];

    public function __construct(array $settings)
    {
        $this->template   = $settings['template'];
        $this->dataDir    = $settings['datadir'];
        $this->tmpDir     = $settings['tmpdir'];
        $this->cacheDir   = $settings['cachedir'];
        $this->logger     = $settings['logger'];
        $this->error      = $settings['error'];
        $this->imageworks = $settings['imageworks'];
        $this->domain     = $settings['domain'];
        $this->api        = $settings['api'];
        $this->assetsDir  = $settings['assetsDir'];
    }
}
