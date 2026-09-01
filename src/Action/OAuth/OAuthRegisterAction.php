<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthClientPruner;
use TotalCMS\Domain\OAuth\Service\OAuthDynamicRegistrar;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

readonly class OAuthRegisterAction
{
	public function __construct(
		private OAuthDynamicRegistrar $registrar,
		private JsonRenderer $renderer,
		private Config $config,
		private OAuthActivityLogger $activityLogger,
		private OAuthClientPruner $clientPruner,
		private ClientIpResolver $clientIpResolver,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		if (($this->config->oauth['dynamicRegistration'] ?? false) !== true) {
			return $this->renderer->json($response, [
				'error'             => 'access_denied',
				'error_description' => 'Dynamic client registration is disabled on this server.',
			], 403);
		}

		// BodyParsingMiddleware parses application/json bodies into getParsedBody().
		// Fall back to decoding the raw body stream for clients that omit middleware.
		$parsed = $request->getParsedBody();
		if (!is_array($parsed)) {
			$raw    = (string)$request->getBody();
			$parsed = json_decode($raw, true);
		}
		if (!is_array($parsed)) {
			return $this->renderer->json($response, [
				'error'             => 'invalid_client_metadata',
				'error_description' => 'Request body must be a JSON object.',
			], 400);
		}
		$body = $parsed;

		try {
			$result = $this->registrar->register($body);
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, [
				'error'             => 'invalid_client_metadata',
				'error_description' => $e->getMessage(),
			], 400);
		}

		$remoteAddr = $this->clientIpResolver->resolve($request);
		$this->activityLogger->dynamicRegistration(
			(string)$result['client_id'],
			(string)$result['client_name'],
			$remoteAddr,
		);

		// The act that creates the litter also sweeps it: MCP clients register
		// a fresh client per connector add, so registration is the reliable
		// touchpoint on sites where connections are attempted but never
		// completed. The just-created client is safe — the pruner's retention
		// floor keeps anything younger than a day. Throttled + failure-proof
		// inside.
		$this->clientPruner->maybeRunDaily();

		return $this->renderer->json($response, $result, 201);
	}
}
