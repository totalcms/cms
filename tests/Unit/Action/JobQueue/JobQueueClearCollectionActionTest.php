<?php

namespace Tests\Unit\Action\JobQueue;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\JobQueue\JobQueueClearCollectionAction;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

final class JobQueueClearCollectionActionTest extends TestCase
{
	private JobQueueClearCollectionAction $action;
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

		$this->action = new JobQueueClearCollectionAction($this->renderer, $this->manager);
	}

	public function testClearsQueueForCollectionSuccessfully(): void
	{
		$this->manager->expects($this->once())
			->method('clearQueueForCollection')
			->with('products')
			->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['cleared' => true])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'products']);

		$this->assertSame($this->response, $result);
	}

	public function testPassesCollectionToManager(): void
	{
		$this->manager->expects($this->once())
			->method('clearQueueForCollection')
			->with('blog')
			->willReturn(true);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testReturnsJsonWithClearedStatus(): void
	{
		$this->manager->method('clearQueueForCollection')->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(fn ($data): bool => isset($data['cleared']) && $data['cleared'] === true))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testReturns500WhenClearFails(): void
	{
		$this->manager->method('clearQueueForCollection')->willReturn(false);

		$response500 = $this->createMock(ResponseInterface::class);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'products']);

		$this->assertSame($response500, $result);
	}
}
