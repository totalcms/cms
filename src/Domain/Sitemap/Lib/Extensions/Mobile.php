<?php

namespace TotalCMS\Domain\Sitemap\Lib\Extensions;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;
use XMLWriter;

/**
 * Class Mobile
 *
 * @package TotalCMS\Domain\Sitemap\Lib\Subelements
 */
class Mobile implements VisitorInterface
{
    public function accept(DriverInterface $driver)
    {
        $driver->visitMobileExtension($this);
    }
}
