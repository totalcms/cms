<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Protect;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\ExtensionStorage;
use TotalCMS\Support\Config;

require_once __DIR__ . '/ProtectMiddleware.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$defaultPasscode    = (string)$context->setting('passcode', '');
		$defaultPromptTitle = (string)$context->setting('promptTitle', 'Enter passcode to view');

		// Cookie lifetime is configured in hours (168 = 7 days); 0 = session cookie.
		// The middleware works in seconds, so convert here.
		$cookieHours = (int)$context->setting('cookieHours', 168);
		$cookieTtl   = max(0, $cookieHours) * 3600;

		// Site-wide mode: one shared passcode + cookie for every protected page.
		$globalScope = (bool)$context->setting('globalScope', false);

		$storage = $context->storage();

		$context->addContainerDefinition(
			ProtectMiddleware::class,
			fn (ContainerInterface $container): ProtectMiddleware => new ProtectMiddleware(
				// Resolved here — inside the factory — so the secret file is
				// only read (or created) when a page actually opts into the
				// `protect` middleware, not on every request at register time.
				$this->resolveSecret($storage),
				$defaultPasscode,
				$defaultPromptTitle,
				// Logged-in admins/operators preview the page instead of the gate —
				// `userLoggedIn($operatorCollection)` excludes front-end members
				// (public registration) while still passing super-admins. Resolved
				// lazily and read at request time so it reflects the live session.
				static fn (): bool => $container->get(AccessManager::class)->userLoggedIn(
					(string)($container->get(Config::class)->auth['collection'] ?? ''),
				),
				$cookieTtl,
				$globalScope,
			),
		);

		$context->addPageMiddleware('protect', ProtectMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
	}

	/**
	 * Return the per-install HMAC secret, generating it if it does not exist.
	 *
	 * Stored via the extension storage API (a 64-character hex string — 32
	 * random bytes) so it lands in the protected, update-safe
	 * `.system/extension-data/totalcms/protect/` directory. A failed write
	 * throws rather than continuing with an unpersisted secret — that would
	 * mint a new secret per request, silently invalidating every unlock
	 * cookie the moment it was issued.
	 */
	private function resolveSecret(ExtensionStorage $storage): string
	{
		$existing = $storage->read('secret');
		if (is_string($existing) && strlen(trim($existing)) === 64) {
			return trim($existing);
		}

		$secret = bin2hex(random_bytes(32));
		$storage->write('secret', $secret);

		return $secret;
	}
}
