<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\FlashInterface;
use Odan\Session\SessionInterface;
use Odan\Session\SessionManagerInterface;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Auth\Service\SessionActivityTracker;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

// The session lifecycle policy — record activity, rotate the id every quarter
// of the lifetime, expire an idle session — used to be a private method
// duplicated in AuthMiddleware and DualAuthMiddleware, and nothing tested it.
final class SessionActivityTrackerTest extends TestCase
{
	private const MAX = 1440;

	/** @return SessionInterface&SessionManagerInterface */
	private function session(?int $lastActivity): SessionInterface&SessionManagerInterface
	{
		$session = new class implements SessionInterface, SessionManagerInterface {
			/** @var array<string,mixed> */
			public array $data       = [];
			public int $regenerated  = 0;
			public int $destroyed    = 0;

			public function get(string $key, mixed $default = null): mixed
			{
				return $this->data[$key] ?? $default;
			}

			public function all(): array
			{
				return $this->data;
			}

			public function set(string $key, mixed $value): void
			{
				$this->data[$key] = $value;
			}

			public function setValues(array $values): void
			{
				$this->data = $values + $this->data;
			}

			public function has(string $key): bool
			{
				return array_key_exists($key, $this->data);
			}

			public function delete(string $key): void
			{
				unset($this->data[$key]);
			}

			public function clear(): void
			{
				$this->data = [];
			}

			public function getFlash(): FlashInterface
			{
				throw new \LogicException('not used');
			}

			public function start(): void
			{
			}

			public function isStarted(): bool
			{
				return true;
			}

			public function regenerateId(): void
			{
				$this->regenerated++;
			}

			public function destroy(): void
			{
				$this->destroyed++;
			}

			public function getId(): string
			{
				return 'id';
			}

			public function setId(string $id): void
			{
			}

			public function getName(): string
			{
				return 'n';
			}

			public function setName(string $name): void
			{
			}

			public function save(): void
			{
			}
		};
		if ($lastActivity !== null) {
			$session->set(SessionKeys::LAST_ACTIVITY, $lastActivity);
		}

		return $session;
	}

	private function tracker(SessionInterface $session, bool $persistent = false, ?PersistentLoginService $persistentLogin = null): SessionActivityTracker
	{
		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->session = ['gc_maxlifetime' => self::MAX];

		$persistentLogin ??= $this->createMock(PersistentLoginService::class);
		$persistentLogin->method('hasPersistentLoginOrCookie')->willReturn($persistent);

		return new SessionActivityTracker($session, $config, $persistentLogin);
	}

	public function testRecordsTheActivityTimestamp(): void
	{
		$session = $this->session(null);
		$this->tracker($session)->touch();

		$this->assertEqualsWithDelta(time(), $session->get(SessionKeys::LAST_ACTIVITY), 2);
		$this->assertSame(0, $session->regenerated);
		$this->assertSame(0, $session->destroyed);
	}

	public function testRotatesTheIdAfterAQuarterOfTheLifetime(): void
	{
		$session = $this->session(time() - (int)(self::MAX / 4) - 5);
		$this->tracker($session)->touch();

		$this->assertSame(1, $session->regenerated);
		$this->assertSame(0, $session->destroyed);
	}

	public function testExpiresAnIdleSessionWithoutARememberMeToken(): void
	{
		$session = $this->session(time() - self::MAX - 5);
		$session->set(SessionKeys::AUTH_USER, 'alice');
		$this->tracker($session)->touch();

		$this->assertSame(1, $session->destroyed);
		$this->assertSame([], $session->data);
	}

	public function testKeepsAnIdleSessionThatHasARememberMeTokenAndPrunesExpiredTokens(): void
	{
		$persistentLogin = $this->createMock(PersistentLoginService::class);
		$persistentLogin->expects($this->once())->method('cleanupExpiredTokens');

		$session = $this->session(time() - self::MAX - 5);
		$session->set(SessionKeys::AUTH_USER, 'alice');
		$this->tracker($session, persistent: true, persistentLogin: $persistentLogin)->touch();

		$this->assertSame(0, $session->destroyed);
		$this->assertSame('alice', $session->get(SessionKeys::AUTH_USER));
	}
}
