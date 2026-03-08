<?php

use TotalCMS\Domain\Factory\Faker\FakerPicsum;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

beforeEach(function (): void {
	FakerPicsum::setHttpClient(null);
});

afterEach(function (): void {
	FakerPicsum::setHttpClient(null);
});

describe('FakerPicsum URL building', function (): void {

	test('generates correct picsum URL with default dimensions', function (): void {
		$url = FakerPicsum::picsumUrl();
		expect($url)->toBe('https://picsum.photos/640/480.jpg');
	});

	test('generates correct picsum URL with custom dimensions', function (): void {
		$url = FakerPicsum::picsumUrl(1920, 1080);
		expect($url)->toBe('https://picsum.photos/1920/1080.jpg');
	});

	test('generates grayscale URL', function (): void {
		$url = FakerPicsum::picsumUrl(640, 480, gray: true);
		expect($url)->toContain('grayscale');
	});

	test('generates blurred URL', function (): void {
		$url = FakerPicsum::picsumUrl(640, 480, blur: 5);
		expect($url)->toContain('blur');
	});

	test('generates URL with both grayscale and blur', function (): void {
		$url = FakerPicsum::picsumUrl(640, 480, gray: true, blur: 3);
		expect($url)->toContain('grayscale');
		expect($url)->toContain('blur');
	});
});

describe('FakerPicsum image download', function (): void {

	test('downloads image and saves to disk', function (): void {
		$fakeImageData = str_repeat("\xFF\xD8\xFF\xE0", 100); // fake JPEG data

		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'GET',
				test()->stringStartsWith('https://picsum.photos/'),
				test()->callback(function (array $options): bool {
					return ($options['timeout'] ?? 0) === 10
						&& ($options['connect_timeout'] ?? 0) === 5
						&& ($options['user_agent'] ?? '') === 'TotalCMS/3.0';
				})
			)
			->willReturn(new HttpResponse(200, $fakeImageData));

		FakerPicsum::setHttpClient($httpClient);

		$tmpDir = sys_get_temp_dir();
		$filepath = FakerPicsum::picsum($tmpDir, 320, 240);

		expect($filepath)->toBeString();
		expect(file_exists($filepath))->toBeTrue();
		expect(file_get_contents($filepath))->toBe($fakeImageData);
		expect(pathinfo($filepath, PATHINFO_EXTENSION))->toBe('jpg');

		// Cleanup
		unlink($filepath);
	});

	test('throws exception when download fails', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willThrowException(new RuntimeException('Connection failed'));

		FakerPicsum::setHttpClient($httpClient);

		FakerPicsum::picsum(sys_get_temp_dir());
	})->throws(RuntimeException::class, 'unable to download the remote image');

	test('throws exception when HTTP status is not 200', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willReturn(new HttpResponse(404, ''));

		FakerPicsum::setHttpClient($httpClient);

		FakerPicsum::picsum(sys_get_temp_dir());
	})->throws(RuntimeException::class, 'unable to download the remote image');

	test('throws exception for invalid directory', function (): void {
		FakerPicsum::picsum('/this/path/does/not/exist');
	})->throws(InvalidArgumentException::class, 'Cannot write to directory');

	test('uses system temp dir when no directory specified', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->method('request')
			->willReturn(new HttpResponse(200, 'fake-image'));

		FakerPicsum::setHttpClient($httpClient);

		$filepath = FakerPicsum::picsum();

		expect($filepath)->toStartWith(sys_get_temp_dir());
		expect(file_exists($filepath))->toBeTrue();

		unlink($filepath);
	});
});
