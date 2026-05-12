<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Encryption\Cipher;

describe('Cipher', function (): void {
	describe('Obfuscation', function (): void {
		test('Cipher → obfuscates and deobfuscates string correctly', function (): void {
			$original = 'Hello World';

			$obfuscated   = Cipher::obfuscate($original);
			$deobfuscated = Cipher::deobfuscate($obfuscated);

			expect($deobfuscated)->toBe($original);
		});

		test('Cipher → obfuscated string is different from original', function (): void {
			$original   = 'secret data';
			$obfuscated = Cipher::obfuscate($original);

			expect($obfuscated)->not->toBe($original);
			expect($obfuscated)->toBeString();
		});

		test('Cipher → obfuscation is deterministic', function (): void {
			$text = 'deterministic test';

			$obfuscated1 = Cipher::obfuscate($text);
			$obfuscated2 = Cipher::obfuscate($text);

			expect($obfuscated1)->toBe($obfuscated2);
		});

		test('Cipher → obfuscation with custom key works', function (): void {
			$text      = 'custom key test';
			$customKey = 'my-custom-key-123';

			$obfuscated   = Cipher::obfuscate($text, $customKey);
			$deobfuscated = Cipher::deobfuscate($obfuscated, $customKey);

			expect($deobfuscated)->toBe($text);
		});

		test('Cipher → obfuscation with different keys produces different results', function (): void {
			$text = 'same text';
			$key1 = 'key1';
			$key2 = 'key2';

			$obfuscated1 = Cipher::obfuscate($text, $key1);
			$obfuscated2 = Cipher::obfuscate($text, $key2);

			expect($obfuscated1)->not->toBe($obfuscated2);
		});

		test('Cipher → wrong key fails deobfuscation', function (): void {
			$text       = 'secure data';
			$correctKey = 'correct-key';
			$wrongKey   = 'wrong-key';

			$obfuscated   = Cipher::obfuscate($text, $correctKey);
			$deobfuscated = Cipher::deobfuscate($obfuscated, $wrongKey);

			expect($deobfuscated)->not->toBe($text);
		});

		test('Cipher → handles empty string obfuscation', function (): void {
			$obfuscated   = Cipher::obfuscate('');
			$deobfuscated = Cipher::deobfuscate($obfuscated);

			expect($deobfuscated)->toBe('');
		});

		test('Cipher → handles long text obfuscation', function (): void {
			$longText = str_repeat('This is a long text for testing. ', 100);

			$obfuscated   = Cipher::obfuscate($longText);
			$deobfuscated = Cipher::deobfuscate($obfuscated);

			expect($deobfuscated)->toBe($longText);
		});

		test('Cipher → handles special characters', function (): void {
			$specialText = '!@#$%^&*()_+-=[]{}|;:,.<>?~`"\'\\';

			$obfuscated   = Cipher::obfuscate($specialText);
			$deobfuscated = Cipher::deobfuscate($obfuscated);

			expect($deobfuscated)->toBe($specialText);
		});

		test('Cipher → handles unicode characters', function (): void {
			$unicodeText = 'Hello 世界 🌍 café naïve résumé';

			$obfuscated   = Cipher::obfuscate($unicodeText);
			$deobfuscated = Cipher::deobfuscate($obfuscated);

			expect($deobfuscated)->toBe($unicodeText);
		});

		test('Cipher → obfuscated output is URL-safe base64', function (): void {
			$text       = 'URL safe test';
			$obfuscated = Cipher::obfuscate($text);

			// Should not contain +, /, or = characters (URL-safe base64)
			expect($obfuscated)->not->toContain('+');
			expect($obfuscated)->not->toContain('/');
			expect($obfuscated)->not->toContain('=');
		});

		test('Cipher → throws exception for invalid obfuscated data', function (): void {
			expect(fn (): string => Cipher::deobfuscate('invalid-data-!!!'))
				->toThrow(Exception::class, 'Invalid obfuscated data');
		});
	});

	describe('Context Keys', function (): void {
		test('Cipher → contextKey generates different keys for different contexts', function (): void {
			$context1 = 'sentry';
			$context2 = 'downloads';

			$key1 = Cipher::contextKey($context1);
			$key2 = Cipher::contextKey($context2);

			expect($key1)->not->toBe($key2);
			expect($key1)->toBeString();
			expect($key2)->toBeString();
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

			$key1 = Cipher::contextKey($context, $baseSalt1);
			$key2 = Cipher::contextKey($context, $baseSalt2);

			expect($key1)->not->toBe($key2);
		});

		test('Cipher → contextKey works with obfuscation', function (): void {
			$text    = 'context-specific data';
			$context = 'testing';

			$contextKey   = Cipher::contextKey($context);
			$obfuscated   = Cipher::obfuscate($text, $contextKey);
			$deobfuscated = Cipher::deobfuscate($obfuscated, $contextKey);

			expect($deobfuscated)->toBe($text);
		});
	});

	describe('Encryption', function (): void {
		test('Cipher → encrypts and decrypts string correctly', function (): void {
			$original = 'secure message';

			$encrypted = Cipher::encrypt($original);
			$decrypted = Cipher::decrypt($encrypted);

			expect($decrypted)->toBe($original);
		});

		test('Cipher → encrypted string is different from original', function (): void {
			$original  = 'secret password';
			$encrypted = Cipher::encrypt($original);

			expect($encrypted)->not->toBe($original);
			expect($encrypted)->toBeString();
		});

		test('Cipher → encryption is not deterministic (includes random IV)', function (): void {
			$text = 'random test';

			$encrypted1 = Cipher::encrypt($text);
			$encrypted2 = Cipher::encrypt($text);

			// Should be different due to random IV
			expect($encrypted1)->not->toBe($encrypted2);
		});

		test('Cipher → encryption with custom key works', function (): void {
			$text      = 'custom key encryption';
			$customKey = 'custom-encryption-key-256-bits-long-key';

			$encrypted = Cipher::encrypt($text, $customKey);
			$decrypted = Cipher::decrypt($encrypted, $customKey);

			expect($decrypted)->toBe($text);
		});

		test('Cipher → wrong key fails decryption', function (): void {
			$text       = 'encrypted data';
			$correctKey = 'correct-key-12345678901234567890123456';
			$wrongKey   = 'wrong-key-09876543210987654321098765432';

			$encrypted = Cipher::encrypt($text, $correctKey);

			expect(fn (): string => Cipher::decrypt($encrypted, $wrongKey))
				->toThrow(Exception::class, 'Decryption failed');
		});

		test('Cipher → handles empty string encryption', function (): void {
			$encrypted = Cipher::encrypt('');
			$decrypted = Cipher::decrypt($encrypted);

			expect($decrypted)->toBe('');
		});

		test('Cipher → handles long text encryption', function (): void {
			$longText = str_repeat('Long encrypted text content. ', 200);

			$encrypted = Cipher::encrypt($longText);
			$decrypted = Cipher::decrypt($encrypted);

			expect($decrypted)->toBe($longText);
		});

		test('Cipher → handles special characters in encryption', function (): void {
			$specialText = '!@#$%^&*()_+-=[]{}|;:,.<>?~`"\'\\';

			$encrypted = Cipher::encrypt($specialText);
			$decrypted = Cipher::decrypt($encrypted);

			expect($decrypted)->toBe($specialText);
		});

		test('Cipher → handles unicode characters in encryption', function (): void {
			$unicodeText = 'Encrypted 世界 🔐 café naïve résumé';

			$encrypted = Cipher::encrypt($unicodeText);
			$decrypted = Cipher::decrypt($encrypted);

			expect($decrypted)->toBe($unicodeText);
		});

		test('Cipher → throws exception for invalid encrypted data', function (): void {
			// Test with valid base64 but insufficient data (less than IV length)
			expect(fn (): string => Cipher::decrypt(base64_encode('short')))
				->toThrow(Exception::class);
		});
	});

	describe('Constants and Defaults', function (): void {
		test('Cipher → has default SALT constant', function (): void {
			expect(Cipher::SALT)->toBeString();
			expect(Cipher::SALT)->not->toBeEmpty();
		});

		test('Cipher → default salt works with obfuscation', function (): void {
			$text = 'default salt test';

			$obfuscated   = Cipher::obfuscate($text);
			$deobfuscated = Cipher::deobfuscate($obfuscated);

			expect($deobfuscated)->toBe($text);
		});

		test('Cipher → default salt works with encryption', function (): void {
			$text = 'default encryption test';

			$encrypted = Cipher::encrypt($text);
			$decrypted = Cipher::decrypt($encrypted);

			expect($decrypted)->toBe($text);
		});
	});
});
