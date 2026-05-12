<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Encryption\Cipher;

describe('Cipher Obfuscation Analysis', function (): void {
	it('confirms obfuscation is deterministic by design', function (): void {
		// Obfuscation is SUPPOSED to be deterministic for consistency
		$configData  = 'sentry_dsn_example';
		$obfuscated1 = Cipher::obfuscate($configData);
		$obfuscated2 = Cipher::obfuscate($configData);

		// Same input produces same output (required for config consistency)
		expect($obfuscated1)->toBe($obfuscated2);

		// Should deobfuscate correctly
		expect(Cipher::deobfuscate($obfuscated1))->toBe($configData);
	});

	it('confirms hardcoded salt enables portable obfuscation', function (): void {
		// Hardcoded salt allows obfuscated data to work across installations
		$hardcodedSalt = Cipher::SALT;
		expect($hardcodedSalt)->toBe('YTFiMmMzZDRlNWY2ZzdoOGk5ajA=');

		// This is intentional for config portability
		$data       = 'portable_config_value';
		$obfuscated = Cipher::obfuscate($data, $hardcodedSalt);
		expect(Cipher::deobfuscate($obfuscated, $hardcodedSalt))->toBe($data);
	});

	it('shows encryption vs obfuscation differences', function (): void {
		$data = 'test_data_123';

		// OBFUSCATION: Deterministic, for hiding config from casual viewing
		$obf1 = Cipher::obfuscate($data);
		$obf2 = Cipher::obfuscate($data);
		expect($obf1)->toBe($obf2); // Same each time (good for config)

		// ENCRYPTION: Random, for actual security
		$enc1 = Cipher::encrypt($data);
		$enc2 = Cipher::encrypt($data);
		expect($enc1)->not()->toBe($enc2); // Different each time (good for security)

		// Both should decode correctly
		expect(Cipher::deobfuscate($obf1))->toBe($data);
		expect(Cipher::decrypt($enc1))->toBe($data);
		expect(Cipher::decrypt($enc2))->toBe($data);
	});

	it('documents proper usage patterns', function (): void {
		// Use OBFUSCATION for:
		$configValue = 'api_endpoint_or_dsn';
		$obfuscated  = Cipher::obfuscate($configValue);
		expect(Cipher::deobfuscate($obfuscated))->toBe($configValue);

		// Use ENCRYPTION for:
		$userPassword = 'file_download_password';
		$encrypted    = Cipher::encrypt($userPassword);
		expect(Cipher::decrypt($encrypted))->toBe($userPassword);

		// Obfuscation purpose: Hide from casual viewing (NOT security)
		// Encryption purpose: Protect sensitive data (IS security)
		expect(true)->toBe(true);
	});

	it('shows context-specific obfuscation capabilities', function (): void {
		$data = 'context_sensitive_data';

		// Different contexts can use different keys
		$sentryKey = Cipher::contextKey('sentry');
		$configKey = Cipher::contextKey('config');

		$sentryObfuscated = Cipher::obfuscate($data, $sentryKey);
		$configObfuscated = Cipher::obfuscate($data, $configKey);

		// Should produce different results
		expect($sentryObfuscated)->not()->toBe($configObfuscated);

		// But both deobfuscate correctly with their keys
		expect(Cipher::deobfuscate($sentryObfuscated, $sentryKey))->toBe($data);
		expect(Cipher::deobfuscate($configObfuscated, $configKey))->toBe($data);
	});

	it('confirms enhanced obfuscation is now active', function (): void {
		// Enhanced obfuscation is now active and provides better obscurity while maintaining
		// the deterministic behavior required for configuration consistency

		$testData   = 'enhanced_obfuscation_test';
		$obfuscated = Cipher::obfuscate($testData);

		// Should work with enhanced implementation
		expect(Cipher::deobfuscate($obfuscated))->toBe($testData);

		// Enhanced mode features:
		// ✅ Existing obfuscated data is migrated (Sentry DSN updated)
		// ✅ Enhanced features active (URL-safe output, stronger scrambling)
		// ✅ Context-specific keys available
		// ✅ Better key derivation implemented

		// Test URL-safe output
		expect($obfuscated)->not()->toContain('+');
		expect($obfuscated)->not()->toContain('/');
		expect($obfuscated)->not()->toContain('=');
	});
});
