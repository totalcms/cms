<?php

namespace TotalCMS\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Support\Config;

/**
 * License validation middleware that enforces license checks via JWT token validation.
 */
readonly class LicenseValidationMiddleware implements MiddlewareInterface
{
	private const JWT_SECRET = 'VwRmMdlSNBD1soVXlNklfzKTkXpU5Bnc4cAiQrCi3tvsHfVpz3L2XDrCxv3UImAj';

	public function __construct(
		private LicenseValidator $licenseValidator,
		private Config $config,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	/**
	 * Process the middleware.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Skip license validation in development mode
		if ($this->config->env === 'dev') {
			return $handler->handle($request);
		}

		try {
			// Get license data (uses cache if valid, otherwise validates)
			$licenseData = $this->licenseValidator->validateLicense();

			// Check if license is valid
			if (!$licenseData->valid) {
				return $this->createUnauthorizedResponse('Invalid license: ' . $licenseData->message);
			}

			// If we have a JWT validation token, verify it
			if ($licenseData->validationToken !== null) {
				$this->validateJwtToken($licenseData->validationToken);
			}

			// Check version compatibility
			if (!$this->isVersionAllowed($licenseData->allowedVersion)) {
				return $this->createUnauthorizedResponse('CMS version not allowed by license');
			}

			// Check domain authorization
			if (!$this->isDomainAuthorized($licenseData)) {
				return $this->createUnauthorizedResponse('Domain not authorized by license');
			}

			// License is valid, continue with request
			return $handler->handle($request);
		} catch (LicenseException $e) {
			return $this->createUnauthorizedResponse('License validation failed: ' . $e->getMessage());
		} catch (\Exception $e) {
			// Log the error but don't block in case of unexpected issues
			error_log('License middleware error: ' . $e->getMessage());

			return $handler->handle($request);
		}
	}

	/**
	 * Validate JWT token from license server.
	 */
	private function validateJwtToken(string $token): void
	{
		try {
			// Validate JWT token with shared secret
			$decoded = JWT::decode($token, new Key(self::JWT_SECRET, 'HS256'));

			// Basic token validation - expires_at is in ISO format
			if (isset($decoded->expires_at)) {
				$expiresAt = new \DateTime($decoded->expires_at);
				if ($expiresAt < new \DateTime()) {
					throw new LicenseException('JWT token has expired');
				}
			} elseif (isset($decoded->exp) && $decoded->exp < time()) {
				// Fallback for old-style exp claim
				throw new LicenseException('JWT token has expired');
			}

			// Validate domain in token matches current domain
			if (isset($decoded->main_domain) && $decoded->main_domain !== $this->config->domain) {
				throw new LicenseException('JWT token domain mismatch');
			} elseif (isset($decoded->domain) && $decoded->domain !== $this->config->domain) {
				// Fallback for old-style domain claim
				throw new LicenseException('JWT token domain mismatch');
			}
		} catch (\Exception $e) {
			throw new LicenseException('JWT token validation failed: ' . $e->getMessage());
		}
	}

	/**
	 * Check if current version is allowed by license.
	 */
	private function isVersionAllowed(string $allowedVersion): bool
	{
		$currentVersion = $this->getCurrentVersion();

		// For now, just check if versions match
		// TODO: Implement semantic version comparison
		return version_compare($currentVersion, $allowedVersion, '<=');
	}

	/**
	 * Check if current domain is authorized by license.
	 */
	private function isDomainAuthorized(\TotalCMS\Domain\License\Data\LicenseData $licenseData): bool
	{
		$currentDomain = $this->config->domain;

		// Check main domain
		if ($currentDomain === $licenseData->mainDomain) {
			return true;
		}

		// Check testing domains
		if (in_array($currentDomain, $licenseData->testingDomains, true)) {
			return true;
		}

		return false;
	}

	/**
	 * Get current CMS version.
	 */
	private function getCurrentVersion(): string
	{
		$versionFile = __DIR__ . '/../../version.txt';
		if (file_exists($versionFile)) {
			$content = file_get_contents($versionFile);
			if ($content !== false) {
				// Extract version from "3.0.39 (24a576e9)" format
				preg_match('/^(\d+\.\d+\.\d+)/', trim($content), $matches);

				return $matches[1] ?? '3.0.0';
			}
		}

		return '3.0.0'; // fallback version
	}

	/**
	 * Create unauthorized response.
	 */
	private function createUnauthorizedResponse(string $message): ResponseInterface
	{
		$response = $this->responseFactory->createResponse(401, 'Unauthorized');
		$response->getBody()->write($message);

		return $response->withHeader('Content-Type', 'text/plain');
	}
}
