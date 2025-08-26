<?php

use TotalCMS\Domain\Property\Data\UrlData;

describe('UrlData', function (): void {
	test('UrlData → creates URL data with valid URL', function (): void {
		$url = new UrlData('https://example.com');

		expect($url->url)->toBe('https://example.com');
		expect($url->settings)->toBe([]);
	});

	test('UrlData → creates URL data with settings', function (): void {
		$settings = ['required' => true, 'placeholder' => 'Enter URL'];
		$url      = new UrlData('https://test.org', $settings);

		expect($url->url)->toBe('https://test.org');
		expect($url->settings)->toBe($settings);
	});

	test('UrlData → transforms to string correctly', function (): void {
		$url = new UrlData('https://domain.co.uk');

		expect($url->transform())->toBe('https://domain.co.uk');
	});

	test('UrlData → converts to string with __toString', function (): void {
		$url = new UrlData('http://localhost:8080');

		expect((string)$url)->toBe('http://localhost:8080');
	});

	test('UrlData → throws exception for invalid URL format', function (): void {
		expect(fn () => new UrlData('not-a-valid-url'))
			->toThrow(InvalidArgumentException::class, 'Invalid URL');
	});

	test('UrlData → throws exception for URL without protocol', function (): void {
		expect(fn () => new UrlData('example.com'))
			->toThrow(InvalidArgumentException::class, 'Invalid URL');
	});

	test('UrlData → throws exception for malformed URL', function (): void {
		expect(fn () => new UrlData('http://'))
			->toThrow(InvalidArgumentException::class, 'Invalid URL');
	});

	test('UrlData → throws exception for completely invalid format', function (): void {
		expect(fn () => new UrlData('::invalid::'))
			->toThrow(InvalidArgumentException::class, 'Invalid URL');
	});

	test('UrlData → handles empty URL string', function (): void {
		$url = new UrlData('');

		expect($url->url)->toBe('');
	});

	test('UrlData → handles HTTP URLs', function (): void {
		$url = new UrlData('http://insecure.example.com');

		expect($url->url)->toBe('http://insecure.example.com');
	});

	test('UrlData → handles HTTPS URLs', function (): void {
		$url = new UrlData('https://secure.example.com');

		expect($url->url)->toBe('https://secure.example.com');
	});

	test('UrlData → handles URLs with ports', function (): void {
		$url = new UrlData('https://example.com:443/path');

		expect($url->url)->toBe('https://example.com:443/path');
	});

	test('UrlData → handles URLs with query parameters', function (): void {
		$url = new UrlData('https://example.com/search?q=test&page=1');

		expect($url->url)->toBe('https://example.com/search?q=test&page=1');
	});

	test('UrlData → handles URLs with fragments', function (): void {
		$url = new UrlData('https://example.com/page#section');

		expect($url->url)->toBe('https://example.com/page#section');
	});

	test('UrlData → handles FTP URLs', function (): void {
		$url = new UrlData('ftp://files.example.com/file.txt');

		expect($url->url)->toBe('ftp://files.example.com/file.txt');
	});

	test('UrlData → handles complex URLs with all components', function (): void {
		$complexUrl = 'https://user:pass@sub.example.com:8080/path/file.html?param=value&other=test#anchor';
		$url        = new UrlData($complexUrl);

		expect($url->url)->toBe($complexUrl);
	});

	test('UrlData → handles URLs with spaces after sanitization', function (): void {
		// PHP's FILTER_SANITIZE_URL removes spaces, making this valid
		$url = new UrlData('https://example.com/path with spaces');

		// Should be sanitized to remove spaces
		expect($url->url)->toBe('https://example.com/pathwithspaces');
	});

	test('UrlData → handles international domain names', function (): void {
		// Test with internationalized domain if supported
		$url = new UrlData('https://xn--e1afmkfd.xn--p1ai'); // пример.рф in punycode

		expect($url->url)->toBe('https://xn--e1afmkfd.xn--p1ai');
	});

	test('UrlData → throws exception for javascript: URLs', function (): void {
		expect(fn () => new UrlData('javascript:alert("xss")'))
			->toThrow(InvalidArgumentException::class, 'Invalid URL');
	});

	test('UrlData → throws exception for data: URLs', function (): void {
		expect(fn () => new UrlData('data:text/html,<script>alert("xss")</script>'))
			->toThrow(InvalidArgumentException::class, 'Invalid URL');
	});

	test('UrlData → transform returns same as __toString', function (): void {
		$url = new UrlData('https://transform.test.com');

		expect($url->transform())->toBe((string)$url);
	});

	test('UrlData → handles URL with unusual but valid schemes', function (): void {
		// Test schemes that are typically supported by PHP's filter_var
		$validSchemes = [
			'mailto:user@example.com',
		];

		foreach ($validSchemes as $testUrl) {
			$url = new UrlData($testUrl);
			expect($url->url)->toBe($testUrl);
		}

		// Some schemes might not be supported, so test them separately
		$possiblyUnsupportedSchemes = [
			'tel:+1234567890',
			'file:///path/to/file',
		];

		foreach ($possiblyUnsupportedSchemes as $testUrl) {
			try {
				$url = new UrlData($testUrl);
				expect($url->url)->toBe($testUrl);
			} catch (InvalidArgumentException $e) {
				// Some schemes might not be supported by filter_var, which is expected
				expect($e->getMessage())->toBe('Invalid URL');
			}
		}
	});

	test('UrlData → handles URLs with IP addresses', function (): void {
		$ipUrls = [
			'http://192.168.1.1',
			'https://127.0.0.1:8080',
			'http://[::1]:3000', // IPv6
		];

		foreach ($ipUrls as $testUrl) {
			$url = new UrlData($testUrl);
			expect($url->url)->toBe($testUrl);
		}
	});
});
