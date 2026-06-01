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
	 */
	public function allowsPath(ApiKeyData $apiKey, string $path): bool
	{
		$path         = strtolower(ltrim($path, '/'));
		$allowedPaths = $apiKey->scopes['paths'] ?? [];

		foreach ($allowedPaths as $allowedPath) {
			$allowedPath = strtolower(ltrim((string)$allowedPath, '/'));

			if ($allowedPath === '*' || $path === $allowedPath || str_starts_with($path, $allowedPath)) {
				return true;
			}
		}

		return false;
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

	/**
	 * Check if the API key is permitted to fire automation webhooks. A single
	 * opt-in scope (`automations.fire`) gates every `auth: apiKey` webhook —
	 * not the method/path model, since automation webhooks live outside the
	 * REST API surface.
	 */
	public function canFireAutomations(ApiKeyData $apiKey): bool
	{
		return ($apiKey->scopes['automations.fire'] ?? false) === true;
	}
}
