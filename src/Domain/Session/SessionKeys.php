<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Session;

/**
 * Central registry for all Total CMS session keys.
 *
 * All session keys used by Total CMS are defined here as constants
 * to provide a single source of truth and prevent typos.
 *
 * Session keys use the 'totalcms.' namespace to avoid conflicts
 * with external application session data.
 */
final class SessionKeys
{
	// Authentication Keys
	public const AUTH_USER             = 'totalcms.auth.user';
	public const AUTH_COLLECTION       = 'totalcms.auth.collection';
	public const AUTH_PERSISTENT_LOGIN = 'totalcms.auth.persistent_login';

	// Request Tracking Keys
	public const REQUEST_ORIGIN_URL  = 'totalcms.requestOriginUrl';
	public const REQUEST_REFERER_URL = 'totalcms.requestRefererUrl';

	// Activity & Attempts Keys
	public const LAST_ACTIVITY     = 'totalcms.lastActivity';
	public const LOGIN_ATTEMPTS    = 'totalcms.loginAttempts';
	public const LOGIN_ORIGIN      = 'totalcms.loginOrigin';
	public const DOWNLOAD_ATTEMPTS = 'totalcms.downloadAttempts';

	// Deferred work flags
	public const LICENSE_CHECK_DUE = 'totalcms.licenseCheckDue';

	// WebAuthn Keys
	public const WEBAUTHN_REGISTER_OPTIONS = 'totalcms.webauthn.register_options';
	public const WEBAUTHN_AUTH_OPTIONS     = 'totalcms.webauthn.auth_options';

	// Security Keys (Note: CSRF uses its own SESSION_KEY constant)
	// public const CSRF_TOKENS = '_csrf'; // Managed by CSRFTokenManager

	/**
	 * Get all Total CMS session keys.
	 *
	 * @return array<string> List of all session keys used by Total CMS
	 */
	public static function getAllKeys(): array
	{
		return [
			self::AUTH_USER,
			self::AUTH_COLLECTION,
			self::AUTH_PERSISTENT_LOGIN,
			self::REQUEST_ORIGIN_URL,
			self::REQUEST_REFERER_URL,
			self::LAST_ACTIVITY,
			self::LOGIN_ATTEMPTS,
			self::LOGIN_ORIGIN,
			self::DOWNLOAD_ATTEMPTS,
			self::WEBAUTHN_REGISTER_OPTIONS,
			self::WEBAUTHN_AUTH_OPTIONS,
			self::LICENSE_CHECK_DUE,
		];
	}

	/**
	 * Check if a session key belongs to Total CMS.
	 *
	 * @param string $key Session key to check
	 *
	 * @return bool True if the key is managed by Total CMS
	 */
	public static function isTotalCMSKey(string $key): bool
	{
		return str_starts_with($key, 'totalcms.');
	}

	/**
	 * Get all authentication-related keys.
	 *
	 * @return array<string> Authentication session keys
	 */
	public static function getAuthKeys(): array
	{
		return [
			self::AUTH_USER,
			self::AUTH_COLLECTION,
			self::AUTH_PERSISTENT_LOGIN,
		];
	}

	/**
	 * Get all request tracking keys.
	 *
	 * @return array<string> Request tracking session keys
	 */
	public static function getRequestKeys(): array
	{
		return [
			self::REQUEST_ORIGIN_URL,
			self::REQUEST_REFERER_URL,
		];
	}

	/**
	 * Get all activity and attempt tracking keys.
	 *
	 * @return array<string> Activity tracking session keys
	 */
	public static function getActivityKeys(): array
	{
		return [
			self::LAST_ACTIVITY,
			self::LOGIN_ATTEMPTS,
			self::LOGIN_ORIGIN,
			self::DOWNLOAD_ATTEMPTS,
		];
	}

	/**
	 * Get all WebAuthn/passkey-related keys.
	 *
	 * @return array<string> WebAuthn session keys
	 */
	public static function getWebAuthnKeys(): array
	{
		return [
			self::WEBAUTHN_REGISTER_OPTIONS,
			self::WEBAUTHN_AUTH_OPTIONS,
		];
	}
}
