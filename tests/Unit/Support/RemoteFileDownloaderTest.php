<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;
use TotalCMS\Support\RefusedUrlException;
use TotalCMS\Support\RemoteFileDownloader;

// RemoteFileDownloader is the one way a URL becomes a local file: the admin
// upload-from-URL path, the importers and MCP all go through it, so the safety
// posture (public addresses only, the size cap, a sanitized filename in the
// configured temp dir) is decided once. Before it existed, each importer had
// its own copy with verification switched off and no cap.
final class RemoteFileDownloaderTest extends TestCase
{
	private string $tmpdir;

	protected function setUp(): void
	{
		$this->tmpdir = sys_get_temp_dir() . '/totalcms-downloader-' . uniqid();
	}

	protected function tearDown(): void
	{
		foreach (glob($this->tmpdir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->tmpdir);
	}

	private function config(int $maxDownloadSizeMb = 2048): Config
	{
		$config                  = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->tmpdir          = $this->tmpdir;
		$config->maxDownloadSize = $maxDownloadSizeMb;

		return $config;
	}

	/**
	 * A downloader whose HTTP is the given mock, reached through the public
	 * client with a stubbed public DNS answer.
	 */
	private function downloader(HttpClientInterface $http, int $maxDownloadSizeMb = 2048): RemoteFileDownloader
	{
		return new RemoteFileDownloader($this->config($maxDownloadSizeMb), stubPublicHttp($http));
	}

	public function testPassesTheOptionsAndCapsTheSize(): void
	{
		$captured = [];
		$http     = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): HttpResponse {
			$captured = $options;

			return new HttpResponse(200, 'bytes');
		});

		$path = $this->downloader($http, 50)->downloadPublic('https://example.test/img/photo.jpg', ['timeout' => 15, 'prefix' => 'rss-import', 'user_agent' => 'Acme/1.0']);

		$this->assertSame(15, $captured['timeout']);
		$this->assertSame('Acme/1.0', $captured['user_agent']);
		$this->assertSame(50 * 1024 * 1024 + 1, $captured['max_bytes']);
		$this->assertStringStartsWith($this->tmpdir . '/rss-import-', $path);
		$this->assertStringEndsWith('.jpg', $path);
		$this->assertSame('bytes', file_get_contents($path));
	}

	public function testKeepsTheUrlsFilenameWhenNoPrefixIsGiven(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturn(new HttpResponse(200, 'x'));

		$path = $this->downloader($http)->downloadPublic('https://example.test/a%20b/my photo (1).jpg?x=1');

		$this->assertSame($this->tmpdir . '/my_photo__1_.jpg', $path);
	}

	public function testGeneratesANameWhenTheUrlHasNoUsableFilename(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturn(new HttpResponse(200, 'x'));

		$path = $this->downloader($http)->downloadPublic('https://example.test/download/');

		$this->assertMatchesRegularExpression('#/downloaded_file_[0-9a-f]+\.tmp$#', $path);
	}

	public function testANonSuccessStatusIsAFailure(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturn(new HttpResponse(404, ''));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('HTTP error when downloading file: 404');

		$this->downloader($http)->downloadPublic('https://example.test/missing.jpg');
	}

	public function testTheSizeCapFailureIsReportedInMegabytes(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturn(new HttpResponse(200, str_repeat('x', 1024 * 1024 + 1)));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File exceeds maximum download size of 1 MB');

		$this->downloader($http, 1)->downloadPublic('https://example.test/huge.zip');
	}

	public function testAPrivateAddressIsRefused(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->never())->method('request');

		$this->expectException(RefusedUrlException::class);

		(new RemoteFileDownloader($this->config(), stubPublicHttp($http, ['internal.test' => ['10.0.0.5']])))
			->downloadPublic('https://internal.test/secret.jpg');
	}
}
