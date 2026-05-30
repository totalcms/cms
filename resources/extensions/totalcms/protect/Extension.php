<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Protect;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
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

		/** @var Config $config */
		$config = $context->get(Config::class);
		$secret = $this->resolveSecret($config->datadir);

		$context->addContainerDefinition(
			ProtectMiddleware::class,
			static fn (ContainerInterface $container): ProtectMiddleware => new ProtectMiddleware(
				$secret,
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
	 * The secret is stored at `<datadir>/.system/protect/secret` as a
	 * 64-character hex string (32 random bytes). The file is created with
	 * mode 0600 so it is readable only by the web-server user.
	 */
	private function resolveSecret(string $datadir): string
	{
		$dir  = $datadir . '/.system/protect';
		$file = $dir . '/secret';

		if (is_file($file)) {
			$contents = file_get_contents($file);
			if (is_string($contents) && strlen(trim($contents)) === 64) {
				return trim($contents);
			}
		}

		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}

		$secret = bin2hex(random_bytes(32));
		file_put_contents($file, $secret);
		chmod($file, 0600);

		return $secret;
	}
}
