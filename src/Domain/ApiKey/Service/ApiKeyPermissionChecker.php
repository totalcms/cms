<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Service;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;

/**
 * API Key Permission Checker Service.
 *
 * Handles authorization logic for API keys - checking if a key allows
 * specific HTTP methods and paths.
 */
readonly class ApiKeyPermissionChecker
{
	/**
	 * Check if the API key allows a specific HTTP method.
	 */
	public function allowsMethod(ApiKeyData $apiKey, string $method): bool
	{
		return in_array(strtoupper($method), $apiKey->scopes['methods'] ?? [], true);
	}

	/**
	 * Check if the API key allows access to a specific path.
	 *
	 * Matches using flexible pattern matching to handle:
	 * - Direct paths: "/collections/text"
	 * - Child paths: "/collections/text/123"
	 * - Case-insensitive matching
	 *
	 * A granted path only matches on a segment boundary: "/collections/blog"
	 * grants "/collections/blog" and "/collections/blog/123", but must NOT
	 * grant "/collections/blog-archive" or "/collections/blogroll" merely
	 * because the string happens to start with the granted path.
	 */
	public function allowsPath(ApiKeyData $apiKey, string $path): bool
	{
		$path         = $this->normalize($path);
		$allowedPaths = $apiKey->scopes['paths'] ?? [];

		foreach ($allowedPaths as $allowedPath) {
			$allowedPath = $this->normalize((string)$allowedPath);

			if ($allowedPath === '*' || $path === $allowedPath || str_starts_with($path, $allowedPath . '/')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The /api route-group prefix is a routing artifact, not part of the
	 * grant vocabulary: the endpoint picker stores grants without it
	 * ("/sync", "/collections/blog") while request paths carry it, and
	 * keys created directly via POST /apikeys may carry it in the grant
	 * instead. Strip it from both sides so they match regardless of shape.
	 */
	private function normalize(string $path): string
	{
		$path = strtolower(trim($path, '/'));

		if ($path === 'api' || str_starts_with($path, 'api/')) {
			return substr($path, 4);
		}

		return $path;
	}

	/**
	 * Check if the API key allows both the method and path.
	 *
	 * Convenience method that combines both checks.
	 */
	public function allows(ApiKeyData $apiKey, string $method, string $path): bool
	{
		return $this->allowsMethod($apiKey, $method) && $this->allowsPath($apiKey, $path);
	}
}
