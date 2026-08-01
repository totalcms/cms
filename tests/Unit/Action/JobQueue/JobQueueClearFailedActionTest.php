<?php

namespace Tests\Unit\Action\JobQueue;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\JobQueue\JobQueueClearFailedAction;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

final class JobQueueClearFailedActionTest extends TestCase
{
	private JobQueueClearFailedAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $manager;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->manager  = $this->createMock(JobManager::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new JobQueueClearFailedAction($this->renderer, $this->manager);
	}

	public function testClearsOnlyFailedJobsAndReturnsTheCount(): void
	{
		$this->manager->expects($this->once())
			->method('clearFailedJobs')
			->willReturn(4);

		$this->manager->expects($this->never())->method('clearQueue');

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['cleared' => 4])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($this->response, $result);
	}

	public function testReportsZeroWhenThereWereNoFailedJobs(): void
	{
		$this->manager->method('clearFailedJobs')->willReturn(0);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['cleared' => 0])
			->willReturn($this->response);

		($this->action)($this->request, $this->response, []);
	}
}
