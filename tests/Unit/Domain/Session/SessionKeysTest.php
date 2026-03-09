<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Session;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Test SessionKeys constants and utility methods.
 */
class SessionKeysTest extends TestCase
{
	public function testSessionKeysConstants(): void
	{
		// Test that all constants are defined and have correct format
		$this->assertEquals('totalcms.auth.user', SessionKeys::AUTH_USER);
		$this->assertEquals('totalcms.auth.collection', SessionKeys::AUTH_COLLECTION);
		$this->assertEquals('totalcms.auth.persistent_login', SessionKeys::AUTH_PERSISTENT_LOGIN);
		$this->assertEquals('totalcms.requestOriginUrl', SessionKeys::REQUEST_ORIGIN_URL);
		$this->assertEquals('totalcms.requestRefererUrl', SessionKeys::REQUEST_REFERER_URL);
		$this->assertEquals('totalcms.lastActivity', SessionKeys::LAST_ACTIVITY);
		$this->assertEquals('totalcms.loginAttempts', SessionKeys::LOGIN_ATTEMPTS);
		$this->assertEquals('totalcms.downloadAttempts', SessionKeys::DOWNLOAD_ATTEMPTS);
	}

	public function testGetAllKeys(): void
	{
		$allKeys = SessionKeys::getAllKeys();

		$this->assertIsArray($allKeys);
		$this->assertCount(11, $allKeys);

		// Ensure all expected keys are present
		$this->assertContains(SessionKeys::AUTH_USER, $allKeys);
		$this->assertContains(SessionKeys::AUTH_COLLECTION, $allKeys);
		$this->assertContains(SessionKeys::AUTH_PERSISTENT_LOGIN, $allKeys);
		$this->assertContains(SessionKeys::REQUEST_ORIGIN_URL, $allKeys);
		$this->assertContains(SessionKeys::REQUEST_REFERER_URL, $allKeys);
		$this->assertContains(SessionKeys::LAST_ACTIVITY, $allKeys);
		$this->assertContains(SessionKeys::LOGIN_ATTEMPTS, $allKeys);
		$this->assertContains(SessionKeys::LOGIN_ORIGIN, $allKeys);
		$this->assertContains(SessionKeys::DOWNLOAD_ATTEMPTS, $allKeys);
		$this->assertContains(SessionKeys::WEBAUTHN_REGISTER_OPTIONS, $allKeys);
		$this->assertContains(SessionKeys::WEBAUTHN_AUTH_OPTIONS, $allKeys);
	}

	public function testIsTotalCMSKey(): void
	{
		// Test Total CMS keys
		$this->assertTrue(SessionKeys::isTotalCMSKey('totalcms.auth.user'));
		$this->assertTrue(SessionKeys::isTotalCMSKey('totalcms.anything'));
		$this->assertTrue(SessionKeys::isTotalCMSKey(SessionKeys::AUTH_USER));

		// Test external keys
		$this->assertFalse(SessionKeys::isTotalCMSKey('user'));
		$this->assertFalse(SessionKeys::isTotalCMSKey('external_session_key'));
		$this->assertFalse(SessionKeys::isTotalCMSKey('_csrf'));
		$this->assertFalse(SessionKeys::isTotalCMSKey(''));
	}

	public function testGetAuthKeys(): void
	{
		$authKeys = SessionKeys::getAuthKeys();

		$this->assertIsArray($authKeys);
		$this->assertCount(3, $authKeys);
		$this->assertContains(SessionKeys::AUTH_USER, $authKeys);
		$this->assertContains(SessionKeys::AUTH_COLLECTION, $authKeys);
		$this->assertContains(SessionKeys::AUTH_PERSISTENT_LOGIN, $authKeys);
	}

	public function testGetRequestKeys(): void
	{
		$requestKeys = SessionKeys::getRequestKeys();

		$this->assertIsArray($requestKeys);
		$this->assertCount(2, $requestKeys);
		$this->assertContains(SessionKeys::REQUEST_ORIGIN_URL, $requestKeys);
		$this->assertContains(SessionKeys::REQUEST_REFERER_URL, $requestKeys);
	}

	public function testGetActivityKeys(): void
	{
		$activityKeys = SessionKeys::getActivityKeys();

		$this->assertIsArray($activityKeys);
		$this->assertCount(4, $activityKeys);
		$this->assertContains(SessionKeys::LAST_ACTIVITY, $activityKeys);
		$this->assertContains(SessionKeys::LOGIN_ATTEMPTS, $activityKeys);
		$this->assertContains(SessionKeys::LOGIN_ORIGIN, $activityKeys);
		$this->assertContains(SessionKeys::DOWNLOAD_ATTEMPTS, $activityKeys);
	}

	public function testAllKeysUseCorrectNamespace(): void
	{
		$allKeys = SessionKeys::getAllKeys();

		foreach ($allKeys as $key) {
			$this->assertStringStartsWith('totalcms.', $key, "Key '$key' should start with 'totalcms.' namespace");
			$this->assertTrue(SessionKeys::isTotalCMSKey($key), "Key '$key' should be identified as a Total CMS key");
		}
	}
}
