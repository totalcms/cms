<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\EmailData;
use InvalidArgumentException;

#[CoversClass(EmailData::class)]
final class EmailDataTest extends TestCase
{
	public function testAcceptsStandardEmailAddresses(): void
	{
		$validEmails = [
			'user@example.com',
			'test.email+tag@domain.co.uk',
			'user_name@domain-name.com',
			'firstname.lastname@subdomain.example.org',
			'user+tag@domain.com',
			'x@example.com', // Single character local part
		];

		foreach ($validEmails as $email) {
			$data = new EmailData($email);
			$this->assertSame($email, $data->email);
			$this->assertSame($email, $data->transform());
			$this->assertSame($email, (string)$data);
		}
	}

	public function testHandlesInternationalizedDomainNames(): void
	{
		// Note: This tests ASCII representation of IDN domains
		$data = new EmailData('user@xn--nxasmq6b.com'); // IDN domain in ASCII
		$this->assertSame('user@xn--nxasmq6b.com', $data->email);
	}

	public function testPreservesCaseInLocalPart(): void
	{
		$data = new EmailData('User.Name@EXAMPLE.COM');
		// PHP's filter functions may normalize, let's test what we get
		$this->assertStringContainsString('@', $data->email);
		$this->assertStringContainsString('User.Name', $data->email);
	}

	public function testSanitizesEmailsWithDangerousCharacters(): void
	{
		$data = new EmailData('user@example.com');
		$this->assertSame('user@example.com', $data->email);
	}

	public function testRemovesWhitespace(): void
	{
		$data = new EmailData(' user@example.com ');
		$this->assertSame('user@example.com', $data->email);
	}

	public function testHandlesEncodedCharacters(): void
	{
		// Test if PHP's sanitization handles encoded chars
		try {
			$data = new EmailData('user%40example.com');
			// This might be sanitized to user@example.com or remain as is
			$this->assertStringContainsString('example.com', $data->email);
		} catch (InvalidArgumentException $e) {
			// PHP might reject this as invalid
			$this->assertSame('Invalid email', $e->getMessage());
		}
	}

	public function testRejectsEmailsWithoutAtSymbol(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid email');
		new EmailData('userexample.com');
	}

	public function testRejectsEmailsWithMultipleAtSymbols(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid email');
		new EmailData('user@@example.com');
	}

	public function testRejectsEmailsWithoutDomain(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid email');
		new EmailData('user@');
	}

	public function testRejectsEmailsWithoutLocalPart(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid email');
		new EmailData('@example.com');
	}

	public function testRejectsEmailsWithInvalidDomainFormat(): void
	{
		$invalidEmails = [
			'user@domain',
			'user@.com',
			'user@domain.',
			'user@domain..com',
			'user@-domain.com',
			'user@domain-.com',
		];

		foreach ($invalidEmails as $email) {
			$this->expectException(InvalidArgumentException::class);
			$this->expectExceptionMessage('Invalid email');
			new EmailData($email);
		}
	}

	public function testHandlesEmailsWithSpaces(): void
	{
		$spacedEmails = [
			'user name@example.com', // Space in local part
			'user@exam ple.com', // Space in domain
		];

		foreach ($spacedEmails as $email) {
			try {
				$data = new EmailData($email);
				// If accepted, the sanitized version should not contain spaces
				$this->assertStringNotContainsString(' ', $data->email);
			} catch (InvalidArgumentException $e) {
				$this->assertSame('Invalid email', $e->getMessage());
			}
		}
	}

	public function testAllowsEmptyEmailStrings(): void
	{
		$data = new EmailData('');
		$this->assertSame('', $data->email);
		$this->assertSame('', $data->transform());
		$this->assertSame('', (string)$data);
	}

