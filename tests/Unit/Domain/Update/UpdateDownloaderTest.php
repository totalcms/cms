<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Update\Service\UpdateDownloader;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

final class UpdateDownloaderTest extends TestCase
{
	private string $tmpDir;
	private \PHPUnit\Framework\MockObject\MockObject $httpClient;
	private \PHPUnit\Framework\MockObject\MockObject $config;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-update-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);

		$this->httpClient       = $this->createMock(HttpClientInterface::class);
		$this->config           = $this->createMock(Config::class);
		$this->config->cachedir = $this->tmpDir;
		$this->config->domain   = 'example.com';
	}

	protected function tearDown(): void
	{
		// Clean up temp files
		$files = glob($this->tmpDir . '/*');
		if ($files !== false) {
			foreach ($files as $file) {
				@unlink($file);
			}
		}
		@rmdir($this->tmpDir);
	}

	public function testDownloadSuccess(): void
	{
		// Create a real zip file in the temp dir
		$zipPath = $this->tmpDir . '/test.zip';
		$zip     = new \ZipArchive();
		$zip->open($zipPath, \ZipArchive::CREATE);
		$zip->addFromString('test.txt', 'hello');
		$zip->close();
		$zipContent = (string)file_get_contents($zipPath);
		unlink($zipPath);

		$this->httpClient->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use ($zipContent): HttpResponse {
				expect($method)->toBe('GET');
				expect($url)->toContain('/version/download/3.3.0');

				// Write zip content to the sink path
				if (isset($options['sink'])) {
					file_put_contents($options['sink'], $zipContent);
				}

				return new HttpResponse(200, $zipContent);
			}
		);

		$downloader = new UpdateDownloader($this->httpClient, $this->config);
		$result     = $downloader->download('3.3.0', '/version/download/3.3.0');

		expect($result)->toContain('update-3.3.0.zip');
		expect(file_exists($result))->toBeTrue();

		@unlink($result);
	}

	public function testDownloadFailsOnHttpError(): void
	{
		$this->httpClient->method('request')->willReturn(new HttpResponse(403, 'Forbidden'));

		$downloader = new UpdateDownloader($this->httpClient, $this->config);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Download failed (HTTP 403)');

		$downloader->download('3.3.0', '/version/download/3.3.0');
	}

	public function testDownloadFailsOnEmptyFile(): void
	{
		$this->httpClient->method('request')->willReturnCallback(
			function (string $method, string $url, array $options): HttpResponse {
				// Don't write anything to sink — empty file
				if (isset($options['sink'])) {
					file_put_contents($options['sink'], '');
				}

				return new HttpResponse(200, '');
			}
		);

		$downloader = new UpdateDownloader($this->httpClient, $this->config);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('empty or missing');

		$downloader->download('3.3.0', '/version/download/3.3.0');
	}

	public function testDownloadFailsOnInvalidZip(): void
	{
		$this->httpClient->method('request')->willReturnCallback(
			function (string $method, string $url, array $options): HttpResponse {
				if (isset($options['sink'])) {
					file_put_contents($options['sink'], 'not a zip file');
				}

				return new HttpResponse(200, 'not a zip file');
			}
		);

		$downloader = new UpdateDownloader($this->httpClient, $this->config);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not a valid zip');

		$downloader->download('3.3.0', '/version/download/3.3.0');
	}

	public function testDownloadIncludesLicenseHeaders(): void
	{
		$zipPath = $this->tmpDir . '/test.zip';
		$zip     = new \ZipArchive();
		$zip->open($zipPath, \ZipArchive::CREATE);
		$zip->addFromString('test.txt', 'hello');
		$zip->close();
		$zipContent = (string)file_get_contents($zipPath);
		unlink($zipPath);

		$this->httpClient->expects($this->once())->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use ($zipContent): HttpResponse {
				// Verify license headers are sent
				expect($options['headers'])->toBeArray();
				$headerStr = implode("\n", $options['headers']);
				expect($headerStr)->toContain('X-License-Domain: example.com');
				expect($options['timeout'])->toBe(300);
				expect($options['follow_redirects'])->toBe(5);

				if (isset($options['sink'])) {
					file_put_contents($options['sink'], $zipContent);
				}

				return new HttpResponse(200, $zipContent);
			}
		);

		$downloader = new UpdateDownloader($this->httpClient, $this->config);
		$result     = $downloader->download('3.3.0', '/version/download/3.3.0');

		@unlink($result);
	}
}
