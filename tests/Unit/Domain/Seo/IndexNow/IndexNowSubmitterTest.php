<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Seo\IndexNow;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\IndexNow\IndexNowSubmitter;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * The submitter owns the protocol: the payload shape, the endpoint, which
 * statuses mean accepted / rejected / retry, and the rule that only this
 * site's URLs go out. A recording HTTP fake pins all of it.
 */
final class IndexNowSubmitterTest extends TestCase
{
	/** @var list<array{method:string,url:string,options:array<string,mixed>}> */
	private array $requests = [];

	private function submitter(array $settings, int $status = 200, string $body = ''): IndexNowSubmitter
	{
		$loader = $this->createMock(SeoSettingsLoader::class);
		$loader->method('load')->willReturn(SeoSettings::fromArray($settings, 'example.com'));

		$requests = &$this->requests;
		$http     = new class($requests, $status, $body) implements HttpClientInterface {
			/** @param list<array{method:string,url:string,options:array<string,mixed>}> $requests */
			public function __construct(private array &$requests, private int $status, private string $body)
			{
			}

			/** @param array<string,mixed> $options */
			public function request(string $method, string $url, array $options = []): HttpResponse
			{
				$this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

				return new HttpResponse($this->status, $this->body);
			}
		};

		return new IndexNowSubmitter($http, $loader, new LoggerFactory(['level' => Level::Debug, 'test' => new NullLogger()]));
	}

	private const ON = ['baseUrl' => 'https://example.com', 'indexNowEnabled' => true, 'indexNowKey' => 'abc123def456'];

	public function testPostsTheProtocolPayloadToTheSharedEndpoint(): void
	{
		$accepted = $this->submitter(self::ON)->submit(['https://example.com/blog/hello', 'https://example.com/about']);

		$this->assertTrue($accepted);
		$this->assertCount(1, $this->requests);
		$this->assertSame('POST', $this->requests[0]['method']);
		$this->assertSame(IndexNowSubmitter::ENDPOINT, $this->requests[0]['url']);

		$sent = json_decode((string)$this->requests[0]['options']['body'], true);
		$this->assertSame([
			'host'        => 'example.com',
			'key'         => 'abc123def456',
			'keyLocation' => 'https://example.com/abc123def456.txt',
			'urlList'     => ['https://example.com/blog/hello', 'https://example.com/about'],
		], $sent);
		$this->assertSame('application/json; charset=utf-8', $this->requests[0]['options']['headers']['Content-Type']);
	}

	public function testAcceptsA202AsWell(): void
	{
		$this->assertTrue($this->submitter(self::ON, 202)->submit(['https://example.com/a']));
	}

	public function testSendsNothingWhenDisabledOrWithoutAKey(): void
	{
		$this->assertFalse($this->submitter(['baseUrl' => 'https://example.com', 'indexNowKey' => 'abc123def456'])->submit(['https://example.com/a']));
		$this->assertFalse($this->submitter(['baseUrl' => 'https://example.com', 'indexNowEnabled' => true])->submit(['https://example.com/a']));
		$this->assertSame([], $this->requests);
	}

	public function testOnlyThisSitesUrlsAreSubmittedAndDuplicatesCollapse(): void
	{
		$this->submitter(self::ON)->submit([
			'https://example.com/a',
			'https://EXAMPLE.com/a',
			'https://other.example.org/leak',
			'https://example.com/b',
		]);

		$sent = json_decode((string)$this->requests[0]['options']['body'], true);
		$this->assertSame(['https://example.com/a', 'https://example.com/b'], $sent['urlList']);
	}

	public function testAnEmptyListAfterFilteringSendsNothing(): void
	{
		$this->assertFalse($this->submitter(self::ON)->submit(['https://other.example.org/x']));
		$this->assertSame([], $this->requests);
	}

	public function testARejectionIsFinalNotRetried(): void
	{
		// 422: the key file failed verification or a URL is malformed —
		// resending the same batch would fail identically.
		$this->assertFalse($this->submitter(self::ON, 422, 'Unprocessable')->submit(['https://example.com/a']));
	}

	public function testRateLimitAndServerErrorsThrowSoTheQueueRetries(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('IndexNow returned HTTP 429');

		$this->submitter(self::ON, 429)->submit(['https://example.com/a']);
	}

	public function testKeyLocationIsUnderTheBaseUrl(): void
	{
		$this->assertSame('https://example.com/abc123def456.txt', $this->submitter(self::ON)->keyLocation());
	}
}
