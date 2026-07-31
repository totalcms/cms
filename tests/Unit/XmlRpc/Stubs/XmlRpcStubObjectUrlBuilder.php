<?php

declare(strict_types=1);

namespace Tests\Unit\XmlRpc\Stubs;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;

/**
 * `ObjectUrlBuilder` is a `readonly class`, so this double must itself be
 * `readonly` — a non-readonly class cannot extend a readonly one. The XmlRpc
 * doubles are NAMED, NAMESPACED classes (not anonymous ones inside the test
 * files): `new readonly class` syntax is PHP 8.3+ while the project floor and
 * CI run 8.2, and the sniffer requires named classes to carry a namespace.
 */
readonly class XmlRpcStubObjectUrlBuilder extends ObjectUrlBuilder
{
	public function __construct()
	{
	}

	public function buildUrl(CollectionData $collectionData, array $object): string
	{
		return 'https://demo.test/blog/' . ($object['id'] ?? '');
	}
}
