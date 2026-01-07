<?php

namespace Tests\Unit\Domain\Sitemap\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Sitemap\Data\SitemapIndex;

final class SitemapIndexTest extends TestCase
{
	public function testConstructorCreatesEmptyIndex(): void
	{
		$index = new SitemapIndex();

		$xml = $index->toXML();
		$this->assertStringContainsString('<?xml', $xml);
		$this->assertStringContainsString('sitemapindex', $xml);
	}

	public function testAddSitemapWithValidHttpUrl(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('http://example.com/sitemap.xml');

		$xml = $index->toXML();
		$this->assertStringContainsString('http://example.com/sitemap.xml', $xml);
	}

	public function testAddSitemapWithValidHttpsUrl(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('https://example.com/sitemap.xml');

		$xml = $index->toXML();
		$this->assertStringContainsString('https://example.com/sitemap.xml', $xml);
	}

	public function testAddSitemapIgnoresInvalidUrl(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('/relative/path.xml');

		$xml = $index->toXML();
		$this->assertStringNotContainsString('/relative/path.xml', $xml);
	}

	public function testAddSitemapIgnoresEmptyUrl(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('');

		// Should not throw exception
		$xml = $index->toXML();
		$this->assertStringContainsString('sitemapindex', $xml);
	}

	public function testAddSitemapTrimsWhitespace(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('  https://example.com/sitemap.xml  ');

		$xml = $index->toXML();
		$this->assertStringContainsString('https://example.com/sitemap.xml', $xml);
	}

	public function testAddMultipleSitemaps(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('https://example.com/sitemap1.xml');
		$index->addSitemap('https://example.com/sitemap2.xml');
		$index->addSitemap('https://example.com/sitemap3.xml');

		$xml = $index->toXML();
		$this->assertStringContainsString('sitemap1.xml', $xml);
		$this->assertStringContainsString('sitemap2.xml', $xml);
		$this->assertStringContainsString('sitemap3.xml', $xml);
	}

	public function testToStringReturnsXML(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('https://example.com/sitemap.xml');

		$this->assertSame($index->toXML(), (string)$index);
	}

	public function testToXMLReturnsValidXML(): void
	{
		$index = new SitemapIndex();
		$index->addSitemap('https://example.com/sitemap.xml');

		$xml = $index->toXML();

		// Should be valid XML
		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($xml));
	}
}
