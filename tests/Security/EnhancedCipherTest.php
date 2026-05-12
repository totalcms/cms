<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Encryption\Cipher;

describe('Enhanced Cipher Implementation', function (): void {
	it('works correctly with various data types', function (): void {
		// Test various types of data commonly obfuscated
		$testCases = [
			'sentry_dsn_example',
			'api_key_12345',
			'database_connection_string',
			'https://example.com/webhook',
		];

		foreach ($testCases as $originalData) {
			// Obfuscate using current implementation
			$obfuscated = Cipher::obfuscate($originalData);

			// Should be able to deobfuscate back to original
			$deobfuscated = Cipher::deobfuscate($obfuscated);

			expect($deobfuscated)->toBe($originalData);
		}
	});

	it('supports context-specific obfuscation keys', function (): void {
		$data = 'sensitive_config_value';

		// Create different context keys
		$sentryKey    = Cipher::contextKey('sentry');
		$downloadsKey = Cipher::contextKey('downloads');
		$configKey    = Cipher::contextKey('config');

		// Each context should produce different obfuscation
		$sentryObfuscated    = Cipher::obfuscate($data, $sentryKey);
		$downloadsObfuscated = Cipher::obfuscate($data, $downloadsKey);
		$configObfuscated    = Cipher::obfuscate($data, $configKey);

		// All should be different
		expect($sentryObfuscated)->not()->toBe($downloadsObfuscated);
		expect($sentryObfuscated)->not()->toBe($configObfuscated);
		expect($downloadsObfuscated)->not()->toBe($configObfuscated);

		// But all should deobfuscate correctly with their respective keys
		expect(Cipher::deobfuscate($sentryObfuscated, $sentryKey))->toBe($data);
		expect(Cipher::deobfuscate($downloadsObfuscated, $downloadsKey))->toBe($data);
		expect(Cipher::deobfuscate($configObfuscated, $configKey))->toBe($data);
	});

	it('produces URL-safe encoded output (enhanced mode active)', function (): void {
		$testData = [
			'file_password_123',
			'special+chars/in=data',
			'unicode_data_ñáéíóú',
		];

		foreach ($testData as $data) {
			$obfuscated = Cipher::obfuscate($data);

			// Enhanced mode uses URL-safe base64 (no +, /, = characters)
			expect($obfuscated)->not()->toContain('+');
			expect($obfuscated)->not()->toContain('/');
			expect($obfuscated)->not()->toContain('=');

			// Should be reversible
			expect(Cipher::deobfuscate($obfuscated))->toBe($data);
		}
	});

	it('encryption methods remain cryptographically secure', function (): void {
		$sensitiveData = 'file_download_password_123';

		// Encrypt twice - should produce different results (random IV)
		$encrypted1 = Cipher::encrypt($sensitiveData);
		$encrypted2 = Cipher::encrypt($sensitiveData);

		expect($encrypted1)->not()->toBe($encrypted2);

		// Both should decrypt to original data
		expect(Cipher::decrypt($encrypted1))->toBe($sensitiveData);
		expect(Cipher::decrypt($encrypted2))->toBe($sensitiveData);
	});

	it('demonstrates proper usage patterns', function (): void {
		// Configuration obfuscation (deterministic, for hiding config values)
		$sentryDsn     = 'https://key@sentry.io/project';
		$obfuscatedDsn = Cipher::obfuscate($sentryDsn);
		expect(Cipher::deobfuscate($obfuscatedDsn))->toBe($sentryDsn);

		// File password encryption (secure, for protecting access)
		$filePassword      = 'secret_file_access_123';
		$encryptedPassword = Cipher::encrypt($filePassword);
		expect(Cipher::decrypt($encryptedPassword))->toBe($filePassword);

		// Context-specific obfuscation
		$contextKey        = Cipher::contextKey('custom_context');
		$contextObfuscated = Cipher::obfuscate('context_data', $contextKey);
		expect(Cipher::deobfuscate($contextObfuscated, $contextKey))->toBe('context_data');
	});

	it('obfuscation is deterministic while encryption is random', function (): void {
		$data = 'test_data_123';

		// Obfuscation should be deterministic
		$obf1 = Cipher::obfuscate($data);
		$obf2 = Cipher::obfuscate($data);
		expect($obf1)->toBe($obf2); // Same input = same output

		// Encryption should be random
		$enc1 = Cipher::encrypt($data);
		$enc2 = Cipher::encrypt($data);
		expect($enc1)->not()->toBe($enc2); // Same input = different output

		// But both should decode correctly
		expect(Cipher::deobfuscate($obf1))->toBe($data);
		expect(Cipher::decrypt($enc1))->toBe($data);
		expect(Cipher::decrypt($enc2))->toBe($data);
	});

	it('handles edge cases gracefully', function (): void {
		// Empty string
		expect(Cipher::deobfuscate(Cipher::obfuscate('')))->toBe('');

		// Long strings
		$longString = str_repeat('Long data test ', 100);
		expect(Cipher::deobfuscate(Cipher::obfuscate($longString)))->toBe($longString);

		// Special characters
		$specialChars = "!@#$%^&*()_+-=[]{}|;':\",./<>?`~";
		expect(Cipher::deobfuscate(Cipher::obfuscate($specialChars)))->toBe($specialChars);
	});

	it('confirms enhanced mode is now active', function (): void {
		// Enhanced mode is now enabled (USE_LEGACY_OBFUSCATION = false):
		// ✅ URL-safe base64 encoding (no +, /, = characters)
		// ✅ Better key derivation using SHA-256
		// ✅ Position-dependent character transformation
		// ✅ Character scrambling for additional obfuscation
		// ✅ Backward compatibility with legacy data via fallback

		$data       = 'example_config_value';
		$obfuscated = Cipher::obfuscate($data);

		// Enhanced mode provides:
		expect(Cipher::deobfuscate($obfuscated))->toBe($data); // 1. Works correctly
		expect($obfuscated)->not()->toContain('+'); // 2. URL-safe output
		expect($obfuscated)->not()->toContain('/'); // 3. URL-safe output
		expect($obfuscated)->not()->toContain('='); // 4. URL-safe output

		// Context-specific key derivation works
		$contextKey        = Cipher::contextKey('test_context');
		$contextObfuscated = Cipher::obfuscate($data, $contextKey);
		expect($contextObfuscated)->not()->toBe($obfuscated);
		expect(Cipher::deobfuscate($contextObfuscated, $contextKey))->toBe($data);
	});

	it('provides clear documentation of use cases', function (): void {
		// OBFUSCATION: Hide from casual viewing (not secure)
		$configValue = 'sentry_dsn_or_api_key';
		$obfuscated  = Cipher::obfuscate($configValue);
		expect(Cipher::deobfuscate($obfuscated))->toBe($configValue);

		// ENCRYPTION: Secure protection (cryptographically secure)
		$sensitiveData = 'user_file_password';
		$encrypted     = Cipher::encrypt($sensitiveData);
		expect(Cipher::decrypt($encrypted))->toBe($sensitiveData);

		// Use obfuscation for:
		// - Configuration files (Sentry DSN, API endpoints)
		// - Template data transformation
		// - Hiding non-sensitive data from casual viewing

		// Use encryption for:
		// - File download passwords
		// - User authentication tokens
		// - Any data requiring real security

		expect(strlen($obfuscated))->toBeGreaterThan(0);
		expect(strlen($encrypted))->toBeGreaterThan(0);
	});
});
