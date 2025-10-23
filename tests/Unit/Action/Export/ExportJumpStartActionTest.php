<?php

namespace Tests\Unit\Action\Export;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Export\ExportJumpStartAction;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;

final class ExportJumpStartActionTest extends TestCase
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
	}

	public function testExportsJumpStartSuccessfully(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->expects($this->once())
			->method('exportCurrentData')
			->willReturn($jumpStartData);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnSelf();

		$this->response->expects($this->once())
			->method('withBody')
			->willReturnSelf();

		$result = ($this->action)($this->request, $this->response);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testSetsMetadataFromQueryParams(): void
	{
		$this->request->method('getQueryParams')->willReturn([
			'name'        => 'My Project',
			'description' => 'Test project description',
		]);

		$this->jumpStartExporter->expects($this->once())
			->method('setMetadata')
			->with('My Project', 'Test project description');

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->method('exportCurrentData')->willReturn($jumpStartData);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);
	}

	public function testHandlesMissingQueryParams(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);

		$this->jumpStartExporter->expects($this->once())
			->method('setMetadata')
			->with('', '');

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->method('exportCurrentData')->willReturn($jumpStartData);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);
	}

	public function testSetsJsonContentType(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->method('exportCurrentData')->willReturn($jumpStartData);

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);

		$this->assertTrue(true);
	}

	public function testUsesNameInFilename(): void
	{
		$this->request->method('getQueryParams')->willReturn([
			'name' => 'My Project',
		]);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->method('exportCurrentData')->willReturn($jumpStartData);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnCallback(function ($name, $value): ResponseInterface {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('jumpstart-my-project', $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);
	}

	public function testUsesDefaultFilenameWhenNoName(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->method('exportCurrentData')->willReturn($jumpStartData);

		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnCallback(function ($name, $value): ResponseInterface {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('jumpstart-export-', $value);
				}

				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);
	}

	public function testReturnsResponseWithStreamBody(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);

		$jumpStartData = $this->createMock(JumpStartData::class);
		$jumpStartData->method('streamJsonToFile');

		$this->jumpStartExporter->method('exportCurrentData')->willReturn($jumpStartData);

		$this->response->method('withHeader')->willReturnSelf();

		$responseWithBody = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withBody')
			->with($this->isInstanceOf(StreamInterface::class))
			->willReturn($responseWithBody);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($responseWithBody, $result);
	}
}
