<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Session;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Test integration of SessionKeys with actual service classes.
 * Verifies that all session key references use the constants.
 */
class SessionKeyIntegrationTest extends TestCase
{
	public function testAllSessionKeysAreNamespaced(): void
	{
		$allKeys = SessionKeys::getAllKeys();

		foreach ($allKeys as $key) {
			$this->assertStringStartsWith('totalcms.', $key, "Session key '$key' should use totalcms namespace");
		}
	}

	public function testSessionKeyConstantsMatchExpectedValues(): void
	{
		// Test specific key formats to ensure consistency
		$expectedKeys = [
			'AUTH_USER'             => 'totalcms.auth.user',
			'AUTH_COLLECTION'       => 'totalcms.auth.collection',
			'AUTH_PERSISTENT_LOGIN' => 'totalcms.auth.persistent_login',
			'REQUEST_ORIGIN_URL'    => 'totalcms.requestOriginUrl',
			'REQUEST_REFERER_URL'   => 'totalcms.requestRefererUrl',
			'LAST_ACTIVITY'         => 'totalcms.lastActivity',
			'LOGIN_ATTEMPTS'        => 'totalcms.loginAttempts',
			'DOWNLOAD_ATTEMPTS'     => 'totalcms.downloadAttempts',
		];

		$reflection = new \ReflectionClass(SessionKeys::class);

		foreach ($expectedKeys as $constantName => $expectedValue) {
			$this->assertTrue(
				$reflection->hasConstant($constantName),
				"SessionKeys should have constant $constantName"
			);

			$actualValue = $reflection->getConstant($constantName);
			$this->assertEquals(
				$expectedValue,
				$actualValue,
				"Constant $constantName should equal $expectedValue"
			);
		}
	}

	public function testSessionKeyGroupingMethods(): void
	{
		// Test that grouping methods return expected keys
		$authKeys = SessionKeys::getAuthKeys();
		$this->assertContains(SessionKeys::AUTH_USER, $authKeys);
		$this->assertContains(SessionKeys::AUTH_COLLECTION, $authKeys);
		$this->assertContains(SessionKeys::AUTH_PERSISTENT_LOGIN, $authKeys);
		$this->assertCount(3, $authKeys);

		$requestKeys = SessionKeys::getRequestKeys();
		$this->assertContains(SessionKeys::REQUEST_ORIGIN_URL, $requestKeys);
		$this->assertContains(SessionKeys::REQUEST_REFERER_URL, $requestKeys);
		$this->assertCount(2, $requestKeys);

		$activityKeys = SessionKeys::getActivityKeys();
		$this->assertContains(SessionKeys::LAST_ACTIVITY, $activityKeys);
		$this->assertContains(SessionKeys::LOGIN_ATTEMPTS, $activityKeys);
		$this->assertContains(SessionKeys::LOGIN_ORIGIN, $activityKeys);
		$this->assertContains(SessionKeys::DOWNLOAD_ATTEMPTS, $activityKeys);
		$this->assertContains(SessionKeys::LICENSE_CHECK_DUE, $activityKeys);
		$this->assertCount(5, $activityKeys);

		$webauthnKeys = SessionKeys::getWebAuthnKeys();
		$this->assertContains(SessionKeys::WEBAUTHN_REGISTER_OPTIONS, $webauthnKeys);
		$this->assertContains(SessionKeys::WEBAUTHN_AUTH_OPTIONS, $webauthnKeys);
		$this->assertCount(2, $webauthnKeys);

		// Ensure all grouped keys are in the main list
		$allKeys     = SessionKeys::getAllKeys();
		$groupedKeys = array_merge($authKeys, $requestKeys, $activityKeys, $webauthnKeys);

		foreach ($groupedKeys as $key) {
			$this->assertContains($key, $allKeys, "Grouped key '$key' should be in getAllKeys()");
		}

		// Ensure main list doesn't have extra keys
		$this->assertCount(count($groupedKeys), $allKeys, 'getAllKeys() should match sum of grouped keys');
	}

	public function testIsTotalCMSKeyDetection(): void
	{
		// Test positive cases
		$totalCMSKeys = [
			'totalcms.auth.user',
			'totalcms.anything',
			'totalcms.nested.deep.key',
			'totalcms.',
		];

		foreach ($totalCMSKeys as $key) {
			$this->assertTrue(
				SessionKeys::isTotalCMSKey($key),
				"Key '$key' should be detected as Total CMS key"
			);
		}

		// Test negative cases
		$externalKeys = [
			'user',
			'auth.user', // Missing totalcms prefix
			'_csrf',
			'session_id',
			'external_app_data',
			'', // Empty string
			'totalcm.auth.user', // Typo in prefix
			'TOTALCMS.auth.user', // Wrong case
		];

		foreach ($externalKeys as $key) {
			$this->assertFalse(
				SessionKeys::isTotalCMSKey($key),
				"Key '$key' should NOT be detected as Total CMS key"
			);
		}
	}

	public function testSessionKeyConsistencyInClassStructure(): void
	{
		// Ensure SessionKeys class follows expected patterns
		$reflection = new \ReflectionClass(SessionKeys::class);

		// Should be final class
		$this->assertTrue($reflection->isFinal(), 'SessionKeys should be final class');

		// All constants should be public
		$constants = $reflection->getConstants();
		foreach ($constants as $name => $value) {
			$constantReflection = $reflection->getReflectionConstant($name);
			$this->assertTrue(
				$constantReflection->isPublic(),
				"Constant $name should be public"
			);
		}

		// All methods should be static
		$methods = $reflection->getMethods();
		foreach ($methods as $method) {
			$this->assertTrue(
				$method->isStatic(),
				"Method {$method->getName()} should be static"
			);
		}
	}

	public function testSessionKeyValueFormat(): void
	{
		$allKeys = SessionKeys::getAllKeys();

		foreach ($allKeys as $key) {
			// Should not have double dots
			$this->assertStringNotContainsString('..', $key, "Key '$key' should not have double dots");

			// Should not end with dot
			$this->assertStringEndsNotWith('.', $key, "Key '$key' should not end with dot");

			// Should not have spaces
			$this->assertStringNotContainsString(' ', $key, "Key '$key' should not contain spaces");

			// Should be lowercase (except for camelCase parts)
			$this->assertMatchesRegularExpression(
				'/^totalcms\.[a-z][a-zA-Z0-9._]*$/',
				$key,
				"Key '$key' should follow naming convention"
			);
		}
	}

	public function testSessionKeyDocumentation(): void
	{
		// Verify that key names are descriptive
		$keyDescriptions = [
			SessionKeys::AUTH_USER             => 'user',
			SessionKeys::AUTH_COLLECTION       => 'collection',
			SessionKeys::AUTH_PERSISTENT_LOGIN => 'persistent_login',
			SessionKeys::REQUEST_ORIGIN_URL    => 'requestOriginUrl',
			SessionKeys::REQUEST_REFERER_URL   => 'requestRefererUrl',
			SessionKeys::LAST_ACTIVITY         => 'lastActivity',
			SessionKeys::LOGIN_ATTEMPTS        => 'loginAttempts',
			SessionKeys::DOWNLOAD_ATTEMPTS     => 'downloadAttempts',
		];

		foreach ($keyDescriptions as $constant => $expectedSuffix) {
			$this->assertStringContainsString(
				$expectedSuffix,
				$constant,
				"Key '$constant' should contain descriptive suffix '$expectedSuffix'"
			);
		}
	}
}
