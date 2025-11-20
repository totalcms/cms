<?php

namespace TotalCMS\Action\Emergency;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Emergency license cache clearing action that bypasses normal middleware and authentication.
 * This provides a way to clear the license cache when debugging license issues.
 *
 * Publicly accessible for support and debugging scenarios where you need to
 * completely reset the license state before triggering a fresh validation.
 */
readonly class EmergencyLicenseCacheClearAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private LicenseValidator $licenseValidator,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			// Clear the license cache
			$this->licenseValidator->clearCache();

			return $this->renderer->json($response, [
				'success'   => true,
				'message'   => 'License cache cleared successfully',
				'timestamp' => date('Y-m-d H:i:s'),
				'next_step' => 'Visit /admin/utils/license-manager to trigger a fresh license check',
			]);
		} catch (\Throwable $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => 'Failed to clear license cache',
				'details' => $e->getMessage(),
			], 500);
		}
	}
}
