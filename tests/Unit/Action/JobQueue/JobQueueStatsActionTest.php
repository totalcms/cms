<?php

namespace Tests\Unit\Action\JobQueue;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\JobQueue\JobQueueStatsAction;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

final class JobQueueStatsActionTest extends TestCase
{
	private JobQueueStatsAction $action;
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

		$this->action = new JobQueueStatsAction($this->renderer, $this->manager);
	}

	public function testReturnsQueueStats(): void
	{
		$stats = [
			'total'   => 100,
			'pending' => 50,
			'failed'  => 10,
		];

		$this->manager->expects($this->once())
			->method('queueStats')
			->willReturn($stats);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $stats)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($this->response, $result);
	}

	public function testReturnsStatsFromManager(): void
	{
		$stats = [
			'queued'     => 25,
			'processing' => 5,
			'completed'  => 100,
		];

		$this->manager->method('queueStats')->willReturn($stats);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(fn ($data): bool => $data === $stats))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, []);
	}

	public function testHandlesEmptyStats(): void
	{
		$this->manager->method('queueStats')->willReturn([]);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, [])
			->willReturn($this->response);

		($this->action)($this->request, $this->response, []);
	}
}
