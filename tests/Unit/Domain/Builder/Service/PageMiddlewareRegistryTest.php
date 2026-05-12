<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;

final class PageMiddlewareRegistryTest extends TestCase
{
	private ContainerInterface&MockObject $container;
	private PageMiddlewareRegistry $registry;

	protected function setUp(): void
	{
		$this->container = $this->createMock(ContainerInterface::class);
		$this->registry  = new PageMiddlewareRegistry($this->container);
	}

	public function testRegistersAndResolvesByName(): void
	{
		$middleware = $this->createMock(PageMiddlewareInterface::class);
		$this->container->method('get')->with('my.middleware')->willReturn($middleware);

		$this->registry->register('auth', 'my.middleware');

		$this->assertTrue($this->registry->has('auth'));
		$this->assertSame($middleware, $this->registry->resolve('auth'));
	}

	public function testHasReturnsFalseForUnregisteredName(): void
	{
		$this->assertFalse($this->registry->has('nope'));
	}

	public function testResolveReturnsNullForUnregisteredName(): void
	{
		$this->assertNull($this->registry->resolve('nope'));
	}

	public function testResolveReturnsNullWhenContainerThrows(): void
	{
		$this->container->method('get')->willThrowException(new \RuntimeException('boom'));

		$this->registry->register('auth', 'my.middleware');

		$this->assertNull($this->registry->resolve('auth'));
	}

	public function testResolveReturnsNullWhenContainerReturnsWrongType(): void
	{
		$this->container->method('get')->willReturn(new \stdClass());

		$this->registry->register('auth', 'my.middleware');

		$this->assertNull($this->registry->resolve('auth'));
	}

	public function testRejectsInvalidNames(): void
	{
		foreach (['', 'Auth', 'au th', 'auth!', '-leading', '_underscore'] as $bad) {
			$this->assertInvalidName($bad);
		}
	}

	public function testAcceptsKebabCaseLowerAlphanumericNames(): void
	{
		// Should not throw.
		$this->registry->register('auth', 'x');
		$this->registry->register('rate-limit', 'x');
		$this->registry->register('a1b2-3c', 'x');

		$this->assertContains('auth', $this->registry->availableNames());
		$this->assertContains('rate-limit', $this->registry->availableNames());
		$this->assertContains('a1b2-3c', $this->registry->availableNames());
	}

	public function testAvailableNamesIsAlphabetical(): void
	{
		$this->registry->register('zebra', 'x');
		$this->registry->register('apple', 'x');
		$this->registry->register('mango', 'x');

		$this->assertSame(['apple', 'mango', 'zebra'], $this->registry->availableNames());
	}

	public function testLastWriteWinsOnCollision(): void
	{
		$first  = $this->createMock(PageMiddlewareInterface::class);
		$second = $this->createMock(PageMiddlewareInterface::class);

		$this->container->method('get')->willReturnMap([
			['first', $first],
			['second', $second],
		]);

		$this->registry->register('auth', 'first');
		$this->registry->register('auth', 'second');

		$this->assertSame($second, $this->registry->resolve('auth'));
	}

	private function assertInvalidName(string $name): void
	{
		try {
			$this->registry->register($name, 'x');
			$this->fail("Expected '{$name}' to be rejected");
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}
	}
}
