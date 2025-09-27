<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Support\Config;
use TotalCMS\Factory\LoggerFactory;

/**
 * License validation middleware that enforces license checks via JWT token validation.
 */
readonly class LicenseValidationMiddleware implements MiddlewareInterface
{

	private LoggerInterface $logger;

	public function __construct(
		private LicenseValidator $licenseValidator,
		private Config $config,
		private ResponseFactoryInterface $responseFactory,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('license.log')->createLogger('license');
	}

	/**
	 * Process the middleware.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Skip license validation
		if ( PHP_SAPI === 'cli-server'
			|| $this->config->env === 'test'
			|| $this->isAuthenticationEndpoint($request)
			|| $this->isReadOnlyRequest($request)
		) {
			return $handler->handle($request);
		}

		try {
			// Get license data (uses cache if valid, otherwise validates)
			$licenseData = $this->licenseValidator->validateLicense();

			$licenseInfo = $licenseData->toArray();
			unset($licenseInfo['validation_token']); // Remove sensitive info
			$this->logger->info('License validation attempted', $licenseInfo);

			// Check if license is valid
			if (!$licenseData->valid) {
				$message = 'Invalid license: ' . $licenseData->message;

				if ($licenseData->trial) {
					$message = 'Trial has expired. Please purchase a license to continue using Total CMS.';
				}

				$this->logger->error($message);

				return $this->createUnauthorizedResponse($message);
			}

			// If we have a JWT validation token, verify it
			if ($licenseData->validationToken !== null) {
				try {
					$this->licenseValidator->validateJwtToken($licenseData->validationToken);
					$this->logger->debug('JWT token validation passed');
				} catch (LicenseException $e) {
					$this->logger->error('JWT token validation failed', [ 'error' => $e->getMessage() ]);
					return $this->createUnauthorizedResponse('License validation failed: ' . $e->getMessage());
				}
			}

			// License and JWT validation passed - all checks completed

			$this->logger->info('License validation successful');

			// License is valid, continue with request
			return $handler->handle($request);
		} catch (LicenseException $e) {
			$this->logger->error('License exception occurred', [
				'domain' => $this->config->domain,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			return $this->createUnauthorizedResponse('License validation failed: ' . $e->getMessage());
		} catch (\Exception $e) {
			$this->logger->error('Unexpected license middleware error', [
				'domain' => $this->config->domain,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return $handler->handle($request);
		}
	}

	/** Check if this is an authentication endpoint that should always be accessible. */
	private function isAuthenticationEndpoint(ServerRequestInterface $request): bool
	{
		// Try to get route information from route context
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		$authRoutes   = ['login', 'logout'];

		if ($route !== null) {
			$routeName = $route->getName();

			// Allow authentication routes by name
			if (in_array($routeName, $authRoutes, true)) {
				return true;
			}
		}

		// Fallback to URI-based checking if route info not available
		$uri = $request->getUri()->getPath();

		foreach ($authRoutes as $endpoint) {
			if ($uri === $endpoint || str_ends_with($uri, $endpoint)) {
				return true;
			}
		}

		return false;
	}

	/** Check if request is read-only (GET, HEAD, OPTIONS). */
	private function isReadOnlyRequest(ServerRequestInterface $request): bool
	{
		$method = strtoupper($request->getMethod());

		return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
	}

	/** Create unauthorized JSON response. */
	private function createUnauthorizedResponse(string $message): ResponseInterface
	{
		$response      = $this->responseFactory->createResponse(401, 'Unauthorized');
		$errorResponse = ['error' => [ 'message' => $message ]];

		$jsonResponse = json_encode($errorResponse, JSON_THROW_ON_ERROR);
		$response->getBody()->write($jsonResponse);

		return $response->withHeader('Content-Type', 'application/json');
	}
}
