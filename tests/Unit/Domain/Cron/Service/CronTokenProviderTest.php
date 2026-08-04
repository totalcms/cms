<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cron\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cron\Service\CronTokenProvider;
use TotalCMS\Support\Config;

/**
 * The credential guarding the HTTP cron endpoints. A generated file rather than
 * a setting, because there is nothing for an operator to choose.
 *
 * The read/create split is the security-relevant part: the middleware calls
 * token() on every request including unauthenticated probes, so reading must
 * never be a way to make the server write a file.
 */
final class CronTokenProviderTest extends TestCase
{
	private string $datadir;

	protected function setUp(): void
	{
		$this->datadir = sys_get_temp_dir() . '/tcms-cron-token-' . uniqid();
	}

	protected function tearDown(): void
	{
		$token = $this->datadir . '/.system/cron-token';
		if (file_exists($token)) {
			unlink($token);
		}
		foreach ([$this->datadir . '/.system', $this->datadir] as $dir) {
			if (is_dir($dir)) {
				rmdir($dir);
			}
		}
	}

	private function provider(): CronTokenProvider
	{
		return new CronTokenProvider(new Config([
			'env'        => 'test',
			'template'   => sys_get_temp_dir(),
			'dashboard'  => [],
			'datadir'    => $this->datadir,
			'tmpdir'     => sys_get_temp_dir(),
			'cachedir'   => sys_get_temp_dir() . '/cache',
			'cache'      => [],
			'logger'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'imageworks' => [],
			'smtp'       => [],
			'mailer'     => [],
		]));
	}

	public function testTokenReturnsNullBeforeOneExists(): void
	{
		$this->assertNull($this->provider()->token());
	}

	public function testTokenNeverCreatesTheFile(): void
	{
		// The middleware calls token() on every request, including unauthenticated
		// probes. Reading must not be a way to make the server write files.
		$this->provider()->token();

		$this->assertFileDoesNotExist($this->datadir . '/.system/cron-token');
	}

	public function testTokenOrCreateGeneratesAndPersists(): void
	{
		$created = $this->provider()->tokenOrCreate();

		$this->assertNotSame('', $created);
		$this->assertSame($created, $this->provider()->token(), 'a second reader must see the same token');
	}

	public function testTokenOrCreateIsStableAcrossCalls(): void
	{
		$provider = $this->provider();

		$this->assertSame($provider->tokenOrCreate(), $provider->tokenOrCreate());
	}

	public function testTheTokenIsLongAndRandom(): void
	{
		$first = $this->provider()->tokenOrCreate();
		$this->tearDown();
		$this->setUp();
		$second = $this->provider()->tokenOrCreate();

		$this->assertNotSame($first, $second);
		$this->assertGreaterThanOrEqual(32, strlen($first));
	}

	public function testTheFileIsNotReadableByOtherUsers(): void
	{
		$this->provider()->tokenOrCreate();

		$mode = fileperms($this->datadir . '/.system/cron-token') & 0777;

		$this->assertSame(0600, $mode);
	}

	public function testRegenerateReplacesTheToken(): void
	{
		$provider = $this->provider();
		$first    = $provider->tokenOrCreate();

		$second = $provider->regenerate();

		$this->assertNotSame($first, $second);
		$this->assertSame($second, $provider->token());
	}

	public function testAnEmptyFileIsTreatedAsNoToken(): void
	{
		// A truncated or half-written file must not authenticate an empty
		// `?token=` — that would be an open endpoint.
		$provider = $this->provider();
		$provider->tokenOrCreate();
		file_put_contents($this->datadir . '/.system/cron-token', '');

		$this->assertNull($provider->token());
	}
}
