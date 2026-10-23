<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Factory\LoggerFactory;

final class DataTwigAdapterPasswordTest extends TestCase
{
	private function makeAdapter(): DataTwigAdapter
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('fetchObject')->willReturn(new ObjectData('admin', [
			'name'     => new StringData('Admin'),
			'password' => new PasswordData('secret-pass'),
		]));

		return new DataTwigAdapter($objectFetcher, $loggerFactory);
	}

	public function testObjectOmitsPasswordProperties(): void
	{
		$result = $this->makeAdapter()->object('auth', 'admin');

		expect($result)->not->toHaveKey('password');
		expect($result['name'])->toBe('Admin');
	}

	public function testRawDoesNotReturnPasswordHash(): void
	{
		expect($this->makeAdapter()->raw('auth', 'admin', 'password'))->toBe('');
	}
}
