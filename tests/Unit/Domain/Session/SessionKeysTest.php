<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Session;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * The session keys are read from the class constants, so a key added to
 * SessionKeys is checked here without editing a list.
 */
final class SessionKeysTest extends TestCase
{
	/**
	 * The stored names. Renaming one signs out everyone holding the old one,
	 * so a change here must be deliberate.
	 */
	public function testKeyValuesArePinned(): void
	{
		$this->assertSame([
			'AUTH_USER'                 => 'totalcms.auth.user',
			'AUTH_COLLECTION'           => 'totalcms.auth.collection',
			'AUTH_PERSISTENT_LOGIN'     => 'totalcms.auth.persistent_login',
			'IMPERSONATOR'              => 'totalcms.auth.impersonator',
			'REQUEST_ORIGIN_URL'        => 'totalcms.requestOriginUrl',
			'REQUEST_REFERER_URL'       => 'totalcms.requestRefererUrl',
			'LAST_ACTIVITY'             => 'totalcms.lastActivity',
			'LOGIN_ORIGIN'              => 'totalcms.loginOrigin',
			'LICENSE_CHECK_DUE'         => 'totalcms.licenseCheckDue',
			'WEBAUTHN_REGISTER_OPTIONS' => 'totalcms.webauthn.register_options',
			'WEBAUTHN_AUTH_OPTIONS'     => 'totalcms.webauthn.auth_options',
		], $this->keys());
	}

	public function testEveryKeyIsNamespacedAndUnique(): void
	{
		$keys = $this->keys();

		foreach ($keys as $name => $key) {
			$this->assertTrue(SessionKeys::isTotalCMSKey($key), "{$name} must start with 'totalcms.'");
			$this->assertMatchesRegularExpression('/^totalcms\.[A-Za-z0-9._]+$/', $key, "{$name} has an unexpected character");
		}

		$this->assertSame(array_values(array_unique($keys)), array_values($keys), 'Two constants share a session key');
	}

	public function testIsTotalCMSKey(): void
	{
		foreach (['totalcms.auth.user', 'totalcms.anything', 'totalcms.nested.deep.key'] as $key) {
			$this->assertTrue(SessionKeys::isTotalCMSKey($key), $key);
		}

		foreach (['user', 'auth.user', '_csrf', '', 'totalcm.auth.user', 'TOTALCMS.auth.user'] as $key) {
			$this->assertFalse(SessionKeys::isTotalCMSKey($key), $key);
		}
	}

	/** @return array<string,string> */
	private function keys(): array
	{
		/** @var array<string,string> $constants */
		$constants = (new ReflectionClass(SessionKeys::class))->getConstants();

		return $constants;
	}
}
