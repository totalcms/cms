<?php

namespace Tests\Unit\Infrastructure\Filesystem;

use PHPUnit\Framework\TestCase;
use TotalCMS\Infrastructure\Filesystem\MimeLookup;

final class MimeLookupTest extends TestCase
{
	public function testGetMimeTypeForCss(): void
	{
		$this->assertSame('text/css', MimeLookup::getMimeType('style.css'));
	}

	public function testGetMimeTypeForJs(): void
	{
		$this->assertSame('application/javascript', MimeLookup::getMimeType('app.js'));
	}

	public function testGetMimeTypeForHtml(): void
	{
		$this->assertSame('text/html', MimeLookup::getMimeType('index.html'));
	}

	public function testGetMimeTypeForJpg(): void
	{
		$this->assertSame('image/jpeg', MimeLookup::getMimeType('photo.jpg'));
	}

	public function testGetMimeTypeForJpeg(): void
	{
		$this->assertSame('image/jpeg', MimeLookup::getMimeType('photo.jpeg'));
	}

	public function testGetMimeTypeForPng(): void
	{
		$this->assertSame('image/png', MimeLookup::getMimeType('image.png'));
	}

	public function testGetMimeTypeForGif(): void
	{
		$this->assertSame('image/gif', MimeLookup::getMimeType('animation.gif'));
	}

	public function testGetMimeTypeForSvg(): void
	{
		$this->assertSame('image/svg+xml', MimeLookup::getMimeType('icon.svg'));
	}

	public function testGetMimeTypeForJson(): void
	{
		$this->assertSame('application/json', MimeLookup::getMimeType('data.json'));
	}

	public function testGetMimeTypeForXml(): void
	{
		$this->assertSame('application/xml', MimeLookup::getMimeType('config.xml'));
	}

	public function testGetMimeTypeForWoff(): void
	{
		$this->assertSame('font/woff', MimeLookup::getMimeType('font.woff'));
	}

	public function testGetMimeTypeForWoff2(): void
	{
		$this->assertSame('font/woff2', MimeLookup::getMimeType('font.woff2'));
	}

	public function testGetMimeTypeForTtf(): void
	{
		$this->assertSame('font/ttf', MimeLookup::getMimeType('font.ttf'));
	}

	public function testGetMimeTypeForOtf(): void
	{
		$this->assertSame('font/otf', MimeLookup::getMimeType('font.otf'));
	}

	public function testGetMimeTypeForEot(): void
	{
		$this->assertSame('application/vnd.ms-fontobject', MimeLookup::getMimeType('font.eot'));
	}

	public function testGetMimeTypeForMap(): void
	{
		$this->assertSame('application/json', MimeLookup::getMimeType('app.js.map'));
	}

	public function testGetMimeTypeIsCaseInsensitive(): void
	{
		$this->assertSame('text/css', MimeLookup::getMimeType('style.CSS'));
		$this->assertSame('image/png', MimeLookup::getMimeType('image.PNG'));
	}

	public function testGetMimeTypeWithPath(): void
	{
		$this->assertSame('text/css', MimeLookup::getMimeType('/path/to/style.css'));
		$this->assertSame('image/jpeg', MimeLookup::getMimeType('assets/images/photo.jpg'));
	}
}
