<?php

declare(strict_types=1);

namespace Tests\Unit\XmlRpc\Stubs;

use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * Reports every id absent — BlogRegistryTest never exercises
 * `resolveForPost()` (pinned at the feature level in
 * XmlRpcPostResolutionTest.php), so this keeps the constructor honest without
 * real storage. Readonly + named + namespaced: see XmlRpcStubObjectUrlBuilder.
 */
readonly class BlogRegistryStubObjectFetcher extends ObjectFetcher
{
	public function __construct()
	{
	}

	public function existsObject(string $collection, string $id): bool
	{
		return false;
	}
}
