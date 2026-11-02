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
	public const IMPERSONATOR          = 'totalcms.auth.impersonator';

	// Request Tracking Keys
	public const REQUEST_ORIGIN_URL  = 'totalcms.requestOriginUrl';
	public const REQUEST_REFERER_URL = 'totalcms.requestRefererUrl';

	// Activity Keys (login and download attempts are counted per IP on the
	// server by AuthThrottle, never in the session)
	public const LAST_ACTIVITY = 'totalcms.lastActivity';
	public const LOGIN_ORIGIN  = 'totalcms.loginOrigin';

	// Deferred work flags
	public const LICENSE_CHECK_DUE = 'totalcms.licenseCheckDue';

	// WebAuthn Keys
	public const WEBAUTHN_REGISTER_OPTIONS = 'totalcms.webauthn.register_options';
	public const WEBAUTHN_AUTH_OPTIONS     = 'totalcms.webauthn.auth_options';

	// Security Keys (Note: CSRF uses its own SESSION_KEY constant)
	// public const CSRF_TOKENS = '_csrf'; // Managed by CSRFTokenManager

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
}
