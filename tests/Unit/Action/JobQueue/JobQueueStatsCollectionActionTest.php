<?php

namespace Tests\Unit\Action\JobQueue;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\JobQueue\JobQueueStatsCollectionAction;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

final class JobQueueStatsCollectionActionTest extends TestCase
{
	private JobQueueStatsCollectionAction $action;
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

		$this->action = new JobQueueStatsCollectionAction($this->renderer, $this->manager);
	}

	public function testReturnsStatsForCollection(): void
	{
		$stats = [
			'collection' => 'products',
			'total'      => 50,
			'pending'    => 25,
		];

		$this->manager->expects($this->once())
			->method('queueStatsForCollection')
			->with('products')
			->willReturn($stats);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $stats)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'products']);

		$this->assertSame($this->response, $result);
	}

	public function testPassesCollectionToManager(): void
	{
		$this->manager->expects($this->once())
			->method('queueStatsForCollection')
			->with('blog')
			->willReturn([]);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testReturnsStatsFromManager(): void
	{
		$stats = [
			'queued'    => 10,
			'completed' => 40,
		];

		$this->manager->method('queueStatsForCollection')->willReturn($stats);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) use ($stats) {
				return $data === $stats;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testHandlesEmptyStats(): void
	{
		$this->manager->method('queueStatsForCollection')->willReturn([]);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, [])
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testHandlesDifferentCollections(): void
	{
		$this->manager->expects($this->once())
			->method('queueStatsForCollection')
			->with('gallery')
			->willReturn(['total' => 5]);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'gallery']);
	}
}
