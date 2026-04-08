<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Update\Data\UpdateInfo;

final class UpdateInfoTest extends TestCase
{
	public function testFromApiResponseWithUpdateAvailable(): void
	{
		$data = [
			'available'   => true,
			'version'     => '3.3.0',
			'releaseDate' => '2026-04-10',
			'severity'    => 'minor',
			'changelog'   => 'New features added',
			'buildHash'   => 'abc123',
			'downloadUrl' => '/version/download/3.3.0',
		];

		$info = UpdateInfo::fromApiResponse($data);

		expect($info->available)->toBeTrue();
		expect($info->version)->toBe('3.3.0');
		expect($info->releaseDate)->toBe('2026-04-10');
		expect($info->severity)->toBe('minor');
		expect($info->changelog)->toBe('New features added');
		expect($info->buildHash)->toBe('abc123');
		expect($info->downloadUrl)->toBe('/version/download/3.3.0');
	}

	public function testFromApiResponseWithNoUpdate(): void
	{
		$data = [
			'available' => false,
			'version'   => '3.2.2',
		];

		$info = UpdateInfo::fromApiResponse($data);

		expect($info->available)->toBeFalse();
		expect($info->version)->toBe('3.2.2');
		expect($info->severity)->toBe('patch');
		expect($info->changelog)->toBe('');
	}

	public function testFromApiResponseWithMissingFields(): void
	{
		$info = UpdateInfo::fromApiResponse([]);

		expect($info->available)->toBeFalse();
		expect($info->version)->toBe('');
		expect($info->severity)->toBe('patch');
	}

	public function testToArray(): void
	{
		$info = new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: 'Changes',
			buildHash: 'abc',
			downloadUrl: '/download/3.3.0',
		);

		$array = $info->toArray();

		expect($array['available'])->toBeTrue();
		expect($array['version'])->toBe('3.3.0');
		expect($array['severity'])->toBe('minor');
		expect($array)->toHaveCount(7);
	}
}
