<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;
use TotalCMS\Support\RemoteFileDownloader;

// RemoteFileDownloader is the one way a URL becomes a local file: the admin
// upload-from-URL path and the RSS and WordPress importers all go through it,
// so the safety posture (certificate verification, the size cap, a sanitised
// filename in the configured temp dir) is decided once. Before it existed,
// each importer had its own copy with verification switched off and no cap.
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

	public function testVerifiesCertificatesAndCapsTheSizeOnEveryDownload(): void
	{
		$captured = [];
		$http     = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): HttpResponse {
			$captured = $options;

			return new HttpResponse(200, 'bytes');
		});

		$path = (new RemoteFileDownloader($http, $this->config(50)))->download('https://example.test/img/photo.jpg', ['timeout' => 15, 'prefix' => 'wp-import']);

		$this->assertTrue($captured['verify_ssl']);
		$this->assertSame(50 * 1024 * 1024, $captured['max_bytes']);
		$this->assertSame(15, $captured['timeout']);
		$this->assertSame(5, $captured['follow_redirects']);
		$this->assertStringStartsWith($this->tmpdir . '/wp-import-', $path);
		$this->assertStringEndsWith('.jpg', $path);
		$this->assertSame('bytes', file_get_contents($path));
	}

	public function testKeepsTheUrlsFilenameWhenNoPrefixIsGiven(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturn(new HttpResponse(200, 'x'));

		$path = (new RemoteFileDownloader($http, $this->config()))->download('https://example.test/a%20b/my photo (1).jpg?x=1');

		$this->assertSame($this->tmpdir . '/my_photo__1_.jpg', $path);
	}

	public function testGeneratesANameWhenTheUrlHasNoUsableFilename(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturn(new HttpResponse(200, 'x'));

		$path = (new RemoteFileDownloader($http, $this->config()))->download('https://example.test/download/');

		$this->assertMatchesRegularExpression('#/downloaded_file_[0-9a-f]+\.tmp$#', $path);
	}

	public function testANonSuccessStatusIsAFailure(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturn(new HttpResponse(404, ''));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('HTTP error when downloading file: 404');

		(new RemoteFileDownloader($http, $this->config()))->download('https://example.test/missing.jpg');
	}

	public function testTheSizeCapFailureIsReportedInMegabytes(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willThrowException(new \RuntimeException('Response exceeded maximum size'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File exceeds maximum download size of 10 MB');

		(new RemoteFileDownloader($http, $this->config(10)))->download('https://example.test/huge.zip');
	}

	public function testNoCapWhenMaxDownloadSizeIsZero(): void
	{
		$captured = [];
		$http     = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturnCallback(function (string $m, string $u, array $options) use (&$captured): HttpResponse {
			$captured = $options;

			return new HttpResponse(200, 'x');
		});

		(new RemoteFileDownloader($http, $this->config(0)))->download('https://example.test/a.jpg');

		$this->assertSame(0, $captured['max_bytes']);
	}
}
