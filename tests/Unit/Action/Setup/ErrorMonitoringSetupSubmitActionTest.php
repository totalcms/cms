<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Setup;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Setup\ErrorMonitoringSetupSubmitAction;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\RedirectRenderer;

final class ErrorMonitoringSetupSubmitActionTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $settingsSaver;
	private \PHPUnit\Framework\MockObject\MockObject $setupState;
	private \PHPUnit\Framework\MockObject\MockObject $redirectRenderer;
	private ErrorMonitoringSetupSubmitAction $action;

	protected function setUp(): void
	{
		$this->settingsSaver    = $this->createMock(SettingsSaver::class);
		$this->setupState       = $this->createMock(SetupStateManager::class);
		$this->redirectRenderer = $this->createMock(RedirectRenderer::class);

		$this->action = new ErrorMonitoringSetupSubmitAction(
			$this->settingsSaver,
			$this->setupState,
			$this->redirectRenderer,
		);
	}

	public function testCheckedBoxSavesSentryEnabledAndAdvances(): void
	{
		$this->settingsSaver->expects($this->once())
			->method('saveSection')
			->with('general', ['sentry' => true]);

		$this->setupState->expects($this->once())
			->method('completeStep')
			->with('error-monitoring');

		$expected = $this->createMock(ResponseInterface::class);
		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-server-config')
			->willReturn($expected);

		$result = ($this->action)(
			$this->createRequest(['sentry' => 'on']),
			$this->createMock(ResponseInterface::class),
		);

		$this->assertSame($expected, $result);
	}

	public function testMissingCheckboxSavesSentryDisabled(): void
	{
		$this->settingsSaver->expects($this->once())
			->method('saveSection')
			->with('general', ['sentry' => false]);

		$this->setupState->expects($this->once())
			->method('completeStep')
			->with('error-monitoring');

		$this->redirectRenderer->method('redirectFor')
			->willReturn($this->createMock(ResponseInterface::class));

		($this->action)($this->createRequest([]), $this->createMock(ResponseInterface::class));
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function createRequest(array $body): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($body);

		return $request;
	}
}
