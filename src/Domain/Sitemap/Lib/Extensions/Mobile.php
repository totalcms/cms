<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib\Extensions;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

/**
 * Class Mobile.
 */
class Mobile implements VisitorInterface
{
	public function accept(DriverInterface $driver): void
	{
		$driver->visitMobileExtension($this);
	}
}
