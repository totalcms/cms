<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Data;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;

/**
 * The result of authenticating an XML-RPC call.
 *
 * `apiKey` is the authorization authority — every permission decision reads its
 * scopes. `authorName` is display attribution only, resolved from the username
 * the client sent WITHOUT verifying a password, so it can never grant access.
 */
readonly class XmlRpcIdentity
{
	public function __construct(
		public ApiKeyData $apiKey,
		public string $authorName,
	) {
	}
}
