<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\PasswordData;

#[CoversClass(PasswordData::class)]
final class PasswordDataTest extends TestCase
{
	public function testHashesPlainTextPasswords(): void
	{
		$password = 'plainTextPassword123';
		$data     = new PasswordData($password);

		$this->assertNotSame($password, $data->hash);
		$this->assertStringStartsWith('$2y$', $data->hash); // Default bcrypt format
		$this->assertSame(60, strlen($data->hash)); // Standard bcrypt hash length
	}

	public function testPreservesExistingPasswordHashes(): void
	{
		// Create a known hash
		$originalPassword = 'testPassword123';
		$existingHash     = password_hash($originalPassword, PASSWORD_DEFAULT);

		$data = new PasswordData($existingHash);
		$this->assertSame($existingHash, $data->hash);
	}

	public function testGeneratesDifferentHashesForSamePassword(): void
	{
		$password = 'samePassword123';
		$data1    = new PasswordData($password);
		$data2    = new PasswordData($password);

		// Hashes should be different due to salt
		$this->assertNotSame($data1->hash, $data2->hash);

		// But both should verify against the original password
		$this->assertTrue(password_verify($password, $data1->hash));
		$this->assertTrue(password_verify($password, $data2->hash));
	}

	public function testHandlesVariousPasswordFormats(): void
	{
		$passwords = [
			'simple',
			'with spaces',
			'with!@#$%^&*()special',
			'très_long_mot_de_passe_avec_caractères_spéciaux_éàçù',
			'123456789',
			'MixedCASE123',
			'emoji😀password🔐',
		];

		foreach ($passwords as $password) {
			$data = new PasswordData($password);
			$this->assertNotSame($password, $data->hash);
			$this->assertTrue(password_verify($password, $data->hash));
		}
	}

	public function testRecognizesBcryptHashes(): void
	{
		// Create a real bcrypt hash to test with
		$realHash = password_hash('testpassword', PASSWORD_DEFAULT);
		$data     = new PasswordData($realHash);
		$this->assertSame($realHash, $data->hash);
	}

	public function testRecognizesArgon2Hashes(): void
	{
		// Test if argon2 is available
		if (defined('PASSWORD_ARGON2I')) {
			$password  = 'testPassword';
			$argonHash = password_hash($password, PASSWORD_ARGON2I);
			$data      = new PasswordData($argonHash);
			$this->assertSame($argonHash, $data->hash);
		} else {
			$this->assertTrue(true); // Skip if argon2 not available
		}
	}

	public function testRejectsInvalidHashFormats(): void
	{
		$invalidHashes = [
			'$1$invalid$hash', // MD5 crypt (deprecated)
			'plaintext',
			'$invalid$format',
		];

		foreach ($invalidHashes as $hash) {
			// Invalid hashes should be treated as plain text and rehashed
			$data = new PasswordData($hash);
			$this->assertNotSame($hash, $data->hash);
			$this->assertStringStartsWith('$2y$', $data->hash);
		}
	}

	public function testHandlesEmptyPasswords(): void
	{
		$data = new PasswordData('');
		$this->assertSame('', $data->hash);
	}

	public function testHandlesNullLikeValues(): void
	{
		// Test empty string
		$data = new PasswordData('');
		$this->assertSame('', $data->hash);

		// Test '0' string - PHP's empty() considers '0' as empty
		$data = new PasswordData('0');
		$this->assertSame('', $data->hash); // '0' is considered empty by PHP

		// Test non-empty string that looks like zero
		$data = new PasswordData('00');
		$this->assertNotSame('00', $data->hash);
		$this->assertStringStartsWith('$2y$', $data->hash);
	}

	public function testUsesSecureHashingAlgorithm(): void
	{
		$data = new PasswordData('testPassword');
		$info = password_get_info($data->hash);

		// Should use a secure algorithm (bcrypt, argon2, etc.)
		$this->assertNotNull($info['algo']);
		$this->assertContains($info['algoName'], ['bcrypt', 'argon2i', 'argon2id']);
	}

	public function testProvidesSecurePasswordVerification(): void
	{
		$password = 'testPassword123';
		$data     = new PasswordData($password);

		// Verify that password_verify works correctly (timing attack resistance is built into PHP's implementation)
		$this->assertTrue(password_verify($password, $data->hash));
		$this->assertFalse(password_verify('wrongPassword', $data->hash));
		$this->assertFalse(password_verify('', $data->hash));
		$this->assertFalse(password_verify('similar-password', $data->hash));

		// Verify hash is long enough to be a proper cryptographic hash
		$this->assertGreaterThan(50, strlen($data->hash));
	}

	public function testGeneratesSufficientlyComplexHashes(): void
	{
		$data = new PasswordData('simple');

		// Hash should be long enough
		$this->assertGreaterThanOrEqual(50, strlen($data->hash));

		// Should contain various character types
		$this->assertMatchesRegularExpression('/[A-Za-z]/', $data->hash); // Letters
		$this->assertMatchesRegularExpression('/\d/', $data->hash); // Numbers
		$this->assertMatchesRegularExpression('/[\.\/\$]/', $data->hash); // Special chars
	}

