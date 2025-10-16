<?php

namespace Tests\Unit\Action\JobQueue;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\JobQueue\JobQueueClearAction;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

final class JobQueueClearActionTest extends TestCase
{
	private JobQueueClearAction $action;
	private JobManager $manager;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->manager  = $this->createMock(JobManager::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new JobQueueClearAction($this->renderer, $this->manager);
	}

	public function testClearsQueueSuccessfully(): void
	{
		$this->manager->expects($this->once())
			->method('clearQueue')
			->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['cleared' => true])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($this->response, $result);
	}

	public function testReturnsJsonWithClearedStatus(): void
	{
		$this->manager->method('clearQueue')->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return isset($data['cleared']) && $data['cleared'] === true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, []);
	}

	public function testReturns500WhenClearFails(): void
	{
		$this->manager->method('clearQueue')->willReturn(false);

		$response500 = $this->createMock(ResponseInterface::class);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($response500, $result);
	}
}
