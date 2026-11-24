<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\PayPal;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Support\HttpClientInterface;

// Sibling classes are loaded by hand: a bundled extension has no autoloader.
require_once __DIR__ . '/PayPalClient.php';
require_once __DIR__ . '/PayPalWebhookVerifier.php';
require_once __DIR__ . '/PayPalProvider.php';

/**
 * PayPal as a payment provider for the store.
 *
 * The provider is registered on every site, whether or not it has the
 * Commerce add-on, because PayPal's webhook must still be heard on a store
 * whose add-on has lapsed. A buyer is offered it only where the store is
 * open, it is switched on in Settings → Commerce, and the client id, the
 * secret and the webhook id for the mode in use are filled in.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$live     = $context->setting('mode', 'sandbox') === 'live';
		$prefix   = $live ? 'live' : 'sandbox';
		$clientId = $this->text($context->setting($prefix . 'ClientId', ''));
		$secret   = $this->text($context->setting($prefix . 'ClientSecret', ''));

		$context->addPaymentProvider(new PayPalProvider(
			// Fetched when PayPal is called, not now: the container is still being built.
			new PayPalClient(static fn (): HttpClientInterface => $context->get(HttpClientInterface::class), $clientId, $secret, $live),
			$clientId,
			$secret,
			$this->text($context->setting($prefix . 'WebhookId', '')),
			$context->logger(),
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
