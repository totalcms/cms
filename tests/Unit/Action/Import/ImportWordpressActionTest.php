<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpInternalServerErrorException;
use TotalCMS\Action\Import\ImportWordpressAction;

final class ImportWordpressActionTest extends TestCase
{
	private ImportWordpressAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->action   = new ImportWordpressAction();
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);
	}

	public function testThrowsNotImplementedException(): void
	{
		$this->expectException(HttpInternalServerErrorException::class);
		$this->expectExceptionMessage('Not implemented');

		($this->action)($this->request, $this->response);
	}
}
