<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class MediaTwigAdapterPasswordTest extends TestCase
{
	public function testFetchDataDoesNotReturnPasswordHash(): void
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('fetchObject')->willReturn(new ObjectData('admin', [
			'name'     => new StringData('Admin'),
			'password' => new PasswordData('secret-pass'),
		]));

		$adapter = new MediaTwigAdapter($objectFetcher, $this->createMock(Config::class), $loggerFactory);
		$method  = new \ReflectionMethod($adapter, 'fetchData');

		expect($method->invoke($adapter, 'auth', 'admin', 'password'))->toBe('');
		expect($method->invoke($adapter, 'auth', 'admin', 'name'))->toBe('Admin');
	}
}
