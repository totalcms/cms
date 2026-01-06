<?php

namespace Tests\Unit\Domain\License\Exception;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\License\Exception\LicenseException;

final class LicenseExceptionTest extends TestCase
{
	public function testConstructorWithMessage(): void
	{
		$exception = new LicenseException('Invalid license');

		$this->assertSame('Invalid license', $exception->getMessage());
		$this->assertSame(0, $exception->getCode());
	}

	public function testConstructorWithMessageAndCode(): void
	{
		$exception = new LicenseException('License expired', 403);

		$this->assertSame('License expired', $exception->getMessage());
		$this->assertSame(403, $exception->getCode());
	}

	public function testConstructorWithPreviousException(): void
	{
		$previous = new \Exception('Previous error');
		$exception = new LicenseException('License error', 500, $previous);

		$this->assertSame('License error', $exception->getMessage());
		$this->assertSame(500, $exception->getCode());
		$this->assertSame($previous, $exception->getPrevious());
	}

	public function testEmptyMessage(): void
	{
		$exception = new LicenseException();

		$this->assertSame('', $exception->getMessage());
	}

	public function testExceptionIsThrowable(): void
	{
		$this->expectException(LicenseException::class);
		$this->expectExceptionMessage('Test exception');

		throw new LicenseException('Test exception');
	}

	public function testExceptionExtendsBaseException(): void
	{
		$exception = new LicenseException('Test');

		$this->assertInstanceOf(\Exception::class, $exception);
	}
}
