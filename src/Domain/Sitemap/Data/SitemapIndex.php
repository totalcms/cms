<?php

namespace TotalCMS\Domain\Sitemap\Data;

use Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;
use Thepixeldeveloper\Sitemap\Sitemap as SitemapBase;
use Thepixeldeveloper\Sitemap\SitemapIndex as SitemapIndexBase;

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
