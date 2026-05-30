<?php declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;

class SitemapIndex extends Collection
{
    public function type(): string
    {
        return Sitemap::class;
    }

    public function accept(DriverInterface $driver): void
    {
        $driver->visitSitemapIndex($this);
    }
}
