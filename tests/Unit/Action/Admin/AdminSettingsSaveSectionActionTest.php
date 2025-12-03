<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\AdminSettingsSaveSectionAction;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

final class AdminSettingsSaveSectionActionTest extends TestCase
{
	private AdminSettingsSaveSectionAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $saver;
	private \PHPUnit\Framework\MockObject\MockObject $installationSaver;
	private \PHPUnit\Framework\MockObject\MockObject $validator;
	private \PHPUnit\Framework\MockObject\MockObject $emailSender;
	private \PHPUnit\Framework\MockObject\MockObject $twigRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer          = $this->createMock(JsonRenderer::class);
		$this->saver             = $this->createMock(SettingsSaver::class);
		$this->installationSaver = $this->createMock(InstallationSettingsSaver::class);
		$this->validator         = $this->createMock(SettingsValidator::class);
		$this->emailSender       = $this->createMock(EmailSender::class);
		$this->twigRenderer      = $this->createMock(TwigRenderer::class);
		$this->editionFeatures   = $this->createMock(EditionFeatureService::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);

		$this->action = new AdminSettingsSaveSectionAction(
			$this->renderer,
			$this->saver,
			$this->installationSaver,
			$this->validator,
			$this->emailSender,
			$this->twigRenderer,
			$this->editionFeatures
		);
	}

	public function testSavesValidSection(): void
	{
		$section  = 'smtp';
		$formData = ['host' => 'smtp.example.com', 'port' => 587];

		$this->validator->expects($this->once())
			->method('isValidSection')
			->with($section)
			->willReturn(true);

		$this->request->method('getParsedBody')->willReturn($formData);

		$this->saver->expects($this->once())
			->method('saveSection')
			->with($section, $formData);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(fn ($data): bool => $data['success'] === true
						&& $data['section'] === $section
						&& isset($data['message']))
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, ['section' => $section]);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns400ForInvalidSection(): void
	{
		$section = 'invalid-section';

		$this->validator->expects($this->once())
			->method('isValidSection')
			->with($section)
			->willReturn(false);

		$this->request->method('getParsedBody')->willReturn([]);

		$this->saver->expects($this->never())->method('saveSection');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(fn ($data): bool => $data['success'] === false
						&& isset($data['message'])
						&& str_contains($data['message'], 'Invalid')),
				400
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, ['section' => $section]);

		$this->assertSame($jsonResponse, $result);
	}

	public function testRemovesCsrfTokens(): void
	{
		$section  = 'cache';
		$formData = [
			'enabled'         => true,
			'csrf_token'      => 'token123',
			'csrf_token_name' => 'csrf_name',
		];
		$expectedData = ['enabled' => true];

		$this->validator->method('isValidSection')->willReturn(true);
		$this->request->method('getParsedBody')->willReturn($formData);

		$this->saver->expects($this->once())
			->method('saveSection')
			->with($section, $expectedData);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['section' => $section]);
	}

	public function testHandlesEmptySection(): void
	{
		$this->validator->expects($this->once())
			->method('isValidSection')
			->with('')
			->willReturn(false);

		$this->request->method('getParsedBody')->willReturn([]);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->arrayHasKey('success'),
				400
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($jsonResponse, $result);
	}

	public function testHandlesMissingSectionInArgs(): void
	{
		$this->validator->expects($this->once())
			->method('isValidSection')
			->with('')
			->willReturn(false);

		$this->request->method('getParsedBody')->willReturn([]);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($jsonResponse, $result);
	}

	public function testIncludesSectionInSuccessResponse(): void
	{
		$section = 'general';

		$this->validator->method('isValidSection')->willReturn(true);
		$this->request->method('getParsedBody')->willReturn([]);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(fn ($data): bool => isset($data['section']) && $data['section'] === $section)
			)
			->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['section' => $section]);
	}

	public function testIncludesSuccessMessageInResponse(): void
	{
		$section = 'smtp';

		$this->validator->method('isValidSection')->willReturn(true);
		$this->request->method('getParsedBody')->willReturn([]);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(fn ($data): bool => isset($data['message']) && !empty($data['message']))
			)
			->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['section' => $section]);
	}

	public function testHandlesNullParsedBody(): void
	{
		$section = 'cache';

		$this->validator->method('isValidSection')->willReturn(true);
		$this->request->method('getParsedBody')->willReturn(null);

		$this->saver->expects($this->once())
			->method('saveSection')
			->with($section, []);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, ['section' => $section]);

		$this->assertSame($jsonResponse, $result);
	}

	public function testRemovesOnlyCsrfTokens(): void
	{
		$section  = 'mailer';
		$formData = [
			'from'            => 'test@example.com',
			'csrf_token'      => 'should-be-removed',
			'csrf_token_name' => 'should-be-removed',
			'subject'         => 'Test Email',
		];
		$expectedData = [
			'from'    => 'test@example.com',
			'subject' => 'Test Email',
		];

		$this->validator->method('isValidSection')->willReturn(true);
		$this->request->method('getParsedBody')->willReturn($formData);

		$this->saver->expects($this->once())
			->method('saveSection')
			->with($section, $expectedData);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['section' => $section]);
	}

	public function testCallsSettingsSaverWithCorrectParameters(): void
	{
		$section  = 'auth';
		$formData = ['collection' => 'users', 'enable' => true];

		$this->validator->method('isValidSection')->willReturn(true);
		$this->request->method('getParsedBody')->willReturn($formData);

		$this->saver->expects($this->once())
			->method('saveSection')
			->with(
				$this->equalTo($section),
				$this->equalTo($formData)
			);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['section' => $section]);
	}

	public function testValidatesBeforeSaving(): void
	{
		$section = 'invalid';

		// Validator returns false, so saver should never be called
		$this->validator->expects($this->once())
			->method('isValidSection')
			->willReturn(false);

		$this->request->method('getParsedBody')->willReturn(['key' => 'value']);

		$this->saver->expects($this->never())->method('saveSection');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['section' => $section]);
	}

	public function testReturnsJsonResponse(): void
	{
		$section = 'logger';

		$this->validator->method('isValidSection')->willReturn(true);
		$this->request->method('getParsedBody')->willReturn([]);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, ['section' => $section]);

		$this->assertInstanceOf(ResponseInterface::class, $result);
		$this->assertSame($jsonResponse, $result);
	}
}
