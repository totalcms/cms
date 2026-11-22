<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;

/**
 * On `user.login`: joins the cart a visitor started to the cart stored for
 * the customer they turn out to be.
 *
 * It has to happen here. The password and passkey logins throw the old
 * session away as soon as the login is checked, and the cart with it; the
 * event fires just before that. Once it is on the server, the cart comes
 * back in the new session the first time it is looked at.
 */
final readonly class CartLoginListener
{
	public function __construct(
		private CommerceGate $gate,
		private CommerceSettings $settings,
		private CartService $cart,
	) {
	}

	/** @param array<mixed> $payload the `user.login` payload: `user` and `collection` */
	public function __invoke(array $payload): void
	{
		$user       = $payload['user'] ?? '';
		$collection = $payload['collection'] ?? '';
		$customers  = $this->settings->customerCollection();

		// Only a customer has a stored cart: not an admin signing in to the site's own users.
		if (!is_string($user) || $user === '' || $customers === '' || $collection !== $customers || !$this->gate->allowed()) {
			return;
		}

		$this->cart->joinCustomer($collection, $user);
	}
}
