<?php

use TotalCMS\Domain\Security\Encryption\Cipher;

describe('Cipher', function (): void {
	// -------------------------
	// Constants
	// -------------------------

	test('Cipher → has default SALT constant', function (): void {
		expect(Cipher::SALT)->toBe('YTFiMmMzZDRlNWY2ZzdoOGk5ajA=');
		expect(Cipher::SALT)->toBeString();
		expect(Cipher::SALT)->not->toBeEmpty();
	});

	// -------------------------
	// Obfuscation (Deterministic)
	// -------------------------

	test('Cipher → obfuscate returns consistent results', function (): void {
		$data = 'test data for obfuscation';

		$result1 = Cipher::obfuscate($data);
		$result2 = Cipher::obfuscate($data);

		expect($result1)->toBe($result2); // Deterministic
		expect($result1)->not->toBe($data); // Should be different from original
		expect($result1)->toBeString();
		expect($result1)->not->toBeEmpty();
	});

	test('Cipher → obfuscate with custom key', function (): void {
		$data      = 'test data';
		$customKey = 'custom-key-123';

		$result1       = Cipher::obfuscate($data, $customKey);
		$result2       = Cipher::obfuscate($data, $customKey);
		$defaultResult = Cipher::obfuscate($data);

		expect($result1)->toBe($result2); // Same key produces same result
		expect($result1)->not->toBe($defaultResult); // Different key produces different result
	});

	test('Cipher → obfuscate produces URL-safe output', function (): void {
		$data   = 'test data with special characters !@#$%^&*()';
		$result = Cipher::obfuscate($data);

		// Should not contain +, /, or = (URL-safe base64)
		expect($result)->not->toContain('+');
		expect($result)->not->toContain('/');
		expect($result)->not->toContain('=');

		// Should only contain URL-safe characters
		expect(preg_match('/^[A-Za-z0-9_-]+$/', $result))->toBe(1);
	});

	test('Cipher → deobfuscate reverses obfuscation', function (): void {
		$originalData = 'confidential configuration data';

		$obfuscated   = Cipher::obfuscate($originalData);
		$deobfuscated = Cipher::deobfuscate($obfuscated);

		expect($deobfuscated)->toBe($originalData);
	});

	test('Cipher → deobfuscate with custom key', function (): void {
		$originalData = 'secret information';
		$customKey    = 'my-secret-key';

		$obfuscated   = Cipher::obfuscate($originalData, $customKey);
		$deobfuscated = Cipher::deobfuscate($obfuscated, $customKey);

		expect($deobfuscated)->toBe($originalData);
	});

	test('Cipher → deobfuscate fails with wrong key', function (): void {
		$originalData = 'secret data';
		$correctKey   = 'correct-key';
		$wrongKey     = 'wrong-key';

		$obfuscated   = Cipher::obfuscate($originalData, $correctKey);
		$deobfuscated = Cipher::deobfuscate($obfuscated, $wrongKey);

		expect($deobfuscated)->not->toBe($originalData);
	});

	test('Cipher → deobfuscate throws exception for invalid data', function (): void {
		expect(fn (): string => Cipher::deobfuscate('invalid-base64-data!!!'))
			->toThrow(Exception::class, 'Invalid obfuscated data');
	});

	// -------------------------
	// Obfuscation Edge Cases
	// -------------------------

	test('Cipher → obfuscate handles empty string', function (): void {
		$result       = Cipher::obfuscate('');
		$deobfuscated = Cipher::deobfuscate($result);

		expect($deobfuscated)->toBe('');
	});

	test('Cipher → obfuscate handles unicode data', function (): void {
		$unicodeData = 'Unicode: 世界 émojis 🔐 special chars';

		$obfuscated   = Cipher::obfuscate($unicodeData);
		$deobfuscated = Cipher::deobfuscate($obfuscated);

		expect($deobfuscated)->toBe($unicodeData);
	});

	test('Cipher → obfuscate handles long data', function (): void {
		$longData = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 100);

		$obfuscated   = Cipher::obfuscate($longData);
		$deobfuscated = Cipher::deobfuscate($obfuscated);

		expect($deobfuscated)->toBe($longData);
	});

	test('Cipher → obfuscate handles binary data', function (): void {
		$binaryData = "\x00\x01\x02\xFF\xFE\xFD";

		$obfuscated   = Cipher::obfuscate($binaryData);
		$deobfuscated = Cipher::deobfuscate($obfuscated);

		expect($deobfuscated)->toBe($binaryData);
	});

	// -------------------------
	// Context Keys
	// -------------------------

	test('Cipher → contextKey generates different keys for different contexts', function (): void {
		$context1 = 'sentry';
		$context2 = 'downloads';

		$key1 = Cipher::contextKey($context1);
		$key2 = Cipher::contextKey($context2);

		expect($key1)->not->toBe($key2);
		expect($key1)->toBeString();
		expect($key2)->toBeString();
		expect(strlen($key1))->toBeGreaterThan(20); // Should be substantial
		expect(strlen($key2))->toBeGreaterThan(20);
	});

	test('Cipher → contextKey is deterministic for same context', function (): void {
		$context = 'config';

		$key1 = Cipher::contextKey($context);
		$key2 = Cipher::contextKey($context);

		expect($key1)->toBe($key2);
	});

	test('Cipher → contextKey with custom base salt', function (): void {
		$context   = 'test';
		$baseSalt1 = 'salt1';
		$baseSalt2 = 'salt2';

		$key1       = Cipher::contextKey($context, $baseSalt1);
		$key2       = Cipher::contextKey($context, $baseSalt2);
		$defaultKey = Cipher::contextKey($context);

		expect($key1)->not->toBe($key2);
		expect($key1)->not->toBe($defaultKey);
		expect($key2)->not->toBe($defaultKey);
	});

	test('Cipher → contextKey works with obfuscation', function (): void {
		$data    = 'context-specific data';
		$context = 'special-context';

		$contextKey   = Cipher::contextKey($context);
		$obfuscated   = Cipher::obfuscate($data, $contextKey);
		$deobfuscated = Cipher::deobfuscate($obfuscated, $contextKey);

		expect($deobfuscated)->toBe($data);
	});

	// -------------------------
	// Encryption (Random/Secure)
	// -------------------------

	test('Cipher → encrypt produces different results each time', function (): void {
		$data = 'sensitive data for encryption';

		$encrypted1 = Cipher::encrypt($data);
		$encrypted2 = Cipher::encrypt($data);

		expect($encrypted1)->not->toBe($encrypted2); // Non-deterministic (secure)
		expect($encrypted1)->not->toBe($data);
		expect($encrypted2)->not->toBe($data);
		expect($encrypted1)->toBeString();
		expect($encrypted2)->toBeString();
	});

	test('Cipher → encrypt with custom key', function (): void {
		$data      = 'secret message';
		$customKey = 'my-encryption-key-256-bits-long-for-aes';

		$encrypted = Cipher::encrypt($data, $customKey);

		expect($encrypted)->toBeString();
		expect($encrypted)->not->toBe($data);
		expect(strlen($encrypted))->toBeGreaterThan(strlen($data)); // Includes IV and base64 encoding
	});

	test('Cipher → decrypt reverses encryption', function (): void {
		$originalData = 'highly sensitive information';

		$encrypted = Cipher::encrypt($originalData);
		$decrypted = Cipher::decrypt($encrypted);

		expect($decrypted)->toBe($originalData);
	});

	test('Cipher → decrypt with custom key', function (): void {
		$originalData = 'classified data';
		$customKey    = 'custom-encryption-key-for-aes-256-cbc';

		$encrypted = Cipher::encrypt($originalData, $customKey);
		$decrypted = Cipher::decrypt($encrypted, $customKey);

		expect($decrypted)->toBe($originalData);
	});

	test('Cipher → decrypt fails with wrong key', function (): void {
		$data       = 'encrypted secret';
		$correctKey = 'correct-key';
		$wrongKey   = 'wrong-key';

		$encrypted = Cipher::encrypt($data, $correctKey);

		expect(fn (): string => Cipher::decrypt($encrypted, $wrongKey))
			->toThrow(Exception::class, 'Decryption failed');
	});

	test('Cipher → decrypt throws exception for invalid data', function (): void {
		expect(fn (): string => Cipher::decrypt('invalid-encrypted-data'))
			->toThrow(Exception::class);
	});

	test('Cipher → decrypt throws exception for insufficient data', function (): void {
		// Too short to contain IV + ciphertext
		$shortData = base64_encode('short');

		expect(fn (): string => Cipher::decrypt($shortData))
			->toThrow(Exception::class, 'Invalid encrypted data: insufficient length');
	});

	// -------------------------
	// Encryption Edge Cases
	// -------------------------

	test('Cipher → encrypt handles empty string', function (): void {
		$encrypted = Cipher::encrypt('');
		$decrypted = Cipher::decrypt($encrypted);

		expect($decrypted)->toBe('');
	});

	test('Cipher → encrypt handles unicode data', function (): void {
		$unicodeData = 'Encrypted unicode: 世界 émojis 🔒 special chars';

		$encrypted = Cipher::encrypt($unicodeData);
		$decrypted = Cipher::decrypt($encrypted);

		expect($decrypted)->toBe($unicodeData);
	});

	test('Cipher → encrypt handles long data', function (): void {
		$longData = str_repeat('This is a long string that needs secure encryption. ', 200);

		$encrypted = Cipher::encrypt($longData);
		$decrypted = Cipher::decrypt($encrypted);

		expect($decrypted)->toBe($longData);
	});

	test('Cipher → encrypt handles binary data', function (): void {
		$binaryData = "\x00\x01\x02\xFF\xFE\xFD\x80\x7F";

		$encrypted = Cipher::encrypt($binaryData);
		$decrypted = Cipher::decrypt($encrypted);

		expect($decrypted)->toBe($binaryData);
	});

	// -------------------------
	// Security Properties
	// -------------------------

	test('Cipher → obfuscation vs encryption differences', function (): void {
		$data = 'test data for comparison';

		// Obfuscation is deterministic
		$obfuscated1 = Cipher::obfuscate($data);
		$obfuscated2 = Cipher::obfuscate($data);
		expect($obfuscated1)->toBe($obfuscated2);

		// Encryption is random
		$encrypted1 = Cipher::encrypt($data);
		$encrypted2 = Cipher::encrypt($data);
		expect($encrypted1)->not->toBe($encrypted2);

		// Both can be reversed
		expect(Cipher::deobfuscate($obfuscated1))->toBe($data);
		expect(Cipher::decrypt($encrypted1))->toBe($data);
		expect(Cipher::decrypt($encrypted2))->toBe($data);
	});

	test('Cipher → different contexts produce different obfuscation', function (): void {
		$data = 'same data, different contexts';

		$key1 = Cipher::contextKey('context1');
		$key2 = Cipher::contextKey('context2');

		$obfuscated1 = Cipher::obfuscate($data, $key1);
		$obfuscated2 = Cipher::obfuscate($data, $key2);

		expect($obfuscated1)->not->toBe($obfuscated2);

		// But each can be deobfuscated correctly
		expect(Cipher::deobfuscate($obfuscated1, $key1))->toBe($data);
		expect(Cipher::deobfuscate($obfuscated2, $key2))->toBe($data);
	});

	test('Cipher → is static utility class', function (): void {
		$reflection = new ReflectionClass(Cipher::class);
		$methods    = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

		// All public methods should be static
		foreach ($methods as $method) {
			expect($method->isStatic())->toBe(true, "Method {$method->getName()} should be static");
		}
	});
});
