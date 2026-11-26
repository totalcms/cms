<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Support\Config;

/**
 * Who the store's customer is in the request in hand.
 *
 * A customer is someone signed in to the collection Settings → Commerce
 * names for customer accounts. Anyone else is a guest to the store: a
 * visitor, and also an admin signed in to the site's own users.
 */
final readonly class CurrentCustomer
{
	public function __construct(
		private CommerceSettings $settings,
		private ActorContext $actors,
		private Config $config,
	) {
	}

	/** @return array{collection: string, id: string}|null null for a guest */
	public function get(): ?array
	{
		$customers = $this->settings->customerCollection();
		$actor     = $this->actors->current();
		if ($customers === '' || $actor->userId === '') {
			return null;
		}

		$default    = $this->config->auth['collection'] ?? 'auth';
		$collection = $actor->authCollection !== '' ? $actor->authCollection : (is_string($default) ? $default : 'auth');

		return $collection === $customers ? ['collection' => $collection, 'id' => $actor->userId] : null;
	}
}
