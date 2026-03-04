<?php

declare(strict_types=1);

namespace Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use TotalCMS\Action\Cache\DevModeDisableAction;
use TotalCMS\Action\Cache\DevModeEnableAction;
use TotalCMS\Action\Cache\DevModeStatusAction;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\ImageWorks\Service\WatermarkCleanupService;
use TotalCMS\Domain\Cache\Service\CacheInvalidationSignal;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Test dev mode API action classes.
 */
final class DevModeApiTest extends TestCase
{
	private DevModeManager $devModeManager;
	private CacheManager $cacheManager;
	private JsonRenderer $jsonRenderer;
	private RequestFactory $requestFactory;
	private ResponseFactory $responseFactory;

	protected function setUp(): void
	{
		parent::setUp();
		$this->devModeManager = new DevModeManager();

		// Create a simple CacheManager for testing (all services disabled)
		$config = new Config([
			'env'       => 'test',
			'template'  => '/tmp',
			'dashboard' => [],
			'datadir'   => '/tmp',
			'tmpdir'    => '/tmp',
			'cachedir'  => '/tmp/test-cache',
			'cache'     => [
				'filesystem' => false,
				'apcu'       => false,
			],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'smtp'       => [],
			'mailer'     => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		]);

		$filesystemService         = new FilesystemService($config);
		$opcacheService            = new OPcacheService();
		$redisService              = new RedisService($config);
		$memcachedService          = new MemcachedService($config);
		$apcuService               = new APCuService($config);
		$watermarkCleanupService   = $this->createMock(WatermarkCleanupService::class);

		$mockLoggerFactoryForCache = $this->createMock(\TotalCMS\Factory\LoggerFactory::class);
		$invalidationSignal        = new CacheInvalidationSignal($config);
		$this->cacheManager        = new CacheManager(
			$filesystemService,
			$opcacheService,
			$redisService,
			$memcachedService,
			$apcuService,
			$watermarkCleanupService,
			$this->devModeManager,
			$invalidationSignal,
			$config,
			$mockLoggerFactoryForCache
		);

		$this->jsonRenderer    = new JsonRenderer();
		$this->requestFactory  = new RequestFactory();
		$this->responseFactory = new ResponseFactory();

		// Ensure clean state
		$this->devModeManager->disableDevMode();
	}

	protected function tearDown(): void
	{
		// Clean up after each test
		$this->devModeManager->disableDevMode();
		parent::tearDown();
	}

	public function testDevModeStatusAction(): void
	{
		$action   = new DevModeStatusAction($this->devModeManager, $this->jsonRenderer);
		$request  = $this->requestFactory->createRequest('GET', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
		$this->assertSame('application/json', $result->getHeaderLine('Content-Type'));

		$body = (string)$result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertFalse($data['devmode']['enabled']);
		$this->assertSame(0, $data['devmode']['remaining_seconds']);
	}

	public function testDevModeEnableAction(): void
	{
		$this->assertFalse($this->devModeManager->isDevModeActive());

		$action   = new DevModeEnableAction($this->devModeManager, $this->cacheManager, $this->jsonRenderer);
		$request  = $this->requestFactory->createRequest('POST', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
		$this->assertSame('application/json', $result->getHeaderLine('Content-Type'));

		$body = (string)$result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('Development mode enabled for 3 hours', $data['message']);
		$this->assertTrue($data['devmode']['enabled']);
		$this->assertGreaterThan(10000, $data['devmode']['remaining_seconds']);

		// Verify dev mode is actually active
		$this->assertTrue($this->devModeManager->isDevModeActive());
	}

	public function testDevModeDisableAction(): void
	{
		// Enable dev mode first
		$this->devModeManager->enableDevMode();
		$this->assertTrue($this->devModeManager->isDevModeActive());

		$action   = new DevModeDisableAction($this->devModeManager, $this->jsonRenderer);
		$request  = $this->requestFactory->createRequest('DELETE', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
		$this->assertSame('application/json', $result->getHeaderLine('Content-Type'));

		$body = (string)$result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertSame('Development mode disabled. Caching has been restored.', $data['message']);
		$this->assertFalse($data['devmode']['enabled']);
		$this->assertSame(0, $data['devmode']['remaining_seconds']);

		// Verify dev mode is actually disabled
		$this->assertFalse($this->devModeManager->isDevModeActive());
	}

	public function testDevModeStatusActionWhenEnabled(): void
	{
		$this->devModeManager->enableDevMode();

		$action   = new DevModeStatusAction($this->devModeManager, $this->jsonRenderer);
		$request  = $this->requestFactory->createRequest('GET', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$body = (string)$result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertTrue($data['devmode']['enabled']);
		$this->assertGreaterThan(0, $data['devmode']['remaining_seconds']);
		$this->assertNotNull($data['devmode']['expires_at']);
		$this->assertNotNull($data['devmode']['started_at']);
	}

	public function testDisableWhenAlreadyDisabled(): void
	{
		$this->assertFalse($this->devModeManager->isDevModeActive());

		$action   = new DevModeDisableAction($this->devModeManager, $this->jsonRenderer);
		$request  = $this->requestFactory->createRequest('DELETE', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$body = (string)$result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertSame('Development mode disabled. Caching has been restored.', $data['message']);
		$this->assertFalse($data['devmode']['enabled']);
	}

	public function testEnableWhenAlreadyEnabled(): void
	{
		$this->devModeManager->enableDevMode();
		$this->assertTrue($this->devModeManager->isDevModeActive());

		$action   = new DevModeEnableAction($this->devModeManager, $this->cacheManager, $this->jsonRenderer);
		$request  = $this->requestFactory->createRequest('POST', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$body = (string)$result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertTrue($data['devmode']['enabled']);
		// Should reset the timer when enabled again
		$this->assertGreaterThan(10000, $data['devmode']['remaining_seconds']);
	}

	public function testResponseStructure(): void
	{
		$action   = new DevModeStatusAction($this->devModeManager, $this->jsonRenderer);
		$request  = $this->requestFactory->createRequest('GET', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);
		$body   = (string)$result->getBody();
		$data   = json_decode($body, true);

		// Verify required response structure
		$this->assertArrayHasKey('success', $data);
		$this->assertArrayHasKey('devmode', $data);

		$devmode = $data['devmode'];
		$this->assertArrayHasKey('enabled', $devmode);
		$this->assertArrayHasKey('remaining_seconds', $devmode);
		$this->assertArrayHasKey('remaining_formatted', $devmode);
		$this->assertArrayHasKey('expires_at', $devmode);
		$this->assertArrayHasKey('started_at', $devmode);
	}
}
