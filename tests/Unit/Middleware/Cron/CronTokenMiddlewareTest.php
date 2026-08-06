<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Cron;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\Cron\Service\CronTokenProvider;
use TotalCMS\Middleware\Cron\CronTokenMiddleware;
use TotalCMS\Support\Config;

/**
 * The token travels in the query string because a URL-cron facility cannot set
 * headers. A wrong token answers 404 rather than 401 so the endpoint does not
 * confirm its own existence to a scanner.
 *
 * Exercised against a real CronTokenProvider over a temp datadir rather than a
 * double — the security-relevant claim is that an unauthenticated request leaves
 * no token file behind, which is a fact about the filesystem, not about which
 * methods got called.
 */
final class CronTokenMiddlewareTest extends TestCase
{
	private string $datadir;

	protected function setUp(): void
	{
		$this->datadir = sys_get_temp_dir() . '/tcms-cron-mw-' . uniqid();
	}

	protected function tearDown(): void
	{
		if (file_exists($this->tokenPath())) {
			unlink($this->tokenPath());
		}
		foreach ([$this->datadir . '/.system', $this->datadir] as $dir) {
			if (is_dir($dir)) {
				rmdir($dir);
			}
		}
	}

	private function tokenPath(): string
	{
		return $this->datadir . '/.system/cron-token';
	}

	private function storeToken(string $token): void
	{
		mkdir($this->datadir . '/.system', 0700, true);
		file_put_contents($this->tokenPath(), $token);
	}

	private function middleware(): CronTokenMiddleware
	{
		$provider = new CronTokenProvider(new Config([
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

		return new CronTokenMiddleware($provider, new ResponseFactory());
	}

	private function handler(): RequestHandlerInterface
	{
		return new class implements RequestHandlerInterface {
			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return (new ResponseFactory())->createResponse(200);
			}
		};
	}

	private function request(string $query): ServerRequestInterface
	{
		return (new ServerRequestFactory())
			->createServerRequest('GET', 'https://site.test/cron/jobs?' . $query);
	}

	public function testTheCorrectTokenReachesTheHandler(): void
	{
		$this->storeToken('secret-token');

		$response = $this->middleware()->process($this->request('token=secret-token'), $this->handler());

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testAWrongTokenIs404NotUnauthorized(): void
	{
		$this->storeToken('secret-token');

		$response = $this->middleware()->process($this->request('token=wrong'), $this->handler());

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testAMissingTokenIsRejected(): void
	{
		$this->storeToken('secret-token');

		$response = $this->middleware()->process($this->request(''), $this->handler());

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testAnInstallWithNoTokenYetRejectsEveryRequest(): void
	{
		// No token file must not mean "no token required" — that would leave the
		// endpoint wide open on every install until somebody opened the admin.
		$response = $this->middleware()->process($this->request('token='), $this->handler());

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testAnUnauthenticatedRequestCreatesNoTokenFile(): void
	{
		// The middleware must read without creating. Otherwise anyone probing the
		// endpoint could make the server mint the credential they are missing.
		$this->middleware()->process($this->request('token=guess'), $this->handler());

		$this->assertFileDoesNotExist($this->tokenPath());
	}
}
