<?php

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TotalCMS\Action\Property\File\FileSaveAction;
use TotalCMS\Domain\Media\Service\ChunkedUploadAssembler;
use TotalCMS\Domain\Media\Service\HeicConverter;
use TotalCMS\Domain\Media\Service\PropertyFileStore;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;
use TotalCMS\Support\RemoteFileDownloader;

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

	$store = new PropertyFileStore($factory, $config, $heicConverter, new RemoteFileDownloader($httpClient, $config, stubPublicHttp($httpClient)), new FileUploadValidator(), test()->createMock(ObjectPatcher::class));

	return new FileSaveAction($renderer, $store, new ChunkedUploadAssembler($config));
}

/** The URL fetch FileSaveAction hands to PropertyFileStore, with the same downloader. */
function fetchThroughStore(HttpClientInterface $httpClient, string $url, ?Config $config = null): string
{
	if (!$config instanceof Config) {
		$config                  = test()->createMock(Config::class);
		$config->tmpdir          = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		$config->maxDownloadSize = 2048;
	}

	$store = new PropertyFileStore(test()->createMock(SaverFactory::class), $config, test()->createMock(HeicConverter::class), new RemoteFileDownloader($httpClient, $config, stubPublicHttp($httpClient)), new FileUploadValidator(), test()->createMock(ObjectPatcher::class));

	return $store->fetch($url);
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
				// Certificates are always verified: the public-only client has no switch for it.
				test()->callback(fn (array $options): bool => ($options['timeout'] ?? 0) === 30
						&& ($options['user_agent'] ?? '') === 'TotalCMS File Downloader')
			)
			->willReturn(new HttpResponse(200, $fileContent));

		$result = fetchThroughStore($httpClient, 'https://example.com/photo.jpg');

		expect($result)->toBeString();
		expect(file_exists($result))->toBeTrue();
		expect(file_get_contents($result))->toBe($fileContent);

		// Cleanup
		unlink($result);
	});

	test('rejects a URL upload with a dangerous extension before downloading', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request'); // download must be prevented

		$action  = createFileSaveAction($httpClient);
		$request = createDownloadRequest('https://evil.test/shell.php');

		$result = $action($request, new Response(), ['collection' => 'blog', 'id' => 'x', 'property' => 'testfile']);

		expect($result->getStatusCode())->toBe(400);
	});

	test('throws exception on HTTP error status', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willReturn(new HttpResponse(404, 'Not Found'));

		fetchThroughStore($httpClient, 'https://example.com/missing.jpg');
	})->throws(RuntimeException::class, 'HTTP error when downloading file: 404');

	test('throws exception when connection fails', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willThrowException(new RuntimeException('Connection timed out'));

		fetchThroughStore($httpClient, 'https://example.com/photo.jpg');
	})->throws(RuntimeException::class, 'Failed to download file from URL');

	test('throws size exceeded exception when download exceeds max size', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willReturn(new HttpResponse(200, str_repeat('x', 1024 * 1024 + 1)));

		$config                  = test()->createMock(Config::class);
		$config->tmpdir          = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		$config->maxDownloadSize = 1;

		fetchThroughStore($httpClient, 'https://example.com/huge-file.zip', $config);
	})->throws(RuntimeException::class, 'maximum download size');

	test('passes max_bytes option when maxDownloadSize is configured', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'GET',
				test()->anything(),
				test()->callback(
					// 50 MB, plus the one byte that tells "too big" from "exactly the cap"
					fn (array $options): bool => ($options['max_bytes'] ?? 0) === 50 * 1024 * 1024 + 1
				)
			)
			->willReturn(new HttpResponse(200, 'content'));

		$config                  = test()->createMock(Config::class);
		$config->tmpdir          = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		$config->maxDownloadSize = 50;

		$result = fetchThroughStore($httpClient, 'https://example.com/file.txt', $config);

		// Cleanup
		if (is_string($result) && file_exists($result)) {
			unlink($result);
		}
	});

	test('extracts filename from URL correctly', function (): void {
		// The filename rule now lives on RemoteFileDownloader, which the action
		// uses both to validate the name before downloading and to name the
		// temp file.
		expect(RemoteFileDownloader::filenameFor('https://example.com/images/photo.jpg'))->toBe('photo.jpg')
			->and(RemoteFileDownloader::filenameFor('https://example.com/doc.pdf?v=2'))->toBe('doc.pdf')
			->and(RemoteFileDownloader::filenameFor('https://example.com/my file (1).jpg'))->toContain('my_file__1_.jpg');
	});

	test('refuses a URL on a non-public address before downloading', function (string $url): void {
		// Saving a file field from a URL used to fetch wherever it pointed and
		// keep the reply as a downloadable file, so anyone who could edit a
		// collection could read this server's network back out (2026-10-30).
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request');

		expect(fn () => fetchThroughStore($httpClient, $url))
			->toThrow(RuntimeException::class, 'non-public address');
	})->with([
		'cloud metadata' => 'http://169.254.169.254/latest/meta-data/iam.jpg',
		'loopback'       => 'http://127.0.0.1:8080/admin.jpg',
		'private range'  => 'http://10.0.0.5/internal.pdf',
	]);
});
