<?php declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;

class Urlset extends Collection
{
    public function type(): string
    {
        return Url::class;
    }

    public function accept(DriverInterface $driver): void
    {
        $driver->visitUrlset($this);
    }
}
