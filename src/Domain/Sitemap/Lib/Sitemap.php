<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib;

use TotalCMS\Domain\Sitemap\Lib\Interfaces\DriverInterface;
use TotalCMS\Domain\Sitemap\Lib\Interfaces\VisitorInterface;

class Sitemap implements VisitorInterface
{
	/**
	 * Last modified time.
	 */
	private ?\DateTimeInterface $lastMod = null;

	public function __construct(
		/**
		 * Location (URL).
		 */
		private readonly string $loc,
	) {
	}

	public function getLoc(): string
	{
		return $this->loc;
	}

	public function getLastMod(): ?\DateTimeInterface
	{
		return $this->lastMod;
	}

	public function setLastMod(\DateTimeInterface $lastMod): void
	{
		$this->lastMod = $lastMod;
	}

	public function accept(DriverInterface $driver): void
	{
		$driver->visitSitemap($this);
	}
}
