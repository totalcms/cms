<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Automation;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;
use TotalCMS\Domain\Automation\Service\AutomationResolver;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Guards `POST /automations/{id}`. Resolves the automation's webhook trigger,
 * then enforces its `auth` mode: `apiKey` (must carry a key with the
 * `automations.fire` scope) or `none` (public, per-IP rate-limited). The
 * resolved trigger is stashed on the request so the action doesn't re-resolve.
 */
final readonly class AutomationWebhookMiddleware implements MiddlewareInterface
{
	public const ATTRIBUTE = 'automationWebhook';

	private const RATE_PREFIX = 'auto_rl_';
	private const WINDOW      = 60;

	public function __construct(
		private AutomationResolver $resolver,
		private ApiKeyAuthenticator $authenticator,
		private ApiKeyPermissionChecker $permissions,
		private CacheManager $cache,
		private JsonRenderer $renderer,
		private ResponseFactoryInterface $responseFactory,
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// The id is the final path segment (`<base>/automations/<id>`); ids are
		// slug-formatted, so the id never contains a slash.
		$id      = basename($request->getUri()->getPath());
		$webhook = $this->resolver->webhook($id);

		if ($webhook === null) {
			return $this->error(404, 'No enabled webhook automation found for this URL.');
		}

		$auth = (string)($webhook['trigger']['auth'] ?? 'apiKey');

		if ($auth === 'none') {
			$denied = $this->rateLimit($request);
			if ($denied instanceof ResponseInterface) {
				return $denied;
			}
		} else {
			$apiKey = $this->authenticator->authenticate($request);
			if (!$apiKey instanceof ApiKeyData) {
				return $this->error(401, 'Invalid or missing API key.');
			}
			if (!$this->permissions->canFireAutomations($apiKey)) {
				return $this->error(403, 'API key lacks the automations.fire permission.');
			}
		}

		return $handler->handle($request->withAttribute(self::ATTRIBUTE, $webhook));
	}

	/**
	 * Per-IP rate limit for `none`-auth webhooks. Returns a 429 response when
	 * over the limit, or null to proceed.
	 */
	private function rateLimit(ServerRequestInterface $request): ?ResponseInterface
	{
		$limit = (int)($this->config->automations['webhookPublicIpPerMinute'] ?? 60);
		if ($limit <= 0) {
			return null;
		}

		$key   = self::RATE_PREFIX . md5($this->clientIp($request));
		$count = $this->cache->getData($key);
		$count = is_int($count) ? $count : 0;

		if ($count >= $limit) {
			return $this->error(429, 'Too many requests from this IP. Slow down.')
				->withHeader('Retry-After', (string)self::WINDOW);
		}

		$this->cache->storeData($key, $count + 1, self::WINDOW);

		return null;
	}

	private function clientIp(ServerRequestInterface $request): string
	{
		if ($request->hasHeader('CF-Connecting-IP')) {
			return $request->getHeaderLine('CF-Connecting-IP');
		}
		if ($request->hasHeader('X-Forwarded-For')) {
			return trim(explode(',', $request->getHeaderLine('X-Forwarded-For'))[0]);
		}

		return $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	private function error(int $status, string $message): ResponseInterface
	{
		return $this->renderer->json($this->responseFactory->createResponse($status), ['error' => ['message' => $message]]);
	}
}
