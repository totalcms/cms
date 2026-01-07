<?php

namespace Tests\Unit\Traits;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Traits\LoggerAwareTrait;

/**
 * Test class that uses LoggerAwareTrait.
 */
class LoggerAwareTestClass
{
	use LoggerAwareTrait;

	public function __construct(LoggerFactory $loggerFactory)
	{
		$this->loggerFactory = $loggerFactory;
	}

	public function getTestLogger(): LoggerInterface
	{
		return $this->getLogger();
	}
}

final class LoggerAwareTraitTest extends TestCase
{
	public function testGetLoggerReturnsLoggerInterface(): void
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$mockLogger    = new NullLogger();

		$loggerFactory->expects($this->once())
			->method('addFileHandler')
			->with('totalcms.log')
			->willReturnSelf();

		$loggerFactory->expects($this->once())
			->method('createLogger')
			->willReturn($mockLogger);

		$testClass = new LoggerAwareTestClass($loggerFactory);
		$logger    = $testClass->getTestLogger();

		$this->assertInstanceOf(LoggerInterface::class, $logger);
	}

	public function testGetLoggerReturnsSameInstance(): void
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$mockLogger    = new NullLogger();

		$loggerFactory->expects($this->once())
			->method('addFileHandler')
			->with('totalcms.log')
			->willReturnSelf();

		$loggerFactory->expects($this->once())
			->method('createLogger')
			->willReturn($mockLogger);

		$testClass = new LoggerAwareTestClass($loggerFactory);

		$logger1 = $testClass->getTestLogger();
		$logger2 = $testClass->getTestLogger();

		$this->assertSame($logger1, $logger2);
	}

	public function testLoggerIsCached(): void
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$mockLogger    = new NullLogger();

		// Should only be called once even with multiple getLogger calls
		$loggerFactory->expects($this->once())
			->method('addFileHandler')
			->willReturnSelf();

		$loggerFactory->expects($this->once())
			->method('createLogger')
			->willReturn($mockLogger);

		$testClass = new LoggerAwareTestClass($loggerFactory);

		// Call multiple times
		$testClass->getTestLogger();
		$testClass->getTestLogger();
		$testClass->getTestLogger();
	}
}
