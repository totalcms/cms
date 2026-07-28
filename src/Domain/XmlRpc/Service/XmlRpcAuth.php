<?php

declare(strict_types=1);

namespace TotalCMS\Domain\XmlRpc\Service;

use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\XmlRpc\Data\XmlRpcIdentity;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;
use TotalCMS\Support\Config;

/**
 * Authenticates XML-RPC calls.
 *
 * Credentials arrive inside the XML body, so no middleware can see them and
 * this runs per method instead. The `password` param is a T3 API key; the
 * `username` param is resolved to an auth user for author attribution ONLY —
 * no password is verified, which is deliberate: a password check at this path
 * would be a credential oracle at a URL bots probe by default, and T3 has no
 * login throttling to blunt it.
 */
readonly class XmlRpcAuth
{
	/**
	 * Scope path every call is checked against, regardless of which route
	 * served it. One "XML-RPC publishing" grant therefore covers both endpoint
	 * shapes and operators never reason about which URL their client uses.
	 */
	public const SCOPE_PATH = '/xmlrpc.php';

	public function __construct(
		private ApiKeyFetcher $apiKeyFetcher,
		private EditionFeatureService $editionFeatures,
		private UserValidationService $userValidation,
		private Config $config,
	) {
	}

	/**
	 * @param array<int,mixed> $params
	 * @param int              $userIndex Position of the username param
	 * @param int              $passIndex Position of the password param
	 */
	public function authenticate(array $params, int $userIndex = 1, int $passIndex = 2): XmlRpcIdentity
	{
		$username = is_string($params[$userIndex] ?? null) ? $params[$userIndex] : '';
		$password = is_string($params[$passIndex] ?? null) ? $params[$passIndex] : '';

		if ($password === '') {
			throw XmlRpcFault::badCredentials();
		}

		$apiKey = $this->apiKeyFetcher->validateKey($password, 'POST', self::SCOPE_PATH);

		if ($apiKey === null) {
			throw XmlRpcFault::badCredentials();
		}

		if (!$this->editionFeatures->can(EditionFeature::EXTERNAL_REST_API)) {
			throw XmlRpcFault::forbidden('XML-RPC publishing requires the Pro edition or higher.');
		}

		return new XmlRpcIdentity($apiKey, $this->resolveAuthor($username, $apiKey->name));
	}

	/**
	 * Map an RPC operation onto the key's HTTP method scopes, so a read-only key
	 * genuinely cannot delete a post.
	 */
	public function assertOperation(XmlRpcIdentity $identity, string $httpMethod): void
	{
		$allowed = $identity->apiKey->scopes['methods'] ?? [];

		if (!is_array($allowed) || !in_array(strtoupper($httpMethod), array_map('strtoupper', $allowed), true)) {
			throw XmlRpcFault::forbidden(sprintf(
				'This API key is not permitted to perform %s operations.',
				strtoupper($httpMethod)
			));
		}
	}

	/**
	 * Resolve a display name for the post author. A username that does not
	 * resolve must NOT fail the call — it is attribution, not authorization —
	 * and `validateUser()` throws on a miss, hence the catch.
	 */
	private function resolveAuthor(string $username, string $fallback): string
	{
		if ($username === '') {
			return $fallback;
		}

		try {
			$user = $this->userValidation->validateUser($username, (string)($this->config->auth['collection'] ?? ''));
		} catch (\Throwable) {
			return $fallback;
		}

		$name = is_string($user['name'] ?? null) ? trim($user['name']) : '';
		if ($name !== '') {
			return $name;
		}

		return is_string($user['id'] ?? null) && $user['id'] !== '' ? $user['id'] : $fallback;
	}
}
