<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Middleware to gate OAuth server routes by edition.
 * Requires Pro edition for the OAuth authorization server feature.
 *
 * Applied to the OAuth protocol endpoints (/oauth/authorize, /oauth/token,
 * /oauth/revoke, /oauth/register) and the OAuth admin API endpoints
 * (/api/oauth-clients, /api/oauth-grants). The .well-known/* discovery
 * endpoints handle edition gating inline (they 404 on non-Pro so external
 * clients can detect the unsupported server cleanly).
 */
readonly class OAuthEditionMiddleware extends BaseEditionMiddleware
{
	protected function getFeature(): EditionFeature
	{
		return EditionFeature::OAUTH_SERVER;
	}
}
