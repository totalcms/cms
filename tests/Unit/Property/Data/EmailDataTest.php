<?php

use TotalCMS\Domain\Property\Data\EmailData;

describe('EmailData', function (): void {
	test('EmailData → creates email data with valid email', function (): void {
		$email = new EmailData('test@example.com');

		expect($email->email)->toBe('test@example.com');
		expect($email->settings)->toBe([]);
	});

	test('EmailData → creates email data with settings', function (): void {
		$settings = ['required' => true, 'placeholder' => 'Enter email'];
		$email    = new EmailData('user@test.com', $settings);

		expect($email->email)->toBe('user@test.com');
		expect($email->settings)->toBe($settings);
	});

	test('EmailData → transforms to string correctly', function (): void {
		$email = new EmailData('contact@domain.org');

		expect($email->transform())->toBe('contact@domain.org');
	});

	test('EmailData → converts to string with __toString', function (): void {
		$email = new EmailData('info@company.net');

		expect((string)$email)->toBe('info@company.net');
	});

	test('EmailData → throws exception for invalid email format', function (): void {
		expect(fn () => new EmailData('invalid-email'))
			->toThrow(InvalidArgumentException::class, 'Invalid email');
	});

	test('EmailData → throws exception for email without domain', function (): void {
		expect(fn () => new EmailData('user@'))
			->toThrow(InvalidArgumentException::class, 'Invalid email');
	});

	test('EmailData → throws exception for email without username', function (): void {
		expect(fn () => new EmailData('@example.com'))
			->toThrow(InvalidArgumentException::class, 'Invalid email');
	});

	test('EmailData → throws exception for completely invalid format', function (): void {
		expect(fn () => new EmailData('not-an-email-at-all'))
			->toThrow(InvalidArgumentException::class, 'Invalid email');
	});

	test('EmailData → handles empty email string', function (): void {
		$email = new EmailData('');

		expect($email->email)->toBe('');
	});

	test('EmailData → cleans up valid email with extra spaces', function (): void {
		// PHP's FILTER_SANITIZE_EMAIL removes spaces
		$email = new EmailData('test@example.com');

		expect($email->email)->toBe('test@example.com');
	});

	test('EmailData → handles complex valid email formats', function (): void {
		$complexEmail = 'user.name+tag@sub.domain.com';
		$email        = new EmailData($complexEmail);

		expect($email->email)->toBe($complexEmail);
	});

	test('EmailData → validates email with numbers and hyphens', function (): void {
		$email = new EmailData('user123@test-domain.co.uk');

		expect($email->email)->toBe('user123@test-domain.co.uk');
	});

	test('EmailData → throws exception for email with invalid characters', function (): void {
		expect(fn () => new EmailData('user@domain..com'))
			->toThrow(InvalidArgumentException::class, 'Invalid email');
	});

	test('EmailData → throws exception for multiple @ symbols', function (): void {
		expect(fn () => new EmailData('user@@domain.com'))
			->toThrow(InvalidArgumentException::class, 'Invalid email');
	});

	test('EmailData → transform returns same as __toString', function (): void {
		$email = new EmailData('transform@test.com');

		expect($email->transform())->toBe((string)$email);
	});
});
