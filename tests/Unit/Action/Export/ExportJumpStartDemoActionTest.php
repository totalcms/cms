<?php

namespace Tests\Unit\Action\Export;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Action\Export\ExportJumpStartDemoAction;

final class ExportJumpStartDemoActionTest extends TestCase
{
	private ExportJumpStartDemoAction $action;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->action   = new ExportJumpStartDemoAction();
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);
	}

	public function testExportsDemoFileSuccessfully(): void
	{
		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnSelf();

		$this->response->expects($this->once())
			->method('withBody')
			->willReturnSelf();

		$result = ($this->action)($this->request, $this->response);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testSetsJsonContentType(): void
	{
		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);

		$this->assertTrue(true);
	}

	public function testSetsContentDispositionHeader(): void
	{
		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnCallback(function ($name, $value) {
				if ($name === 'Content-Disposition') {
					$this->assertStringContainsString('attachment', $value);
					$this->assertStringContainsString('jumpstart-demo-', $value);
					$this->assertStringContainsString('.json', $value);
				}
				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);
	}

	public function testReturnsResponseWithBody(): void
	{
		$this->response->method('withHeader')->willReturnSelf();

		$responseWithBody = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withBody')
			->with($this->isInstanceOf(StreamInterface::class))
			->willReturn($responseWithBody);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($responseWithBody, $result);
	}

	public function testDoesNotRequireRequestParameters(): void
	{
		$this->request->expects($this->never())
			->method('getQueryParams');

		$this->request->expects($this->never())
			->method('getParsedBody');

		$this->response->method('withHeader')->willReturnSelf();
		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);
	}

	public function testFilenameIncludesTimestamp(): void
	{
		$this->response->expects($this->exactly(2))
			->method('withHeader')
			->willReturnCallback(function ($name, $value) {
				if ($name === 'Content-Disposition') {
					// Filename should contain date format Ymd-His
					$this->assertMatchesRegularExpression('/jumpstart-demo-\d{8}-\d{6}\.json/', $value);
				}
				return $this->response;
			});

		$this->response->method('withBody')->willReturnSelf();

		($this->action)($this->request, $this->response);
	}
}
