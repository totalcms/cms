<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Security\RateLimit\RateLimiter;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Server-side limits for the public auth forms.
 *
 * Login attempts used to be counted in the visitor's session, so a bot that
 * dropped its cookie started from zero on every request: a Nov 2026 scan sent
 * 434 logins in an hour and every one was checked. The counts now live in the
 * cache, keyed by client IP.
 *
 * - Login: `auth.maxAttempts` failures from one IP lock that IP out for
 *   `auth.deniedTimeout` minutes. Each lockout within a day doubles the next
 *   one, up to a day. Accounts are never locked: that would let anyone lock
 *   an admin out by typing their email. Past ACCOUNT_SLOWDOWN_FACTOR × the
 *   limit across all IPs, each attempt on that account is slowed by a second
 *   instead, which stops guessing from many addresses.
 * - Forgot password and resend verification: `auth.mailRatePerIp` sends per
 *   IP per 15 minutes, so the forms cannot flood someone's inbox.
 * - Registration: `auth.registerRatePerIp` accounts per IP per hour.
 * - Protected downloads: `auth.downloadMaxAttempts` wrong passwords lock the
 *   IP out of download passwords, with the same doubling lockout as login.
 *
 * The mail and registration limits are plain rate limits and use the shared
 * `RateLimiter`; the login and download lockouts count failures here. Both
 * live in the cache, so a cache clear resets them, and with only APCu each
 * PHP worker counts on its own. When the cache is unreachable the counts read
 * zero and requests go through.
 */
