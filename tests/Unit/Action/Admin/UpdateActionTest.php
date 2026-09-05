<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\UpdateAction;
use TotalCMS\Domain\Update\Data\UpdateInfo;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Domain\Update\Service\UpdateDownloader;
use TotalCMS\Renderer\JsonRenderer;

final class UpdateActionTest extends TestCase
{
	private UpdateAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $updateChecker;
	private \PHPUnit\Framework\MockObject\MockObject $updateDownloader;
	private \PHPUnit\Framework\MockObject\MockObject $updateApplier;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer         = $this->createMock(JsonRenderer::class);
		$this->updateChecker    = $this->createMock(UpdateChecker::class);
		$this->updateDownloader = $this->createMock(UpdateDownloader::class);
		$this->updateApplier    = $this->createMock(UpdateApplier::class);
		$this->request          = $this->createMock(ServerRequestInterface::class);
		$this->response         = $this->createMock(ResponseInterface::class);

		$this->action = new UpdateAction(
			$this->renderer,
			$this->updateChecker,
			$this->updateDownloader,
			$this->updateApplier,
		);

		$this->renderer->method('json')->willReturn($this->response);
		$this->response->method('withStatus')->willReturn($this->response);
	}

	public function testReturnsUpToDateWhenNoUpdateAvailable(): void
	{
		$this->updateChecker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: false,
			version: '3.2.2',
			releaseDate: '',
			severity: '',
			changelog: '',
			buildHash: '',
			downloadUrl: ''
		));

		$this->updateDownloader->expects($this->never())->method('download');

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeTrue();
				expect($data['message'])->toContain('up to date');

				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testDownloadsAndAppliesUpdate(): void
	{
		$this->updateChecker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: 'New features',
			buildHash: 'abc',
			downloadUrl: '/version/download/3.3.0'
		));

		$this->updateDownloader->expects($this->once())
			->method('download')
			->with('3.3.0', '/version/download/3.3.0')
			->willReturn('/tmp/update-3.3.0.zip');

		$this->updateApplier->expects($this->once())
			->method('apply')
			->with('/tmp/update-3.3.0.zip', '3.3.0');

		$this->updateChecker->expects($this->once())->method('clearCache');

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeTrue();
				expect($data['version'])->toBe('3.3.0');

				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturns500OnDownloadFailure(): void
	{
		$this->updateChecker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '/version/download/3.3.0'
		));

		$this->updateDownloader->method('download')
			->willThrowException(new \RuntimeException('Download failed'));

		$this->updateApplier->expects($this->never())->method('apply');

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeFalse();
				expect($data['error'])->toContain('Download failed');

				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturns500OnApplyFailure(): void
	{
		$this->updateChecker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '/version/download/3.3.0'
		));

		$this->updateDownloader->method('download')->willReturn('/tmp/update-3.3.0.zip');

		$this->updateApplier->method('apply')
			->willThrowException(new \RuntimeException('Swap failed'));

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function (array $data): bool {
				expect($data['success'])->toBeFalse();
				expect($data['error'])->toContain('Swap failed');

				return true;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	/**
	 * The Update Manager's checkbox is the only thing that decides whether the
	 * previous version is kept, so it has to actually reach the applier. An
	 * operator who never touches it gets the safety net.
	 */
	public function testKeepsThePreviousVersionWhenTheRequestSaysNothing(): void
	{
		$this->stubAvailableUpdate();
		$this->request->method('getParsedBody')->willReturn(null);

		$this->updateApplier->expects($this->once())
			->method('apply')
			->with('/tmp/update-3.3.0.zip', '3.3.0', true);

		($this->action)($this->request, $this->response);
	}

	public function testHonoursAnUntickedKeepBackupCheckbox(): void
	{
		$this->stubAvailableUpdate();
		$this->request->method('getParsedBody')->willReturn(['keepBackup' => false]);

		$this->updateApplier->expects($this->once())
			->method('apply')
			->with('/tmp/update-3.3.0.zip', '3.3.0', false);

		($this->action)($this->request, $this->response);
	}

	/** JSON gives a real bool, but a form post would give the string. */
	public function testTreatsTheStringFalseAsUnticked(): void
	{
		$this->stubAvailableUpdate();
		$this->request->method('getParsedBody')->willReturn(['keepBackup' => 'false']);

		$this->updateApplier->expects($this->once())
			->method('apply')
			->with('/tmp/update-3.3.0.zip', '3.3.0', false);

		($this->action)($this->request, $this->response);
	}

	private function stubAvailableUpdate(): void
	{
		$this->updateChecker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: 'New features',
			buildHash: 'abc',
			downloadUrl: '/version/download/3.3.0'
		));
		$this->updateDownloader->method('download')->willReturn('/tmp/update-3.3.0.zip');
	}
}
