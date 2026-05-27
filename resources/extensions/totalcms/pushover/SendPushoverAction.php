<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Pushover;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;

readonly class SendPushoverAction
{
	private const CACHE_PREFIX = 'pushover_rl_';
	private const WINDOW       = 60; // 1-minute sliding window

	private LoggerInterface $logger;

	public function __construct(
		private PushoverService $pushoverService,
		private JsonRenderer $renderer,
		private AccessManager $accessManager,
		private CacheManager $cache,
		LoggerFactory $loggerFactory,
		private int $rateLimitPerMinute = 10,
	) {
		$this->logger = $loggerFactory->addFileHandler('pushover.log')->createLogger('pushover-rate-limit');
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$ip       = $this->getClientIp($request);
		$userData = $this->accessManager->userData();
		$userId   = (string)($userData['id'] ?? '');

		if ($this->isRateLimited($ip, $userId)) {
			$this->logger->warning('Pushover rate limit exceeded', [
				'ip'     => $ip,
				'userId' => $userId !== '' ? $userId : 'anonymous',
				'limit'  => $this->rateLimitPerMinute,
				'window' => self::WINDOW,
			]);

			return $this->renderer->json(
				$response->withStatus(429),
				[
					'success' => false,
					'error'   => ['message' => 'Rate limit exceeded. Too many Pushover requests.'],
				],
			)
				->withHeader('Retry-After', (string)self::WINDOW)
				->withHeader('X-RateLimit-Limit', (string)$this->rateLimitPerMinute)
				->withHeader('X-RateLimit-Window', (string)self::WINDOW);
		}

		$this->incrementCounters($ip, $userId);

		$data = (array)$request->getParsedBody();

		if (!isset($data['message']) || $data['message'] === '') {
			return $this->renderer->json($response->withStatus(400), [
				'success' => false,
				'message' => 'message is required',
			]);
		}

		$title     = (string)($data['title'] ?? '');
		$message   = (string)$data['message'];
		$priority  = (int)($data['priority'] ?? 0);
		$sound     = (string)($data['sound'] ?? '');
		$link      = (string)($data['link'] ?? '');
		$linkTitle = (string)($data['linkTitle'] ?? '');
		$group     = (bool)($data['group'] ?? false);
		$formData  = $data['data'] ?? [];
		$image     = $data['image'] ?? [];

		if ($formData === '') {
			$formData = [];
		} elseif (is_string($formData)) {
			$formData = json_decode($formData, true);
		}

		if (!is_array($formData)) {
			return $this->renderer->json($response->withStatus(400), [
				'success' => false,
				'message' => 'data must be an array',
			]);
		}

		if (!is_array($image)) {
			$image = [];
		}

		$userData = $this->accessManager->userData();

		$result = $this->pushoverService->send(
			message   : $message,
			data      : $formData,
			user      : $userData,
			priority  : $priority,
			title     : $title,
			sound     : $sound,
			link      : $link,
			linkTitle : $linkTitle,
			image     : $image,
			group     : $group,
		);

		if (!$result->success) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, $result->toArray());
	}

	private function isRateLimited(string $ip, string $userId): bool
	{
		if ($this->rateLimitPerMinute <= 0) {
			// Limiter disabled when set to 0.
			return false;
		}

		$ipCount = $this->getCount(self::CACHE_PREFIX . 'ip_' . md5($ip));
		if ($ipCount >= $this->rateLimitPerMinute) {
			return true;
		}

		if ($userId !== '') {
			$userCount = $this->getCount(self::CACHE_PREFIX . 'user_' . md5($userId));
			if ($userCount >= $this->rateLimitPerMinute) {
				return true;
			}
		}

		return false;
	}

	private function incrementCounters(string $ip, string $userId): void
	{
		$ipKey = self::CACHE_PREFIX . 'ip_' . md5($ip);
		$this->cache->storeData($ipKey, $this->getCount($ipKey) + 1, self::WINDOW);

		if ($userId !== '') {
			$userKey = self::CACHE_PREFIX . 'user_' . md5($userId);
			$this->cache->storeData($userKey, $this->getCount($userKey) + 1, self::WINDOW);
		}
	}

	private function getCount(string $key): int
	{
		// CacheManager returns null when key is absent or every backend is
		// unreachable — collapses to zero so the limiter fails open rather
		// than locking out legitimate traffic on a broken cache.
		$count = $this->cache->getData($key);

		return is_int($count) ? $count : 0;
	}

	private function getClientIp(ServerRequestInterface $request): string
	{
		// Trust proxy-injected client-IP headers in this order: Cloudflare, then
		// X-Forwarded-For first hop. Both can be spoofed in misconfigured
		// environments, so production operators MUST front the server with a
		// trusted reverse proxy if the rate limit is load-bearing for security.
		if ($request->hasHeader('CF-Connecting-IP')) {
			return $request->getHeaderLine('CF-Connecting-IP');
		}

		if ($request->hasHeader('X-Forwarded-For')) {
			$first = explode(',', $request->getHeaderLine('X-Forwarded-For'))[0];

			return trim($first);
		}

		return $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
	}
}
