<?php

namespace Tests\Unit\Domain\Sitemap\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Sitemap\Data\Sitemap;
use TotalCMS\Domain\Sitemap\Data\SitemapLocation;

final class SitemapTest extends TestCase
{
	public function testConstructorCreatesEmptySitemap(): void
	{
		$sitemap = new Sitemap();

		$xml = $sitemap->toXML();
		$this->assertStringContainsString('<?xml', $xml);
		$this->assertStringContainsString('urlset', $xml);
	}

	public function testNewLocationReturnsLocationInstance(): void
	{
		$sitemap = new Sitemap();
		$location = $sitemap->newLocation('https://example.com/page');

		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testAddUrlAddsLocationToSitemap(): void
	{
		$sitemap = new Sitemap();
		$sitemap->addURL('https://example.com/page1');

		$xml = $sitemap->toXML();
		$this->assertStringContainsString('https://example.com/page1', $xml);
	}

	public function testAddMultipleUrls(): void
	{
		$sitemap = new Sitemap();
		$sitemap->addURL('https://example.com/page1');
		$sitemap->addURL('https://example.com/page2');
		$sitemap->addURL('https://example.com/page3');

		$xml = $sitemap->toXML();
		$this->assertStringContainsString('page1', $xml);
		$this->assertStringContainsString('page2', $xml);
		$this->assertStringContainsString('page3', $xml);
	}

	public function testAddUrlWithOptions(): void
	{
		$sitemap = new Sitemap();
		$sitemap->addURL('https://example.com/page', [
			'frequency' => 'weekly',
			'priority'  => '0.8',
		]);

		$xml = $sitemap->toXML();
		$this->assertStringContainsString('https://example.com/page', $xml);
		$this->assertStringContainsString('weekly', $xml);
	}

	public function testAddLocationFromInstance(): void
	{
		$sitemap = new Sitemap();
		$location = $sitemap->newLocation('https://example.com/custom');
		$sitemap->addLocation($location);

		$xml = $sitemap->toXML();
		$this->assertStringContainsString('https://example.com/custom', $xml);
	}

	public function testToStringReturnsXML(): void
	{
		$sitemap = new Sitemap();
		$sitemap->addURL('https://example.com');

		$this->assertSame($sitemap->toXML(), (string)$sitemap);
	}

	public function testToXMLReturnsValidXML(): void
	{
		$sitemap = new Sitemap();
		$sitemap->addURL('https://example.com/page');

		$xml = $sitemap->toXML();

		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($xml));
	}

	public function testImplementsStringable(): void
	{
		$sitemap = new Sitemap();

		$this->assertInstanceOf(\Stringable::class, $sitemap);
	}
}
