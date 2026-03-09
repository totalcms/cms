<?php

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\File\FileSaveAction;
use TotalCMS\Domain\Media\Service\HeicConverter;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

function createFileSaveAction(HttpClientInterface $httpClient, ?Config $config = null): FileSaveAction
{
	$renderer = test()->createMock(JsonRenderer::class);
	$renderer->method('json')->willReturnArgument(0);
	$renderer->method('jsonItem')->willReturnArgument(0);

	$factory = test()->createMock(SaverFactory::class);

	$heicConverter = test()->createMock(HeicConverter::class);
	$heicConverter->method('isHeicFile')->willReturn(false);

	if (!$config instanceof Config) {
		$config                  = test()->createMock(Config::class);
		$config->tmpdir          = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		$config->maxDownloadSize = 2048;
	}

	return new FileSaveAction($renderer, $factory, $config, $heicConverter, $httpClient);
}

function createDownloadRequest(string $url): ServerRequestInterface
{
	$request = test()->createMock(ServerRequestInterface::class);
	$request->method('getUploadedFiles')->willReturn([]);
	$request->method('getParsedBody')->willReturn(['testfile' => $url]);
	$request->method('getQueryParams')->willReturn([]);

	return $request;
}

describe('FileSaveAction URL Download', function (): void {
	test('downloads file from URL using HTTP client', function (): void {
		$fileContent = 'fake image binary content';
		$httpClient  = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'GET',
				'https://example.com/photo.jpg',
				test()->callback(fn (array $options): bool => ($options['timeout'] ?? 0) === 30
						&& ($options['user_agent'] ?? '') === 'TotalCMS File Downloader'
						&& ($options['verify_ssl'] ?? false) === true)
			)
			->willReturn(new HttpResponse(200, $fileContent));

		$action = createFileSaveAction($httpClient);

		// We need to test the private method indirectly via reflection
		$reflection = new ReflectionClass($action);
		$method     = $reflection->getMethod('downloadFileFromUrl');

		$result = $method->invoke($action, 'https://example.com/photo.jpg');

		expect($result)->toBeString();
		expect(file_exists($result))->toBeTrue();
		expect(file_get_contents($result))->toBe($fileContent);

		// Cleanup
		unlink($result);
	});

	test('throws exception on HTTP error status', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willReturn(new HttpResponse(404, 'Not Found'));

		$action = createFileSaveAction($httpClient);

		$reflection = new ReflectionClass($action);
		$method     = $reflection->getMethod('downloadFileFromUrl');

		$method->invoke($action, 'https://example.com/missing.jpg');
	})->throws(RuntimeException::class, 'HTTP error when downloading file: 404');

	test('throws exception when connection fails', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willThrowException(new RuntimeException('Connection timed out'));

		$action = createFileSaveAction($httpClient);

		$reflection = new ReflectionClass($action);
		$method     = $reflection->getMethod('downloadFileFromUrl');

		$method->invoke($action, 'https://example.com/photo.jpg');
	})->throws(RuntimeException::class, 'Failed to download file from URL');

	test('throws size exceeded exception when download exceeds max size', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willThrowException(new RuntimeException('Download exceeds maximum size limit'));

		$config                  = test()->createMock(Config::class);
		$config->tmpdir          = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		$config->maxDownloadSize = 10;

		$action = createFileSaveAction($httpClient, $config);

		$reflection = new ReflectionClass($action);
		$method     = $reflection->getMethod('downloadFileFromUrl');

		$method->invoke($action, 'https://example.com/huge-file.zip');
	})->throws(RuntimeException::class, 'maximum download size');

	test('passes max_bytes option when maxDownloadSize is configured', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'GET',
				test()->anything(),
				test()->callback(
					// 50 MB * 1024 * 1024 = 52428800 bytes
					fn (array $options): bool => ($options['max_bytes'] ?? 0) === 50 * 1024 * 1024
				)
			)
			->willReturn(new HttpResponse(200, 'content'));

		$config                  = test()->createMock(Config::class);
		$config->tmpdir          = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		$config->maxDownloadSize = 50;

		$action = createFileSaveAction($httpClient, $config);

		$reflection = new ReflectionClass($action);
		$method     = $reflection->getMethod('downloadFileFromUrl');

		$result = $method->invoke($action, 'https://example.com/file.txt');

		// Cleanup
		if (is_string($result) && file_exists($result)) {
			unlink($result);
		}
	});

	test('extracts filename from URL correctly', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willReturn(new HttpResponse(200, 'content'));

		$action = createFileSaveAction($httpClient);

		$reflection = new ReflectionClass($action);
		$method     = $reflection->getMethod('extractFilenameFromUrl');

		// Normal URL with filename
		$result = $method->invoke($action, 'https://example.com/images/photo.jpg');
		expect($result)->toBe('photo.jpg');

		// URL with query params
		$result = $method->invoke($action, 'https://example.com/doc.pdf?v=2');
		expect($result)->toBe('doc.pdf');

		// URL with special chars gets sanitized
		$result = $method->invoke($action, 'https://example.com/my file (1).jpg');
		expect($result)->toContain('my_file__1_.jpg');
	});

	test('follows redirects with limit of 5', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'GET',
				test()->anything(),
				test()->callback(fn (array $options): bool => ($options['follow_redirects'] ?? 0) === 5)
			)
			->willReturn(new HttpResponse(200, 'content'));

		$action = createFileSaveAction($httpClient);

		$reflection = new ReflectionClass($action);
		$method     = $reflection->getMethod('downloadFileFromUrl');

		$result = $method->invoke($action, 'https://example.com/redirect-test.jpg');

		if (is_string($result) && file_exists($result)) {
			unlink($result);
		}
	});
});
