<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Mailer;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Mailer\SendEmailAction;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

final class SendEmailActionTest extends TestCase
{
	private SendEmailAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $emailService;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->emailService = $this->createMock(EmailService::class);
		$this->renderer     = $this->createMock(JsonRenderer::class);
		$this->request      = $this->createMock(ServerRequestInterface::class);
		$this->response     = $this->createMock(ResponseInterface::class);

		$accessManager = $this->createMock(AccessManager::class);
		$accessManager->method('userData')->willReturn([]);

		$this->action = new SendEmailAction($this->emailService, $this->renderer, $accessManager);
	}

	public function testSendsEmailSuccessfully(): void
	{
		$postData = [
			'mailerId' => 'welcome-email',
			'data'     => ['name' => 'John Doe'],
		];

		$this->request->method('getParsedBody')->willReturn($postData);

		$opResult = OperationResult::success('Email sent');

		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('welcome-email', ['name' => 'John Doe'])
			->willReturn($opResult);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $opResult->toArray())
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns400WhenMailerIdMissing(): void
	{
		$this->request->method('getParsedBody')->willReturn([]);

		$response400 = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($response400);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$response400,
				$this->callback(fn ($data): bool => $data['success'] === false
						&& str_contains((string)$data['message'], 'mailerId is required'))
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns400WhenMailerIdEmpty(): void
	{
		$this->request->method('getParsedBody')->willReturn(['mailerId' => '']);

		$response400 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(400)->willReturn($response400);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response);
	}

	public function testHandlesEmptyDataField(): void
	{
		$postData = ['mailerId' => 'test-email', 'data' => ''];

		$this->request->method('getParsedBody')->willReturn($postData);

		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('test-email', [])
			->willReturn(OperationResult::success('Sent'));

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testHandlesMissingDataField(): void
	{
		$postData = ['mailerId' => 'test-email'];

		$this->request->method('getParsedBody')->willReturn($postData);

		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('test-email', [])
			->willReturn(OperationResult::success('Sent'));

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testDecodesJsonStringData(): void
	{
		$postData = [
			'mailerId' => 'test-email',
			'data'     => '{"name":"Jane","email":"jane@example.com"}',
		];

		$this->request->method('getParsedBody')->willReturn($postData);

		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('test-email', ['name' => 'Jane', 'email' => 'jane@example.com'])
			->willReturn(OperationResult::success('Sent'));

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturns400WhenDataIsNotArray(): void
	{
		$postData = [
			'mailerId' => 'test-email',
			'data'     => 'invalid json',
		];

		$this->request->method('getParsedBody')->willReturn($postData);

		$response400 = $this->createMock(ResponseInterface::class);
		$this->response->method('withStatus')->with(400)->willReturn($response400);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$response400,
				$this->callback(fn ($data): bool => $data['success'] === false
						&& str_contains((string)$data['message'], 'data must be an array'))
			)
			->willReturn($jsonResponse);

		($this->action)($this->request, $this->response);
	}

	public function testReturns500OnEmailServiceFailure(): void
	{
		$postData = ['mailerId' => 'test-email'];

		$this->request->method('getParsedBody')->willReturn($postData);

		$opResult = OperationResult::failure('Email failed');

		$this->emailService->method('sendEmail')
			->willReturn($opResult);

		$response500 = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($response500, $opResult->toArray())
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testPassesDataToEmailService(): void
	{
		$postData = [
			'mailerId' => 'order-confirmation',
			'data'     => [
				'orderId'      => '12345',
				'customerName' => 'John Smith',
				'total'        => 99.99,
			],
		];

		$this->request->method('getParsedBody')->willReturn($postData);

		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with(
				'order-confirmation',
				$this->equalTo($postData['data'])
			)
			->willReturn(OperationResult::success('Sent'));

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturnsJsonResponse(): void
	{
		$postData = ['mailerId' => 'test-email'];

		$this->request->method('getParsedBody')->willReturn($postData);

		$this->emailService->method('sendEmail')
			->willReturn(OperationResult::success('Sent'));

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertInstanceOf(ResponseInterface::class, $result);
		$this->assertSame($jsonResponse, $result);
	}
}
