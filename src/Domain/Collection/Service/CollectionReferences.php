<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\DataView\Service\DataViewDependencyResolver;
use TotalCMS\Support\Config;

/**
 * What still points at a collection, so a delete can say what it leaves
 * dangling. None of these break the delete itself: they break later, and
 * quietly (an empty Data View, a login redirect to nowhere, a registration
 * form that 403s).
 *
 * Covers the references that can be found without reading templates or
 * every schema: Data Views built from it, other collections' owner and
 * access collection settings, and the public registration allow-list.
 */
readonly class CollectionReferences
{
	public function __construct(
		private CollectionLister $collectionLister,
		private DataViewDependencyResolver $dataViews,
		private Config $config,
	) {
	}

	/**
	 * One line per reference, e.g. "Data View 'dealer-map'".
	 *
	 * @return list<string>
	 */
	public function find(string $collection): array
	{
		$references = [];

		foreach ($this->dataViews->resolveForCollection($collection) as $view) {
			$references[] = "Data View '{$view}'";
		}

		foreach ($this->collectionLister->listAllCollections() as $other) {
			if ($other->id === $collection) {
				continue;
			}
			if ($other->ownerCollection === $collection) {
				$references[] = "Collection '{$other->id}' (owner collection)";
			}
			if ($other->accessCollection === $collection) {
				$references[] = "Collection '{$other->id}' (access collection)";
			}
		}

		$registration = $this->config->auth['publicRegistration'] ?? [];
		if (is_array($registration) && in_array($collection, $registration, true)) {
			$references[] = 'auth.publicRegistration in the site config';
		}

		return $references;
	}
}
