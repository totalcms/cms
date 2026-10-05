<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Auth\Exception\AccountNotActiveException;
use TotalCMS\Domain\Auth\Exception\InvalidCredentialsException;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Auth\Service\LastLoginUpdateService;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * A failed login must not say whether the account exists, and must not name
 * it: unknown identifier and wrong password are one indistinguishable error.
 */
final class LoginServiceTest extends TestCase
{
	/** @param array<string,mixed>|null $user null = identifier matches no account */
	private function service(?array $user): LoginService
	{
		$validator = $this->createMock(UserValidationService::class);
		if ($user === null) {
			$validator->method('validateUser')
				->willThrowException(new \Exception('User not found with email: ghost@example.com'));
		} else {
			$validator->method('validateUser')->willReturn($user);
		}

		$firstLogin = $this->createMock(FirstLoginChecker::class);
		$firstLogin->method('isNewInstallation')->willReturn(false);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$config       = $this->createMock(Config::class);
		$config->auth = ['collection' => 'users'];

		return new LoginService(
			$validator,
			$this->createMock(LastLoginUpdateService::class),
			$firstLogin,
			$loggerFactory,
			$config,
			new EventDispatcher(new NullLogger()),
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 *
	 * @return array<string,mixed>
	 */
	private function user(array $overrides = []): array
	{
		return $overrides + [
			'id'       => 'john-doe',
			'email'    => 'john@example.com',
			'password' => password_hash('correct', PASSWORD_DEFAULT),
			'active'   => true,
		];
	}

	private function failure(LoginService $service, string $password): \Throwable
	{
		try {
			$service->authenticate('john@example.com', $password);
		} catch (\Throwable $e) {
			return $e;
		}

		$this->fail('Login unexpectedly succeeded');
	}

	public function testUnknownAccountAndWrongPasswordFailIdentically(): void
	{
		$unknown = $this->failure($this->service(null), 'whatever');
		$wrong   = $this->failure($this->service($this->user()), 'wrong');

		$this->assertInstanceOf(InvalidCredentialsException::class, $unknown);
		$this->assertInstanceOf(InvalidCredentialsException::class, $wrong);
		$this->assertSame($unknown->getMessage(), $wrong->getMessage());

		// Neither the typed address nor the record id leaks into the message.
		$this->assertStringNotContainsString('ghost@example.com', $unknown->getMessage());
		$this->assertStringNotContainsString('john-doe', $wrong->getMessage());
	}

	public function testAccountStateIsNotRevealedWithoutThePassword(): void
	{
		$states = [
			['active' => false],
			['expiration' => '2000-01-01'],
			['maxLoginCount' => 1, 'loginCount' => 1],
		];

		foreach ($states as $state) {
			$e = $this->failure($this->service($this->user($state)), 'wrong');
			$this->assertInstanceOf(InvalidCredentialsException::class, $e);
		}
	}

	public function testAccountStateIsReportedOnceThePasswordIsVerified(): void
	{
		$inactive = $this->failure($this->service($this->user(['active' => false])), 'correct');
		$this->assertInstanceOf(AccountNotActiveException::class, $inactive);

		$expired = $this->failure($this->service($this->user(['expiration' => '2000-01-01'])), 'correct');
		$this->assertStringContainsString('has expired', $expired->getMessage());
	}

	public function testCorrectCredentialsReturnTheUser(): void
	{
		$user = $this->service($this->user())->authenticate('john@example.com', 'correct');

		$this->assertSame('john-doe', $user['id']);
	}
}
