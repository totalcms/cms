<?php

namespace TotalCMS\Domain\Sitemap\Data;

use TotalCMS\Domain\Sitemap\Lib\Drivers\XmlWriterDriver;
use TotalCMS\Domain\Sitemap\Lib\Sitemap as SitemapBase;
use TotalCMS\Domain\Sitemap\Lib\SitemapIndex as SitemapIndexBase;

readonly class SitemapIndex implements \Stringable
{
	private SitemapIndexBase $index;

	public function __construct()
	{
		$this->index = new SitemapIndexBase();
	}

	public function addSitemap(string $url): void
	{
		$url = trim($url);
		if (!str_starts_with($url, 'http')) {
			return;
		}

		// Sitemap entry.
		$sitemap = new SitemapBase($url);
		$this->index->add($sitemap);
	}

	public function toXML(): string
	{
		$driver = new XmlWriterDriver();
		$this->index->accept($driver);

		return $driver->output();
	}

	public function __toString(): string
	{
		return $this->toXML();
	}
}
