<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * Feed hosts behind Cloudflare 403 the HTTP library's default user-agent, so a
 * feed fetch has to say who it is. The transport already supported the option;
 * these cover the importer actually sending one.
 */
final class RssImporterUserAgentTest extends TestCase
{
	private const FEED = '<?xml version="1.0"?><rss version="2.0"><channel>'
		. '<title>Feed</title><link>https://example.com</link><description>d</description>'
		. '</channel></rss>';

	/** @var array<string,mixed> */
	private array $captured = [];

	private function importer(): RssImporter
	{
		$httpClient = $this->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willReturnCallback(function (string $method, string $url, array $options): HttpResponse {
				$this->captured = $options;

				return new HttpResponse(200, self::FEED);
			});

		$collectionFetcher = $this->createMock(CollectionFetcher::class);
		$collectionFetcher->method('collectionExists')->willReturn(true);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new \Psr\Log\NullLogger());

		return new RssImporter(
			$collectionFetcher,
			$this->createMock(ObjectFetcher::class),
			$this->createMock(JobQueuer::class),
			$httpClient,
			$loggerFactory
		);
	}

	public function testFeedFetchIdentifiesTotalCmsByDefault(): void
	{
		$this->importer()->analyze('https://example.com/feed.xml');

		$this->assertArrayHasKey('user_agent', $this->captured);
		$this->assertStringContainsString('TotalCMS', (string)$this->captured['user_agent']);
	}

	public function testTheDefaultIsNotABrowserImpersonation(): void
	{
		$this->importer()->analyze('https://example.com/feed.xml');

		$userAgent = (string)$this->captured['user_agent'];
		$this->assertStringNotContainsString('Mozilla', $userAgent);
		$this->assertStringContainsString('totalcms.co', $userAgent);
	}

	public function testAnExplicitUserAgentOverridesTheDefault(): void
	{
		$this->importer()->analyze('https://example.com/feed.xml', 'AcmeReader/1.0 (+https://acme.test)');

		$this->assertSame('AcmeReader/1.0 (+https://acme.test)', $this->captured['user_agent']);
	}

	public function testImportPassesItsUserAgentOptionThrough(): void
	{
		$this->importer()->import('https://example.com/feed.xml', 'blog', [
			'userAgent' => 'AcmeReader/1.0 (+https://acme.test)',
		]);

		$this->assertSame('AcmeReader/1.0 (+https://acme.test)', $this->captured['user_agent']);
	}

	public function testImportFallsBackToTheDefaultWhenNoneIsGiven(): void
	{
		$this->importer()->import('https://example.com/feed.xml', 'blog');

		$this->assertStringContainsString('TotalCMS', (string)$this->captured['user_agent']);
	}
}
