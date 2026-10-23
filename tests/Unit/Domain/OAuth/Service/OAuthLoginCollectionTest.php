<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthLoginCollection;
use TotalCMS\Support\Config;

final class OAuthLoginCollectionTest extends TestCase
{
	/** @var list<array<string,mixed>> */
	private array $logged = [];

	private function service(string $loginCollection): OAuthLoginCollection
	{
		$config        = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth  = ['collection' => 'auth'];
		$config->oauth = ['loginCollection' => $loginCollection];

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/** @param list<array<string,mixed>> $records */
			public function __construct(private array &$records)
			{
			}

			public function log($level, \Stringable|string $message, array $context = []): void
			{
				$this->records[] = $context;
			}
		};

		return new OAuthLoginCollection($config, new OAuthActivityLogger($logger));
	}

	public function testUnsetAcceptsEveryCollectionAndNeverFallsBack(): void
	{
		$service = $this->service('');

		$this->assertSame('', $service->configured());
		$this->assertTrue($service->accepts('vendors', 'pat', 'c1'));
		$this->assertFalse($service->fallsBackToDefault('', '/oauth/authorize?client_id=c1'));
	}

	public function testTheDefaultCollectionCountsAsUnset(): void
	{
		$this->assertSame('', $this->service('auth')->configured());
	}

	public function testSetAcceptsItselfAndTheDefaultCollectionOnly(): void
	{
		$service = $this->service('members');

		$this->assertTrue($service->accepts('members', 'jane', 'c1'));
		$this->assertTrue($service->accepts('auth', 'admin', 'c1'));
		$this->assertTrue($service->accepts('', 'admin', 'c1'));
		$this->assertFalse($service->accepts('vendors', 'pat', 'c1'));
		$this->assertSame('login_collection.rejected', $this->logged[0]['type'] ?? null);
		$this->assertSame('vendors', $this->logged[0]['collection'] ?? null);
	}

	public function testFallsBackOnlyOnItsOwnLoginPageDuringAnOAuthFlow(): void
	{
		$service = $this->service('members');

		$this->assertTrue($service->fallsBackToDefault('members', 'https://example.test/oauth/authorize?client_id=c1'));
		$this->assertFalse($service->fallsBackToDefault('members', '/admin'));
		$this->assertFalse($service->fallsBackToDefault('members', ''));
		$this->assertFalse($service->fallsBackToDefault('vendors', '/oauth/authorize?client_id=c1'));
	}
}
