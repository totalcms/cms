<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Export;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Export\ExportJumpStartAction;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;

final class ExportJumpStartSyncModeTest extends TestCase
{
	private ExportJumpStartAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $jumpStartExporter;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->jumpStartExporter = $this->createMock(JumpStartExporter::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);

		$this->action = new ExportJumpStartAction($this->jumpStartExporter);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();
	}

	public function testSyncModeCallsExportSyncData(): void
	{
		$this->request->method('getQueryParams')->willReturn(['mode' => 'sync']);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->expects($this->once())
			->method('exportSyncData')
			->willReturn($jumpStartData);

		$this->jumpStartExporter->expects($this->never())
			->method('exportCurrentData');

		($this->action)($this->request, $this->response);
	}

	public function testFullModeCallsExportCurrentData(): void
	{
		$this->request->method('getQueryParams')->willReturn(['mode' => 'full']);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->expects($this->once())
			->method('exportCurrentData')
			->willReturn($jumpStartData);

		$this->jumpStartExporter->expects($this->never())
			->method('exportSyncData');

		($this->action)($this->request, $this->response);
	}

	public function testDefaultModeCallsExportCurrentData(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->expects($this->once())
			->method('exportCurrentData')
			->willReturn($jumpStartData);

		$this->jumpStartExporter->expects($this->never())
			->method('exportSyncData');

		($this->action)($this->request, $this->response);
	}
}
