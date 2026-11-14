<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Protect;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Builder\Exception\PageResponseException;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\ExtensionStorage;
use TotalCMS\Support\Config;
use TotalCMS\Support\GlobalsRequestCreator;
use Twig\TwigFunction;

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

		// Admin-written HTML around the form: above it, and at the bottom of the page.
		$headerHtml = (string)$context->setting('headerHtml', '');
		$footerHtml = (string)$context->setting('footerHtml', '');

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
				$headerHtml,
				$footerHtml,
			),
		);

		$context->addPageMiddleware('protect', ProtectMiddleware::class);

		// The same gate for a page with no builder-pages record to tick the feature
		// on: Stacks pages, which Apache serves without the Slim pipeline.
		$context->addTwigFunction(new TwigFunction(
			'protect_page',
			static function (array $options = []) use ($context): string {
				try {
					$context->get(ProtectMiddleware::class)->gate(
						(new GlobalsRequestCreator())->createServerRequestFromGlobals(),
						$options,
					);
				} catch (PageResponseException $answer) {
					throw $answer;
				} catch (\Throwable $e) {
					// ExtensionGuard turns anything else into an empty string, and the
					// page would render. A gate that cannot decide stays shut.
					$context->logger()->error('Protect failed, page withheld: ' . $e->getMessage(), ['exception' => $e]);

					throw new PageResponseException(self::withheld());
				}

				return '';
			},
		));
	}

	public function boot(ExtensionContext $context): void
	{
	}

	private static function withheld(): ResponseInterface
	{
		$factory = new Psr17Factory();

		return $factory->createResponse(500)
			->withHeader('Content-Type', 'text/plain; charset=utf-8')
			->withBody($factory->createStream('This page is unavailable right now.'));
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
