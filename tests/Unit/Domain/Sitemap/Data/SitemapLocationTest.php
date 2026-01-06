<?php

namespace Tests\Unit\Domain\Sitemap\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Sitemap\Data\SitemapLocation;
use Thepixeldeveloper\Sitemap\Url;

final class SitemapLocationTest extends TestCase
{
	public function testConstructorCreatesLocation(): void
	{
		$location = new SitemapLocation('https://example.com/page');

		$this->assertInstanceOf(Url::class, $location->location());
	}

	public function testConstructorTrimsWhitespace(): void
	{
		$location = new SitemapLocation('  https://example.com/page  ');

		$this->assertSame('https://example.com/page', $location->location()->getLoc());
	}

	public function testConstructorWithEmptyUrlUsesFallback(): void
	{
		$location = new SitemapLocation('');

		$this->assertSame('about:blank', $location->location()->getLoc());
	}

	public function testConstructorWithDateOption(): void
	{
		$location = new SitemapLocation('https://example.com', [
			'date' => '2024-01-15',
		]);

		$lastMod = $location->location()->getLastMod();
		$this->assertNotNull($lastMod);
	}

	public function testConstructorWithFrequencyOption(): void
	{
		$location = new SitemapLocation('https://example.com', [
			'frequency' => 'weekly',
		]);

		$this->assertSame('weekly', $location->location()->getChangeFreq());
	}

	public function testConstructorWithPriorityOption(): void
	{
		$location = new SitemapLocation('https://example.com', [
			'priority' => '0.8',
		]);

		$this->assertSame('0.8', $location->location()->getPriority());
	}

	public function testConstructorWithAllOptions(): void
	{
		$location = new SitemapLocation('https://example.com', [
			'date'      => '2024-06-01',
			'frequency' => 'daily',
			'priority'  => '1.0',
		]);

		$url = $location->location();
		$this->assertSame('daily', $url->getChangeFreq());
		$this->assertSame('1.0', $url->getPriority());
		$this->assertNotNull($url->getLastMod());
	}

	public function testAddImage(): void
	{
		$location = new SitemapLocation('https://example.com/page');
		$location->addImage('https://example.com/image.jpg');

		// Image is added as extension - verify it doesn't throw
		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testAddImageIgnoresEmptyUrl(): void
	{
		$location = new SitemapLocation('https://example.com/page');
		$location->addImage('');
		$location->addImage('   ');

		// Should not throw exception
		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testAddLink(): void
	{
		$location = new SitemapLocation('https://example.com/page');
		$location->addLink('en', 'https://example.com/en/page');

		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testAddVideo(): void
	{
		$location = new SitemapLocation('https://example.com/page');
		$location->addVideo(
			'https://example.com/video.mp4',
			'https://example.com/thumb.jpg',
			'Video Title',
			'Video description'
		);

		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testAddVideoWithDuration(): void
	{
		$location = new SitemapLocation('https://example.com/page');
		$location->addVideo(
			'https://example.com/video.mp4',
			'https://example.com/thumb.jpg',
			'Video Title',
			'Video description',
			120
		);

		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testAddHostedVideo(): void
	{
		$location = new SitemapLocation('https://example.com/page');
		$location->addHostedVideo(
			'https://youtube.com/embed/abc123',
			'https://example.com/thumb.jpg',
			'Hosted Video',
			'Hosted video description'
		);

		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testAddHostedVideoWithDuration(): void
	{
		$location = new SitemapLocation('https://example.com/page');
		$location->addHostedVideo(
			'https://youtube.com/embed/abc123',
			'https://example.com/thumb.jpg',
			'Hosted Video',
			'Hosted video description',
			300
		);

		$this->assertInstanceOf(SitemapLocation::class, $location);
	}

	public function testLocationReturnsUrlInstance(): void
	{
		$location = new SitemapLocation('https://example.com/test');

		$url = $location->location();

		$this->assertInstanceOf(Url::class, $url);
		$this->assertSame('https://example.com/test', $url->getLoc());
	}
}
