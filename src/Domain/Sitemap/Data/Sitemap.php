<?php

namespace TotalCMS\Domain\Sitemap\Data;

use Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;
use Thepixeldeveloper\Sitemap\Urlset;

final class Sitemap
{
	private Urlset $urlset;

	public function __construct()
	{
		$this->urlset = new Urlset();
	}

	/** @param array<string,string> $options */
	public function newLocation(string $url, array $options = []): SitemapLocation
	{
		$location = new SitemapLocation($url, $options);

		return $location;
	}

	public function addLocation(SitemapLocation $location): void
	{
		$this->urlset->add($location->location());
	}

	/** @param array<string,string> $options */
	public function addURL(string $url, array $options = []): void
	{
		$location = $this->newLocation($url, $options);
		$this->addLocation($location);
	}

	public function toXML(): string
	{
		$driver = new XmlWriterDriver();
		$this->urlset->accept($driver);

		return $driver->output();
	}

	public function __toString(): string
	{
		return $this->toXML();
	}
}
