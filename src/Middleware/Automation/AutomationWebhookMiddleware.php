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
use TotalCMS\Domain\Automation\Service\AutomationResolver;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Guards `POST /automations/{id}`. Resolves the automation's webhook trigger,
 * then enforces its `auth` mode: `apiKey` (must carry a key scoped to POST the
 * `/automations` endpoint) or `none` (public, per-IP rate-limited). The
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

		if ($auth === 'none' || $auth === 'sameOrigin') {
			$denied = $this->rateLimit($request);
			if ($denied instanceof ResponseInterface) {
				return $denied;
			}

			// `sameOrigin`: only browser requests from this site's own host. Stops
			// other origins' JS/forms (the browser stamps a truthful Origin that
			// JS cannot forge), but a non-browser client can spoof it — so this is
			// CSRF-grade, not a substitute for an API key.
			if ($auth === 'sameOrigin' && !$this->isSameOrigin($request)) {
				return $this->error(403, 'This webhook only accepts same-origin requests.');
			}
		} else {
			// authenticate() validates the key's method+path scope against the
			// request (via ApiKeyFetcher::validateKey), so a key that isn't scoped
			// to POST /automations is rejected here — same as the REST API.
			$apiKey = $this->authenticator->authenticate($request);
			if (!$apiKey instanceof ApiKeyData) {
				return $this->error(401, 'API key is missing, invalid, or not scoped for the automations endpoint.');
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

	/**
	 * Whether the request originates from this site's own host. Compares the
	 * browser-set Origin host (falling back to Referer) against the request host.
	 * Host-only — scheme and port are ignored, matching "same domain". Returns
	 * false when neither header is present (a non-browser caller we can't verify).
	 */
	private function isSameOrigin(ServerRequestInterface $request): bool
	{
		$host = $this->requestHost($request);
		if ($host === '') {
			return false;
		}

		foreach (['Origin', 'Referer'] as $header) {
			$value = $request->getHeaderLine($header);
			if ($value !== '') {
				// Origin is authoritative; Referer is only the fallback for
				// browsers that omit Origin on same-origin POSTs. First one present
				// decides — a present-but-mismatched Origin is a hard no.
				return strtolower((string)parse_url($value, PHP_URL_HOST)) === strtolower($host);
			}
		}

		return false;
	}

	private function requestHost(ServerRequestInterface $request): string
	{
		if ($request->hasHeader('X-Forwarded-Host')) {
			return trim(explode(',', $request->getHeaderLine('X-Forwarded-Host'))[0]);
		}

		return $request->getUri()->getHost();
	}

	private function error(int $status, string $message): ResponseInterface
	{
		return $this->renderer->json($this->responseFactory->createResponse($status), ['error' => ['message' => $message]]);
	}
}
