<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;

// Sibling classes are loaded by hand: a bundled extension has no autoloader.
require_once __DIR__ . '/StripeClient.php';
require_once __DIR__ . '/StripeWebhookVerifier.php';
require_once __DIR__ . '/StripeProvider.php';

/**
 * Stripe as a payment provider for the store.
 *
 * The provider is registered on every site, whether or not it has the
 * Commerce add-on, because Stripe's webhook must still be heard on a store
 * whose add-on has lapsed. A buyer is offered it only where the store is
 * open, it is switched on in Settings → Commerce, and the secret key and
 * webhook secret for the mode in use are filled in. The publishable key only
 * decides where the buyer pays: without it, on Stripe's own page.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$live   = $context->setting('mode', 'test') === 'live';
		$key    = $this->text($context->setting($live ? 'liveSecretKey' : 'testSecretKey', ''));
		$secret = $this->text($context->setting($live ? 'liveWebhookSecret' : 'testWebhookSecret', ''));

		$public = $this->text($context->setting($live ? 'livePublishableKey' : 'testPublishableKey', ''));

		// A live key in test mode, or the other way round, is a mistake that
		// would charge real cards while the store says "test". Refuse it by
		// not being ready, which Settings → Commerce shows.
		$marker = $live ? '_live_' : '_test_';
		if ($key !== '' && !str_contains($key, $marker)) {
			$key = '';
		}
		if ($public !== '' && !str_contains($public, $marker)) {
			$public = '';
		}

		$module = __DIR__ . '/assets/embed.js';

		$context->addPaymentProvider(new StripeProvider(
			// Fetched when Stripe is called, not now: the container is still being built.
			new StripeClient(static fn (): HttpClientInterface => $context->get(HttpClientInterface::class), $key),
			$key,
			$secret,
			$this->text($context->setting('statementDescriptor', '')),
			$context->logger(),
			$public,
			// Asked when a checkout needs it: the site's config does not exist
			// yet while extensions register.
			static fn (): string => $context->get(Config::class)->api . '/api/ext/totalcms/stripe/assets/embed.js?v=' . (int)@filemtime($module),
		));
	}

	public function boot(ExtensionContext $context): void
	{
	}

	private function text(mixed $value): string
	{
		return is_string($value) ? trim($value) : '';
	}
}
