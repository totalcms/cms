<?php

declare(strict_types=1);

namespace Tests\Unit\Twig\Extension;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Extension\LazyTwigGlobal;

final class LazyTwigGlobalTest extends TestCase
{
	public function testFactoryNotCalledUntilAccess(): void
	{
		$factoryCalled = false;
		$factory       = function () use (&$factoryCalled): \stdClass {
			$factoryCalled = true;

			return new \stdClass();
		};

		new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertFalse($factoryCalled);
	}

	public function testFactoryCalledOnFirstAccess(): void
	{
		$factoryCalled = false;
		$factory       = function () use (&$factoryCalled): \stdClass {
			$factoryCalled = true;
			$obj           = new \stdClass();
			$obj->value    = 'test';

			return $obj;
		};

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		// Access a property - should trigger factory
		$result = $lazy->value;

		$this->assertTrue($factoryCalled);
		$this->assertEquals('test', $result);
	}

	public function testFactoryCalledOnlyOnce(): void
	{
		$factoryCallCount = 0;
		$factory          = function () use (&$factoryCallCount): \stdClass {
			$factoryCallCount++;
			$obj        = new \stdClass();
			$obj->value = 'test';

			return $obj;
		};

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		// Access multiple times
		$result1 = $lazy->value;
		$result2 = $lazy->value;

		$this->assertEquals(1, $factoryCallCount);
		$this->assertEquals('test', $result1);
		$this->assertEquals('test', $result2);
	}

	public function testPropertyAccess(): void
	{
		$factory = function (): \stdClass {
			$obj       = new \stdClass();
			$obj->name = 'John';
			$obj->age  = 30;

			return $obj;
		};

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertEquals('John', $lazy->name);
		$this->assertEquals(30, $lazy->age);
	}

	public function testPropertySetting(): void
	{
		$factory = (fn (): \stdClass => new \stdClass());

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$lazy->dynamicProperty = 'dynamic_value';

		$this->assertEquals('dynamic_value', $lazy->dynamicProperty);
	}

	public function testPropertyIsset(): void
	{
		$factory = function (): \stdClass {
			$obj                   = new \stdClass();
			$obj->existingProperty = 'exists';

			return $obj;
		};

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertTrue(isset($lazy->existingProperty));
		$this->assertFalse(isset($lazy->nonExistentProperty));
	}

	public function testMethodCalls(): void
	{
		$factory = (fn (): object => new class {
			public function getName(): string
			{
				return 'TestObject';
			}

			public function calculate(int $a, int $b): int
			{
				return $a + $b;
			}
		});

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertEquals('TestObject', $lazy->getName());
		$this->assertEquals(15, $lazy->calculate(10, 5));
	}

	public function testMethodCallsWithComplexArguments(): void
	{
		$factory = (fn (): object => new class {
			public function processArray(array $data): int
			{
				return count($data);
			}

			public function processObject(\stdClass $obj): string
			{
				return $obj->name ?? 'unknown';
			}
		});

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertEquals(3, $lazy->processArray(['a', 'b', 'c']));

		$obj       = new \stdClass();
		$obj->name = 'TestName';
		$this->assertEquals('TestName', $lazy->processObject($obj));
	}

	public function testStringableInterface(): void
	{
		$factory = (fn (): \Stringable => new class implements \Stringable {
			public function __toString(): string
			{
				return 'StringableObject';
			}
		});

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertEquals('StringableObject', (string)$lazy);
	}

	public function testToStringWithNonStringableObject(): void
	{
		$factory = (fn (): \stdClass => new \stdClass());

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$result = (string)$lazy;

		$this->assertStringStartsWith('stdClass@', $result);
		$this->assertIsString($result);
	}

	public function testToStringWithObjectThatHasToStringMethod(): void
	{
		$factory = (fn (): object => new class implements \Stringable {
			public function __toString(): string
			{
				return 'CustomToString';
			}
		});

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertEquals('CustomToString', (string)$lazy);
	}

	public function testWithComplexObject(): void
	{
		$factory = (fn (): object => new class {
			public string $name = 'Complex';
			public array $data  = ['key' => 'value'];

			public function getData(): array
			{
				return $this->data;
			}

			public function setData(string $key, mixed $value): void
			{
				$this->data[$key] = $value;
			}
		});

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		// Test property access
		$this->assertEquals('Complex', $lazy->name);
		$this->assertEquals(['key' => 'value'], $lazy->data);

		// Test method calls
		$this->assertEquals(['key' => 'value'], $lazy->getData());

		// Test method that modifies state
		$lazy->setData('new_key', 'new_value');
		$this->assertEquals('new_value', $lazy->getData()['new_key']);
	}

	public function testWithFactoryThatThrowsException(): void
	{
		$factory = function (): never {
			throw new \RuntimeException('Factory failed');
		};

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Factory failed');

		// Exception should be thrown when trying to access
		$lazy->someProperty;
	}

	public function testWithFactoryReturningSimpleObject(): void
	{
		$factory = function (): \stdClass {
			$obj               = new \stdClass();
			$obj->testProperty = 'test_value';

			return $obj;
		};

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		$this->assertEquals('test_value', $lazy->testProperty);
	}

	public function testMultiplePropertyAccessAfterLoad(): void
	{
		$factoryCallCount = 0;
		$factory          = function () use (&$factoryCallCount): \stdClass {
			$factoryCallCount++;
			$obj        = new \stdClass();
			$obj->prop1 = 'value1';
			$obj->prop2 = 'value2';
			$obj->prop3 = 'value3';

			return $obj;
		};

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		// Access multiple properties
		$this->assertEquals('value1', $lazy->prop1);
		$this->assertEquals('value2', $lazy->prop2);
		$this->assertEquals('value3', $lazy->prop3);

		// Factory should only be called once
		$this->assertEquals(1, $factoryCallCount);
	}

	public function testMixedAccessPatterns(): void
	{
		$factory = (fn (): object => new class {
			public string $name = 'TestObject';

			public function getName(): string
			{
				return $this->name;
			}

			public function setName(string $name): void
			{
				$this->name = $name;
			}
		});

		$lazy = new LazyTwigGlobal(\Closure::fromCallable($factory));

		// Mix property access and method calls
		$this->assertEquals('TestObject', $lazy->name);
		$this->assertEquals('TestObject', $lazy->getName());

		$lazy->setName('NewName');
		$this->assertEquals('NewName', $lazy->name);
		$this->assertEquals('NewName', $lazy->getName());
	}

	public function testImplementsStringableInterface(): void
	{
		$lazy = new LazyTwigGlobal(fn (): \stdClass => new \stdClass());

		$this->assertInstanceOf(\Stringable::class, $lazy);
	}

	public function testFactoryWithDifferentObjectTypes(): void
	{
		// Test with different object types
		$factories = [
			fn (): \stdClass => new \stdClass(),
			fn (): \DateTime    => new \DateTime(),
			fn (): \ArrayObject => new \ArrayObject(['test' => 'data']),
		];

		foreach ($factories as $factory) {
			$lazy   = new LazyTwigGlobal(\Closure::fromCallable($factory));
			$result = (string)$lazy;
			$this->assertIsString($result);
		}
	}
}
