<?php

declare(strict_types=1);

namespace Tests\Integration\Cache;

use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Action\Cache\DevModeEnableAction;
use TotalCMS\Action\Cache\DevModeDisableAction;
use TotalCMS\Action\Cache\DevModeStatusAction;
use TotalCMS\Renderer\JsonRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Test dev mode API action classes
 */
final class DevModeApiTest extends TestCase
{
	private DevModeManager $devModeManager;
	private JsonRenderer $jsonRenderer;
	private RequestFactory $requestFactory;
	private ResponseFactory $responseFactory;

	protected function setUp(): void
	{
		parent::setUp();
		$this->devModeManager = new DevModeManager();
		$this->jsonRenderer = new JsonRenderer();
		$this->requestFactory = new RequestFactory();
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
		$action = new DevModeStatusAction($this->devModeManager, $this->jsonRenderer);
		$request = $this->requestFactory->createRequest('GET', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
		$this->assertSame('application/json', $result->getHeaderLine('Content-Type'));

		$body = (string) $result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertFalse($data['devmode']['enabled']);
		$this->assertSame(0, $data['devmode']['remaining_seconds']);
	}

	public function testDevModeEnableAction(): void
	{
		$this->assertFalse($this->devModeManager->isDevModeActive());

		$action = new DevModeEnableAction($this->devModeManager, $this->jsonRenderer);
		$request = $this->requestFactory->createRequest('POST', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
		$this->assertSame('application/json', $result->getHeaderLine('Content-Type'));

		$body = (string) $result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertSame('Development mode enabled for 3 hours. Caching is now disabled.', $data['message']);
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

		$action = new DevModeDisableAction($this->devModeManager, $this->jsonRenderer);
		$request = $this->requestFactory->createRequest('DELETE', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$this->assertSame(200, $result->getStatusCode());
		$this->assertSame('application/json', $result->getHeaderLine('Content-Type'));

		$body = (string) $result->getBody();
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

		$action = new DevModeStatusAction($this->devModeManager, $this->jsonRenderer);
		$request = $this->requestFactory->createRequest('GET', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$body = (string) $result->getBody();
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

		$action = new DevModeDisableAction($this->devModeManager, $this->jsonRenderer);
		$request = $this->requestFactory->createRequest('DELETE', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$body = (string) $result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertSame('Development mode disabled. Caching has been restored.', $data['message']);
		$this->assertFalse($data['devmode']['enabled']);
	}

	public function testEnableWhenAlreadyEnabled(): void
	{
		$this->devModeManager->enableDevMode();
		$this->assertTrue($this->devModeManager->isDevModeActive());

		$action = new DevModeEnableAction($this->devModeManager, $this->jsonRenderer);
		$request = $this->requestFactory->createRequest('POST', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);

		$body = (string) $result->getBody();
		$data = json_decode($body, true);

		$this->assertTrue($data['success']);
		$this->assertTrue($data['devmode']['enabled']);
		// Should reset the timer when enabled again
		$this->assertGreaterThan(10000, $data['devmode']['remaining_seconds']);
	}

	public function testResponseStructure(): void
	{
		$action = new DevModeStatusAction($this->devModeManager, $this->jsonRenderer);
		$request = $this->requestFactory->createRequest('GET', '/cache/devmode');
		$response = $this->responseFactory->createResponse();

		$result = $action($request, $response);
		$body = (string) $result->getBody();
		$data = json_decode($body, true);

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