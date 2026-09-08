<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\UpdatePageData;
use TotalCMS\Domain\Update\Data\UpdateInfo;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Domain\Update\Service\UpdateChecker;

final class UpdatePageDataTest extends TestCase
{
	public function testChecksForAnUpdateAndReportsTheRetainedBackup(): void
	{
		$checker = $this->createMock(UpdateChecker::class);
		$applier = $this->createMock(UpdateApplier::class);
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn([]);
		$updateInfo = new UpdateInfo(
			available: true,
			version: '9.9.9',
			releaseDate: '2026-09-08',
			severity: 'major',
			changelog: 'Test changelog',
			buildHash: 'abc123',
			downloadUrl: 'https://example.com/download',
		);
		$checker->expects($this->once())->method('checkForUpdate')->with(false)->willReturn($updateInfo);
		$applier->method('retainedBackup')->willReturn(['path' => '/backup', 'name' => 'backup', 'bytes' => 1000]);

		$data = (new UpdatePageData($checker, $applier))->build($request, 'update', '');

		$this->assertSame($updateInfo, $data['updateInfo']);
		$this->assertSame(['path' => '/backup', 'name' => 'backup', 'bytes' => 1000], $data['retainedBackup']);
	}

	public function testCheckQueryForcesAFreshCheck(): void
	{
		$checker = $this->createMock(UpdateChecker::class);
		$applier = $this->createMock(UpdateApplier::class);
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['check' => '1']);
		$noUpdateInfo = new UpdateInfo(
			available: false,
			version: '3.5.0',
			releaseDate: '',
			severity: '',
			changelog: '',
			buildHash: '',
			downloadUrl: '',
		);
		$checker->expects($this->once())->method('checkForUpdate')->with(true)->willReturn($noUpdateInfo);

		(new UpdatePageData($checker, $applier))->build($request, 'update', '');
	}

	public function testACheckFailureIsSwallowed(): void
	{
		$checker = $this->createMock(UpdateChecker::class);
		$applier = $this->createMock(UpdateApplier::class);
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn([]);
		$checker->method('checkForUpdate')->willThrowException(new \RuntimeException('offline'));

		$data = (new UpdatePageData($checker, $applier))->build($request, 'update', '');

		$this->assertNull($data['updateInfo']);
	}
}