final readonly class AuthThrottle
{
	public const REGISTER_WINDOW = 3600;

	private const MAIL_WINDOW             = 900;

	private const PREFIX                  = 'auth_throttle_';
	private const LOGIN                   = '';
	private const DOWNLOAD                = 'download_';
	private const FAILURE_WINDOW          = 900;
	private const STRIKE_WINDOW           = 86400;
	private const MAX_LOCKOUT             = 86400;
	private const ACCOUNT_SLOWDOWN_FACTOR = 5;
	private const ACCOUNT_SLOWDOWN_MICROS = 1_000_000;

	private LoggerInterface $logger;

	public function __construct(
		private CacheManager $cache,
		private RateLimiter $limiter,
		private ClientIpResolver $clientIpResolver,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Login);
	}

	/** Seconds until this client may try to log in again; 0 when it may now. */
	public function loginLockedFor(ServerRequestInterface $request): int
	{
		return $this->lockedFor(self::LOGIN, $request);
	}

	/**
	 * Count a failed login. Returns the lockout in seconds when this failure
	 * reached the limit, else 0.
	 */
	public function recordLoginFailure(ServerRequestInterface $request, string $account): int
	{
		$this->increment($this->accountKey($account), self::FAILURE_WINDOW);

		return $this->recordFailure(self::LOGIN, $request, $this->maxAttempts());
	}

	/** A good login clears that client's failures and the account's. */
	public function recordLoginSuccess(ServerRequestInterface $request, string $account): void
	{
		$this->clearFailures(self::LOGIN, $request);
		$this->cache->clearData($this->accountKey($account));
	}

	/** Seconds until this client may try a download password again; 0 when it may now. */
	public function downloadLockedFor(ServerRequestInterface $request): int
	{
		return $this->lockedFor(self::DOWNLOAD, $request);
	}

	/**
	 * Count a wrong password on a protected download: `auth.downloadMaxAttempts`
	 * of them lock the client out of every download password, the same way
	 * failed logins do. Returns the lockout in seconds, else 0.
	 */
	public function recordDownloadFailure(ServerRequestInterface $request): int
	{
		return $this->recordFailure(self::DOWNLOAD, $request, max(1, (int)($this->config->auth['downloadMaxAttempts'] ?? 25)));
	}

	/** A right download password clears that client's failures. */
	public function recordDownloadSuccess(ServerRequestInterface $request): void
	{
		$this->clearFailures(self::DOWNLOAD, $request);
	}

	/** Slow an account that many addresses are guessing at; never refuse it. */
	public function slowDownAccount(string $account): void
	{
		if ($this->read($this->accountKey($account)) >= $this->maxAttempts() * self::ACCOUNT_SLOWDOWN_FACTOR) {
			usleep(self::ACCOUNT_SLOWDOWN_MICROS);
		}
	}

	/**
	 * Count one forgot-password or resend-verification send by this client.
	 * False past `auth.mailRatePerIp` in 15 minutes; 0 turns the limit off.
	 */
	public function allowMail(string $form, ServerRequestInterface $request): bool
	{
		return $this->allow($form, $request, (int)($this->config->auth['mailRatePerIp'] ?? 5), self::MAIL_WINDOW);
	}

	/** Count one registration by this client. False past `auth.registerRatePerIp` in an hour; 0 turns it off. */
	public function allowRegister(ServerRequestInterface $request): bool
	{
		return $this->allow('register', $request, (int)($this->config->auth['registerRatePerIp'] ?? 20), self::REGISTER_WINDOW);
	}

	private function allow(string $action, ServerRequestInterface $request, int $limit, int $window): bool
	{
		$ip = $this->clientIpResolver->resolve($request);
		if ($this->limiter->attempt('auth-' . $action, $ip, $limit, $window)->allowed) {
			return true;
		}

		$this->logger->warning('Auth form rate limited', ['form' => $action, 'ip' => $ip]);

		return false;
	}

	private function lockedFor(string $scope, ServerRequestInterface $request): int
	{
		return max(0, $this->read($this->ipKey($scope . 'lock', $request)) - time());
	}

	private function recordFailure(string $scope, ServerRequestInterface $request, int $limit): int
	{
		$failuresKey = $this->ipKey($scope . 'fail', $request);
		if ($this->increment($failuresKey, self::FAILURE_WINDOW) < $limit) {
			return 0;
		}

		$strikes = $this->increment($this->ipKey($scope . 'strike', $request), self::STRIKE_WINDOW);
		$lockout = min(self::MAX_LOCKOUT, $this->deniedTimeout() * 2 ** min(16, $strikes - 1));

		$this->cache->storeData($this->ipKey($scope . 'lock', $request), time() + $lockout, $lockout);
		$this->cache->clearData($failuresKey);

		// One line per lockout, so fail2ban or a WAF rule can act on it.
		$this->logger->warning($scope === self::DOWNLOAD ? 'Download password locked out' : 'Login locked out', [
			'ip'      => $this->clientIpResolver->resolve($request),
			'minutes' => intdiv($lockout, 60),
			'strike'  => $strikes,
		]);

		return $lockout;
	}

	private function clearFailures(string $scope, ServerRequestInterface $request): void
	{
		$this->cache->clearData($this->ipKey($scope . 'fail', $request));
		$this->cache->clearData($this->ipKey($scope . 'strike', $request));
	}

	private function maxAttempts(): int
	{
		return max(1, (int)($this->config->auth['maxAttempts'] ?? 10));
	}

	/** Seconds; the setting is minutes. */
	private function deniedTimeout(): int
	{
		return max(1, (int)($this->config->auth['deniedTimeout'] ?? 7)) * 60;
	}

	private function ipKey(string $kind, ServerRequestInterface $request): string
	{
		return self::PREFIX . $kind . '_' . hash('xxh3', $this->clientIpResolver->resolve($request));
	}

	private function accountKey(string $account): string
	{
		return self::PREFIX . 'account_' . hash('xxh3', mb_strtolower(trim($account)));
	}

	private function increment(string $key, int $ttl): int
	{
		$count = $this->read($key) + 1;
		$this->cache->storeData($key, $count, $ttl);

		return $count;
	}

	/** Reads past dev mode: these are counters, not content. */
	private function read(string $key): int
	{
		$value = $this->cache->getOperationalData($key);

		return is_int($value) ? $value : 0;
	}
}
