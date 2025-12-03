<?php

use TotalCMS\Domain\Media\Generator\QRGenerator;

describe('QRGenerator', function (): void {
	beforeEach(function (): void {
		$this->generator = new QRGenerator();
	});

	test('QRGenerator → creates instance with default size', function (): void {
		$generator = new QRGenerator();

		expect($generator)->toBeInstanceOf(QRGenerator::class);
	});

	test('QRGenerator → creates instance with custom size', function (): void {
		$generator = new QRGenerator(editionFeatures: null, size: 256);

		expect($generator)->toBeInstanceOf(QRGenerator::class);
	});

	test('QRGenerator → generates SVG for plain text', function (): void {
		$text = 'Hello World';
		$svg  = $this->generator->text($text);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for URL', function (): void {
		$url = 'https://example.com';
		$svg = $this->generator->url($url);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for telephone number', function (): void {
		$phone = '+1234567890';
		$svg   = $this->generator->tel($phone);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for GPS coordinates', function (): void {
		$latitude  = '40.7128';
		$longitude = '-74.0060';
		$svg       = $this->generator->gps($latitude, $longitude);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for SMS', function (): void {
		$phone   = '1234567890';
		$message = 'Hello from QR code';
		$svg     = $this->generator->sms($phone, $message);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for WiFi credentials', function (): void {
		$auth     = 'WPA';
		$ssid     = 'MyNetwork';
		$password = 'password123';
		$hidden   = 'false';

		$svg = $this->generator->wifi($auth, $ssid, $password, $hidden);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for email (mailto)', function (): void {
		$email = 'test@example.com';
		$svg   = $this->generator->mailto($email);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for email with subject and body', function (): void {
		$email   = 'contact@domain.com';
		$subject = 'QR Code Test';
		$body    = 'This email was generated from a QR code';

		$svg = $this->generator->mailto($email, $subject, $body);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for calendar event', function (): void {
		$eventData = [
			'title'    => 'Test Meeting',
			'desc'     => 'QR code generated meeting',
			'location' => 'Conference Room',
			'start'    => '2024-12-01 10:00:00',
			'end'      => '2024-12-01 11:00:00',
		];

		$svg = $this->generator->event($eventData);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → generates SVG for VCF contact', function (): void {
		$contactData = [
			'first'   => 'John',
			'last'    => 'Doe',
			'company' => 'Test Company',
			'phone'   => '+1234567890',
			'email'   => 'john@example.com',
		];

		$svg = $this->generator->vcf($contactData);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		expect($svg)->toContain('</svg>');
	});

	test('QRGenerator → throws exception for empty text input', function (): void {
		expect(fn () => $this->generator->text(''))
			->toThrow(InvalidArgumentException::class, 'Found empty contents');
	});

	test('QRGenerator → handles special characters in text', function (): void {
		$text = 'Special chars: !@#$%^&*()_+-=[]{}|;:,.<>?';
		$svg  = $this->generator->text($text);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
	});

	test('QRGenerator → handles simple unicode characters in text', function (): void {
		// Use simpler unicode that works with ISO-8859-1 encoding
		$text = 'Unicode café naïve';
		$svg  = $this->generator->text($text);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
	});

	test('QRGenerator → handles long text input', function (): void {
		$longText = str_repeat('Long text content for QR code generation. ', 50);
		$svg      = $this->generator->text($longText);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
	});

	test('QRGenerator → event handles partial data with defaults', function (): void {
		$partialData = [
			'title' => 'Minimal Event',
		];

		$svg = $this->generator->event($partialData);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
	});

	test('QRGenerator → VCF handles partial data with defaults', function (): void {
		$partialData = [
			'first' => 'Jane',
			'email' => 'jane@test.com',
		];

		$svg = $this->generator->vcf($partialData);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
	});

	test('QRGenerator → event sanitizes HTML in data', function (): void {
		$maliciousData = [
			'title' => '<script>alert("xss")</script>Meeting',
			'desc'  => 'Normal description',
		];

		$svg = $this->generator->event($maliciousData);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		// HTML should be escaped/sanitized
		expect($svg)->not->toContain('<script>');
	});

	test('QRGenerator → VCF sanitizes HTML in data', function (): void {
		$maliciousData = [
			'first'   => '<b>Bold</b>Name',
			'company' => '<i>Company</i>',
		];

		$svg = $this->generator->vcf($maliciousData);

		expect($svg)->toBeString();
		expect($svg)->toContain('<svg');
		// HTML should be escaped/sanitized
		expect($svg)->not->toContain('<b>');
		expect($svg)->not->toContain('<i>');
	});

	test('QRGenerator → handles various phone number formats', function (): void {
		$formats = [
			'1234567890',
			'+1-234-567-8900',
			'(123) 456-7890',
			'+44 20 7946 0958',
		];

		foreach ($formats as $phone) {
			$svg = $this->generator->tel($phone);

			expect($svg)->toBeString();
			expect($svg)->toContain('<svg');
		}
	});

	test('QRGenerator → generates different SVG content for different inputs', function (): void {
		$svg1 = $this->generator->text('Content 1');
		$svg2 = $this->generator->text('Content 2');

		expect($svg1)->not->toBe($svg2);
		expect($svg1)->toContain('<svg');
		expect($svg2)->toContain('<svg');
	});
});
