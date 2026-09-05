<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\UpdateBackupDeleteAction;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Renderer\JsonRenderer;

final class UpdateBackupDeleteActionTest extends TestCase
{
	public function testDiscardsTheRetainedPreviousVersion(): void
	{
		$applier = $this->createMock(UpdateApplier::class);
		$applier->expects($this->once())->method('discardRetainedBackup');

		$response = $this->createMock(ResponseInterface::class);
		$renderer = $this->createMock(JsonRenderer::class);
		$renderer->expects($this->once())
			->method('json')
			->with($response, ['success' => true])
			->willReturn($response);

		$action = new UpdateBackupDeleteAction($renderer, $applier);
		$action($this->createMock(ServerRequestInterface::class), $response);
	}

	public function testReportsAFailureAsA500RatherThanThrowing(): void
	{
		$applier = $this->createMock(UpdateApplier::class);
		$applier->method('discardRetainedBackup')->willThrowException(new \RuntimeException('disk on fire'));

		$response = $this->createMock(ResponseInterface::class);
		$response->expects($this->once())->method('withStatus')->with(500)->willReturnSelf();

		$renderer = $this->createMock(JsonRenderer::class);
		$renderer->expects($this->once())
			->method('json')
			->with($response, $this->callback(static function (array $data): bool {
				return $data['success'] === false && str_contains($data['error'], 'disk on fire');
			}))
			->willReturn($response);

		$action = new UpdateBackupDeleteAction($renderer, $applier);
		$action($this->createMock(ServerRequestInterface::class), $response);
	}
}