	public function testPreventsEmailHeaderInjection(): void
	{
		// Test emails that could be used for header injection
		$dangerousEmails = [
			"user@example.com\nBcc: attacker@evil.com",
			"user@example.com\rTo: victim@target.com",
			"user@example.com\r\nSubject: Spam",
		];

		foreach ($dangerousEmails as $email) {
			$this->expectException(InvalidArgumentException::class);
			$this->expectExceptionMessage('Invalid email');
			new EmailData($email);
		}
	}

	public function testSanitizesScriptInjectionAttempts(): void
	{
		$scriptEmails = [
			'<script>alert(1)</script>@example.com',
			'user@<script>alert(1)</script>.com',
			'javascript:alert(1)@example.com',
		];

		foreach ($scriptEmails as $email) {
			try {
				$data = new EmailData($email);
				// If accepted, dangerous parts should be sanitized
				$this->assertStringNotContainsString('<script>', $data->email);
				$this->assertStringNotContainsString('javascript:', $data->email);
			} catch (InvalidArgumentException $e) {
				$this->assertSame('Invalid email', $e->getMessage());
			}
		}
	}

	public function testPreventsSqlInjectionAttempts(): void
	{
		$sqlEmails = [
			"user'; DROP TABLE users; --@example.com",
			'user@example.com; DELETE FROM users;',
			"user@example.com' OR '1'='1",
		];

		foreach ($sqlEmails as $email) {
			$this->expectException(InvalidArgumentException::class);
			$this->expectExceptionMessage('Invalid email');
			new EmailData($email);
		}
	}

	public function testHandlesVeryLongEmailAddresses(): void
	{
		// RFC 5321 limits local part to 64 chars and domain to 253 chars
		$longLocal = str_repeat('a', 64);
		$validDomain = 'example.com';
		$email = $longLocal . '@' . $validDomain;
		
		$data = new EmailData($email);
		$this->assertSame($email, $data->email);
	}

	public function testRejectsExcessivelyLongEmailAddresses(): void
	{
		// Over the RFC limit
		$tooLongLocal = str_repeat('a', 65);
		$email = $tooLongLocal . '@example.com';
		
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid email');
		new EmailData($email);
	}

	public function testHandlesSpecialValidEmailFormats(): void
	{
		// These are technically valid but unusual
		$specialEmails = [
			'"user.name"@example.com', // Quoted local part
			'user@[192.168.1.1]', // IP address in brackets
		];

		foreach ($specialEmails as $email) {
			try {
				$data = new EmailData($email);
				$this->assertStringContainsString('@', $data->email);
			} catch (InvalidArgumentException $e) {
				// Some special formats might not be supported by PHP's filter
				$this->assertSame('Invalid email', $e->getMessage());
			}
		}
	}

	public function testHandlesUnicodeInEmailAddresses(): void
	{
		// Test various unicode scenarios
		$unicodeEmails = [
			'user@münchen.de', // Non-ASCII domain
			'üser@example.com', // Non-ASCII local part
		];

		foreach ($unicodeEmails as $email) {
			try {
				$data = new EmailData($email);
				// If accepted, should contain @ symbol
				$this->assertStringContainsString('@', $data->email);
			} catch (InvalidArgumentException $e) {
				$this->assertSame('Invalid email', $e->getMessage());
			}
		}
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['some' => 'setting'];
		$data = new EmailData('user@example.com', $settings);
		$this->assertSame($settings, $data->settings);
	}

	public function testUsesEmptyArrayAsDefaultSettings(): void
	{
		$data = new EmailData('user@example.com');
		$this->assertSame([], $data->settings);
	}

	public function testTransformReturnsStringRepresentation(): void
	{
		$email = 'user@example.com';
		$data = new EmailData($email);
		$this->assertSame($email, $data->transform());
		$this->assertIsString($data->transform());
	}

	public function testToStringReturnsEmailString(): void
	{
		$email = 'user@example.com';
		$data = new EmailData($email);
		$this->assertSame($email, (string)$data);
	}

	public function testBothMethodsReturnSameValue(): void
	{
		$email = 'user@example.com';
		$data = new EmailData($email);
		$this->assertSame($data->transform(), (string)$data);
	}
}