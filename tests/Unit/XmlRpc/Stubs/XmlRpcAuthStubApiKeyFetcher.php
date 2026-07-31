<?php

declare(strict_types=1);

namespace Tests\Unit\XmlRpc\Stubs;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;

/**
 * Returns the injected key for every path. Readonly (parent is a readonly
 * class), named + namespaced (anonymous readonly classes are PHP 8.3+; CI
 * runs the 8.2 floor). See XmlRpcStubObjectUrlBuilder for the full rationale.
 */
readonly class XmlRpcAuthStubApiKeyFetcher extends ApiKeyFetcher
{
	public function __construct(private ?ApiKeyData $key)
	{
	}

	public function validateKeyForPath(string $keyString, string $path): ?ApiKeyData
	{
		return $this->key;
	}
}
