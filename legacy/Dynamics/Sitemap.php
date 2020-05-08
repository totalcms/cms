<?php
namespace Dynamics;

use Dynamics\Dynamics;
use Dynamics\Settings;
use Dynamics\Controllers\CollectionsController;
use Monolog\Logger;
use Thepixeldeveloper\Sitemap\Urlset;
use Thepixeldeveloper\Sitemap\Url;
use Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;

//---------------------------------------------------------------------------------
// Dynamics Sitemap Utilities
//---------------------------------------------------------------------------------
class Sitemap
{
    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
        $this->urlset   = new Urlset();
    }

    public function addURL(string $url) : void
    {
        $url = new Url($url);
        // $url->setLastMod($lastMod);
        // $url->setChangeFreq($changeFreq);
        // $url->setPriority($priority);

        $this->urlset->add($url);
    }

    public function addCollection(string $collection, string $baseURL) : void
    {
        $controller = new CollectionsController($this->settings, $this->logger);
        $controller->setCollection($collection);
        foreach ($controller->getIndex() as $object) {
            $this->addURL($baseURL.$object["id"]);
        }
    }

    public function endBuffer() : void
    {
        // End all output buffers
        // XML validation does not like anything, even space before XML declaration
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    public function print() : void
    {
        $this->endBuffer();
        $driver = new XmlWriterDriver();
        $this->urlset->accept($driver);
        header('Content-type: application/xml');
        echo $driver->output();
    }
}
