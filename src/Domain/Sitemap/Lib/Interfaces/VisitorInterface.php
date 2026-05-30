<?php declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib\Interfaces;

interface VisitorInterface
{
    public function accept(DriverInterface $driver);
}