	public function testHandlesPasswordInjectionAttempts(): void
	{
		$injectionAttempts = [
			"'; DROP TABLE users; --",
			'<script>alert("xss")</script>',
			'${system("rm -rf /")}',
			'`whoami`',
			'$(id)',
		];

		foreach ($injectionAttempts as $attempt) {
			$data = new PasswordData($attempt);
			$this->assertNotSame($attempt, $data->hash);
			$this->assertTrue(password_verify($attempt, $data->hash));
		}
	}

	public function testPreventsHashCollisionAttacks(): void
	{
		// Test that different passwords produce different hashes
		$passwords = [
			'password1',
			'password2',
			'Password1', // Case variation
			'password1 ', // Trailing space
		];

		$hashes = [];
		foreach ($passwords as $password) {
			$data     = new PasswordData($password);
			$hashes[] = $data->hash;
		}

		// All hashes should be unique
		$this->assertSame(count(array_unique($hashes)), count($hashes));
	}

	public function testHandlesVeryLongPasswords(): void
	{
		$longPassword = str_repeat('a', 1000);
		$data         = new PasswordData($longPassword);

		$this->assertNotSame($longPassword, $data->hash);
		$this->assertTrue(password_verify($longPassword, $data->hash));
	}

	public function testHandlesBinaryDataInPasswords(): void
	{
		// Test binary data without null bytes (bcrypt doesn't support null bytes)
		$binaryPassword = "\x01\x02\xFF\xFE";
		$data           = new PasswordData($binaryPassword);

		$this->assertNotSame($binaryPassword, $data->hash);
		$this->assertTrue(password_verify($binaryPassword, $data->hash));
	}

	public function testHandlesUnicodePasswords(): void
	{
		$unicodePasswords = [
			'пароль', // Cyrillic
			'密码', // Chinese
			'パスワード', // Japanese
			'🔐🔑🗝️', // Emoji
		];

		foreach ($unicodePasswords as $password) {
			$data = new PasswordData($password);
			$this->assertNotSame($password, $data->hash);
			$this->assertTrue(password_verify($password, $data->hash));
		}
	}

	public function testHandlesPasswordsWithNullBytes(): void
	{
		$passwordWithNull = "password\x00hidden";

		// bcrypt will throw ValueError for null bytes
		$this->expectException(\ValueError::class);
		$this->expectExceptionMessage('Bcrypt password must not contain null character');
		new PasswordData($passwordWithNull);
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['some' => 'setting'];
		$data     = new PasswordData('password', $settings);
		$this->assertSame($settings, $data->settings);
	}

	public function testUsesEmptyArrayAsDefaultSettings(): void
	{
		$data = new PasswordData('password');
		$this->assertSame([], $data->settings);
	}

	public function testTransformReturnsHash(): void
	{
		$data = new PasswordData('password');
		$this->assertSame($data->hash, $data->transform());
		$this->assertIsString($data->transform());
	}

	public function testToStringReturnsHash(): void
	{
		$data = new PasswordData('password');
		$this->assertSame($data->hash, (string)$data);
	}

	public function testBothMethodsReturnSameValue(): void
	{
		$data = new PasswordData('password');
		$this->assertSame($data->transform(), (string)$data);
	}

	public function testNeverExposesPlainTextPassword(): void
	{
		$plainPassword = 'secretPassword123';
		$data          = new PasswordData($plainPassword);

		$this->assertNotSame($plainPassword, $data->transform());
		$this->assertNotSame($plainPassword, (string)$data);
		$this->assertNotSame($plainPassword, $data->hash);
	}

	public function testUsesCurrentDefaultAlgorithm(): void
	{
		$data = new PasswordData('test');
		$info = password_get_info($data->hash);

		// Should use whatever PHP's current default is
		$this->assertSame(PASSWORD_DEFAULT, $info['algo']);
	}

	public function testMaintainsCompatibilityWithOlderHashes(): void
	{
		// Test that a valid bcrypt hash is preserved
		$validHash = password_hash('oldpassword', PASSWORD_DEFAULT);
		$data      = new PasswordData($validHash);
		$this->assertSame($validHash, $data->hash);

		// Test that invalid hash formats get rehashed
		$invalidHash = '$2y$10$example.hash.from.older.version';
		$data        = new PasswordData($invalidHash);
		$this->assertNotSame($invalidHash, $data->hash);
		$this->assertStringStartsWith('$2y$', $data->hash);
	}

	public function testHashesWeakPasswordsWithoutRejection(): void
	{
		// The class should hash any password given, not enforce strength
		$weakPasswords = [
			'123',
			'password',
			'abc',
			'1',
		];

		foreach ($weakPasswords as $weak) {
			$data = new PasswordData($weak);
			$this->assertNotSame($weak, $data->hash);
			$this->assertTrue(password_verify($weak, $data->hash));
		}
	}

	public function testProvidesSecureStorageRegardlessOfPasswordStrength(): void
	{
		$weakPassword = '123';
		$data         = new PasswordData($weakPassword);

		// Even weak passwords should get strong hashes
		$this->assertGreaterThanOrEqual(50, strlen($data->hash));
		$this->assertStringStartsWith('$2y$', $data->hash);
	}
}
