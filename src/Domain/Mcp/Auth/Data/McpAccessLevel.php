<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Data;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyGrants;
use TotalCMS\Domain\Auth\Data\UserAuthority;

/**
 * The `mcp.access` level a collection, data view, prompt or tool is exposed
 * at, and the one place that decides which personas it lets in.
 *
 * `public` — everyone, including anonymous connections.
 * `authenticated` — any OAuth-authenticated connection, plus API keys and
 *   admins.
 * `apikey` — API keys limited to some collections or views, plus admins. A
 *   key still reaches only what its paths name; this level only stops a
 *   collection or view being closed to keys altogether.
 * `admin` — admin connections only (an All endpoints key, a super admin).
 *
 * This rule used to be a three-arm `match` copied into nine classes, and the
 * copies disagreed on what an unrecognised value meant: most read it as
 * `admin`, one read it as "nobody". Unknown strings fail closed to ADMIN
 * here, so a malformed setting can never widen exposure — and cannot lock
 * an admin out either.
 */
enum McpAccessLevel: string
{
	case PUBLIC_       = 'public';
	case AUTHENTICATED = 'authenticated';
	case API_KEY       = 'apikey';
	case ADMIN         = 'admin';

	/**
	 * Resolve a raw setting value. Anything that is not one of the four
	 * levels — including '' and null — is treated as `admin`.
	 */
	public static function fromString(?string $access): self
	{
		return self::tryFrom((string)$access) ?? self::ADMIN;
	}

	/**
	 * $apiKey says the caller is an API key limited to some collections or
	 * views (an AUTHENTICATED persona); an All endpoints key is ADMIN.
	 */
	public function allows(McpPersona $persona, bool $apiKey = false): bool
	{
		return match ($this) {
			self::PUBLIC_       => true,
			self::AUTHENTICATED => $persona !== McpPersona::PUBLIC_,
			self::API_KEY       => $persona === McpPersona::ADMIN || ($apiKey && $persona === McpPersona::AUTHENTICATED),
			self::ADMIN         => $persona === McpPersona::ADMIN,
		};
	}

	/**
	 * Whether data view $viewId, exposed at this level, is open to the
	 * caller: the level must let it in, a limited key ($limitedKey) must
	 * name the view in its paths, and an OAuth member's groups must grant
	 * data views ($authority), as on REST. A public view needs neither.
	 *
	 * A view's data is built with full trust, so the per-collection grants
	 * and ownership that narrow a member's collection reads do not apply to
	 * it: the data views grant is the whole gate.
	 */
	public function allowsView(McpPersona $persona, ?ApiKeyData $limitedKey, string $viewId, ?UserAuthority $authority): bool
	{
		if (!$this->allows($persona, $limitedKey instanceof ApiKeyData)) {
			return false;
		}
		if ($this === self::PUBLIC_ || $persona !== McpPersona::AUTHENTICATED) {
			return true;
		}

		return $limitedKey instanceof ApiKeyData
			? ApiKeyGrants::reachesView($limitedKey, $viewId)
			: $authority instanceof UserAuthority && $authority->canDataViews();
	}
}
