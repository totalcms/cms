<?php

declare(strict_types = 1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Factory\LoggerFactory;

class ImageSaverTest extends TestCase
{
	private ImageSaver $imageSaver;
	private \PHPUnit\Framework\MockObject\MockObject $mockStorage;
	private \PHPUnit\Framework\MockObject\MockObject $mockPropFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectSaver;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectPatcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectFetcher;
	private LoggerFactory $loggerFactory;
	private \PHPUnit\Framework\MockObject\MockObject $mockLogger;

	protected function setUp(): void
	{
		$this->mockStorage       = $this->createMock(PropertyRepository::class);
		$this->mockPropFetcher   = $this->createMock(PropertyFetcher::class);
		$this->mockObjectSaver   = $this->createMock(ObjectSaver::class);
		$this->mockObjectPatcher = $this->createMock(ObjectPatcher::class);
		$this->mockObjectFetcher = $this->createMock(ObjectFetcher::class);
		$this->mockLogger        = $this->createMock(LoggerInterface::class);

		// Create LoggerFactory in test mode with mock logger
		$this->loggerFactory = new LoggerFactory(['test' => $this->mockLogger, 'level' => \Monolog\Level::Debug]);

		$this->imageSaver = new ImageSaver(
			$this->mockStorage,
			$this->mockPropFetcher,
			$this->mockObjectSaver,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher,
			$this->loggerFactory
		);
	}

	public function testTypeProperty(): void
	{
		$this->assertEquals('image', $this->imageSaver->type);
	}

	public function testSaveCreateObjectException(): void
	{
		$collection = 'test-collection';
		$objectID   = 'test-object';
		$property   = 'photo';
		$filePath   = '/tmp/test.jpg';

		// Object doesn't exist
		$this->mockObjectFetcher->expects($this->once())
			->method('existsObject')
			->willReturn(false);

		// Object creation fails
		$this->mockObjectSaver->expects($this->once())
			->method('saveObject')
			->willThrowException(new \Exception('Creation failed'));

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Object test-object does not exist in collection test-collection to save file (photo) to.');

		$this->imageSaver->save($collection, $objectID, $property, $filePath);
	}

	public function testInheritsFromFileSaver(): void
	{
		// Test that ImageSaver properly extends FileSaver
		$this->assertInstanceOf(\TotalCMS\Domain\Property\Service\FileSaver::class, $this->imageSaver);
	}

	public function testHasLoggerFactoryInjected(): void
	{
		// Test that logger factory is properly injected via dependency injection
		$reflection = new \ReflectionClass($this->imageSaver);
		$this->assertTrue($reflection->hasProperty('loggerFactory'));

		$loggerFactoryProperty = $reflection->getProperty('loggerFactory');
		$loggerFactoryProperty->setAccessible(true);
		$injectedFactory = $loggerFactoryProperty->getValue($this->imageSaver);

		$this->assertInstanceOf(LoggerFactory::class, $injectedFactory);
	}

	public function testUsesLoggerAwareTrait(): void
	{
		// Test that the class inherits LoggerAwareTrait from FileSaver
		$reflection = new \ReflectionClass($this->imageSaver);
		$allTraits  = $this->getAllTraitsFromClass($reflection);
		$this->assertContains('TotalCMS\Traits\LoggerAwareTrait', $allTraits);
	}

	private function getAllTraitsFromClass(\ReflectionClass $class): array
	{
		$traits = [];

		// Get traits from current class and all parent classes
		do {
			$traits = array_merge($traits, $class->getTraitNames());
		} while ($class = $class->getParentClass());

		return $traits;
	}
}
