<?php

declare(strict_types = 1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Factory\LoggerFactory;

class FileSaverTest extends TestCase
{
	private FileSaver $fileSaver;
	private \PHPUnit\Framework\MockObject\MockObject $mockStorage;
	private \PHPUnit\Framework\MockObject\MockObject $mockPropFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectSaver;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectPatcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockLoggerFactory;

	protected function setUp(): void
	{
		$this->mockStorage       = $this->createMock(PropertyRepository::class);
		$this->mockPropFetcher   = $this->createMock(PropertyFetcher::class);
		$this->mockObjectSaver   = $this->createMock(ObjectSaver::class);
		$this->mockObjectPatcher = $this->createMock(ObjectPatcher::class);
		$this->mockObjectFetcher = $this->createMock(ObjectFetcher::class);
		$this->mockLoggerFactory = $this->createMock(LoggerFactory::class);

		$this->fileSaver = new FileSaver(
			$this->mockStorage,
			$this->mockPropFetcher,
			$this->mockObjectSaver,
			$this->mockObjectPatcher,
			$this->mockObjectFetcher,
			$this->mockLoggerFactory
		);
	}

	public function testSaveWithNewObject(): void
	{
		$collection = 'test-collection';
		$objectID   = 'test-object';
		$property   = 'document';
		$filePath   = '/tmp/test.pdf';

		// Object doesn't exist, so will be created
		$this->mockObjectFetcher->expects($this->once())
			->method('existsObject')
			->with($collection, $objectID)
			->willReturn(false);

		// Expect object creation
		$this->mockObjectSaver->expects($this->once())
			->method('saveObject')
			->with($collection, $this->callback(function ($data) use ($objectID, $property) {
				return $data['id'] === $objectID && isset($data[$property]);
			}));

		// Clean up existing files
		$this->mockStorage->expects($this->once())
			->method('deleteDirectory')
			->with($collection, $objectID, $property);

		// Save the file
		$fileInfo = [
			'name'      => 'test.pdf',
			'size'      => 1024,
			'mime'      => 'application/pdf',
			'extension' => 'pdf',
		];
		$this->mockStorage->expects($this->once())
			->method('saveFile')
			->with($collection, $objectID, $property, $filePath)
			->willReturn($fileInfo);

		// Patch the object with new data - FileData.transform() includes more than just fileInfo
		$expectedResult = new ObjectData($objectID, []);
		$this->mockObjectPatcher->expects($this->once())
			->method('patchObject')
			->with($collection, $objectID, $this->callback(function ($data) use ($property, $fileInfo) {
				$propData = $data[$property];

				// FileData.transform() includes the file info plus default properties
				return $propData['name'] === $fileInfo['name']
					   && $propData['size'] === $fileInfo['size']
					   && $propData['mime'] === $fileInfo['mime']
					   && isset($propData['tags'])
					   && isset($propData['password'])
					   && isset($propData['uploadDate'])
					   && isset($propData['protected'])
					   && isset($propData['download'])
					   && isset($propData['comments'])
					   && isset($propData['count']);
			}))
			->willReturn($expectedResult);

		$result = $this->fileSaver->save($collection, $objectID, $property, $filePath);

		$this->assertInstanceOf(ObjectData::class, $result);
		$this->assertEquals($expectedResult, $result);
	}

	public function testSaveWithExistingObject(): void
	{
		$collection = 'test-collection';
		$objectID   = 'test-object';
		$property   = 'document';
		$filePath   = '/tmp/test.pdf';

		// Object exists
		$this->mockObjectFetcher->expects($this->once())
			->method('existsObject')
			->with($collection, $objectID)
			->willReturn(true);

		// No object creation expected

		// Clean up existing files
		$this->mockStorage->expects($this->once())
			->method('deleteDirectory')
			->with($collection, $objectID, $property);

		// Save the file
		$fileInfo = [
			'name'      => 'test.pdf',
			'size'      => 1024,
			'mime'      => 'application/pdf',
			'extension' => 'pdf',
		];
		$this->mockStorage->expects($this->once())
			->method('saveFile')
			->with($collection, $objectID, $property, $filePath)
			->willReturn($fileInfo);

		// Fetch existing property data
		$existingFileData = new FileData([
			'name'      => 'old.pdf',
			'download'  => 'Custom Download Name.pdf',
			'comments'  => 'Important document',
			'tags'      => ['work', 'important'],
			'protected' => true,
		]);
		$this->mockPropFetcher->expects($this->once())
			->method('fetchProperty')
			->with($collection, $objectID, $property)
			->willReturn($existingFileData);

		// Patch the object with merged data
		$expectedResult = new ObjectData($objectID, []);
		$this->mockObjectPatcher->expects($this->once())
			->method('patchObject')
			->with($collection, $objectID, $this->callback(function ($data) use ($property) {
				$propData = $data[$property];

				// Should merge existing data with new file info
				return isset($propData['name'])
					   && isset($propData['download'])
					   && isset($propData['comments'])
					   && isset($propData['tags'])
					   && isset($propData['protected']);
			}))
			->willReturn($expectedResult);

		$result = $this->fileSaver->save($collection, $objectID, $property, $filePath);

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testSaveWithExistingObjectAndExtensionChange(): void
	{
		$collection = 'test-collection';
		$objectID   = 'test-object';
		$property   = 'document';
		$filePath   = '/tmp/test.docx'; // Different extension

		// Object exists
		$this->mockObjectFetcher->expects($this->once())
			->method('existsObject')
			->with($collection, $objectID)
			->willReturn(true);

		// Clean up existing files
		$this->mockStorage->expects($this->once())
			->method('deleteDirectory')
			->with($collection, $objectID, $property);

		// Save the file with new extension
		$fileInfo = [
			'name'      => 'test.docx',
			'size'      => 2048,
			'mime'      => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'extension' => 'docx',
		];
		$this->mockStorage->expects($this->once())
			->method('saveFile')
			->with($collection, $objectID, $property, $filePath)
			->willReturn($fileInfo);

		// Fetch existing property data with different extension
		$existingFileData = new FileData([
			'name'     => 'old.pdf',
			'download' => 'Custom Document.pdf', // Old extension
			'comments' => 'Document',
		]);
		$this->mockPropFetcher->expects($this->once())
			->method('fetchProperty')
			->with($collection, $objectID, $property)
			->willReturn($existingFileData);

		// Should update extension in download name
		$this->mockObjectPatcher->expects($this->once())
			->method('patchObject')
			->with($collection, $objectID, $this->callback(function ($data) use ($property) {
				$propData = $data[$property];

				// Download name should have updated extension
				return $propData['download'] === 'Custom Document.docx';
			}))
			->willReturn(new ObjectData($objectID, []));

		$result = $this->fileSaver->save($collection, $objectID, $property, $filePath);
		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testCreateObjectThrowsExceptionOnFailure(): void
	{
		$collection = 'test-collection';
		$objectID   = 'test-object';
		$property   = 'document';
		$filePath   = '/tmp/test.pdf';

		// Object doesn't exist
		$this->mockObjectFetcher->expects($this->once())
			->method('existsObject')
			->with($collection, $objectID)
			->willReturn(false);

		// Object creation fails
		$this->mockObjectSaver->expects($this->once())
			->method('saveObject')
			->willThrowException(new \Exception('Creation failed'));

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Object test-object does not exist in collection test-collection to save file (document) to.');

		$this->fileSaver->save($collection, $objectID, $property, $filePath);
	}

	public function testTypeProperty(): void
	{
		$this->assertEquals('file', $this->fileSaver->type);
	}

	public function testFetchPropertyCreatesNewWhenNotFound(): void
	{
		$collection = 'test-collection';
		$objectID   = 'test-object';
		$property   = 'document';
		$filePath   = '/tmp/test.pdf';

		// Object exists
		$this->mockObjectFetcher->expects($this->once())
			->method('existsObject')
			->willReturn(true);

		// Clean up
		$this->mockStorage->expects($this->once())
			->method('deleteDirectory');

		// Save file
		$this->mockStorage->expects($this->once())
			->method('saveFile')
			->willReturn(['name' => 'test.pdf']);

		// Property fetch fails, should create new property
		$this->mockPropFetcher->expects($this->once())
			->method('fetchProperty')
			->willThrowException(new \UnexpectedValueException('Property not found'));

		// Should still proceed with save
		$this->mockObjectPatcher->expects($this->once())
			->method('patchObject')
			->willReturn(new ObjectData($objectID, []));

		$result = $this->fileSaver->save($collection, $objectID, $property, $filePath);
		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testSaveIgnoresSubpathParameter(): void
	{
		$collection = 'test-collection';
		$objectID   = 'test-object';
		$property   = 'document';
		$filePath   = '/tmp/test.pdf';
		$subpath    = 'some/path'; // Should be ignored for FileSaver

		$this->mockObjectFetcher->expects($this->once())
			->method('existsObject')
			->willReturn(false);

		$this->mockObjectSaver->expects($this->once())
			->method('saveObject');

		$this->mockStorage->expects($this->once())
			->method('deleteDirectory');

		// Save file should be called without subpath (only 4 parameters)
		$this->mockStorage->expects($this->once())
			->method('saveFile')
			->with($collection, $objectID, $property, $filePath)
			->willReturn(['name' => 'test.pdf']);

		$this->mockObjectPatcher->expects($this->once())
			->method('patchObject')
			->willReturn(new ObjectData($objectID, []));

		$result = $this->fileSaver->save($collection, $objectID, $property, $filePath, $subpath);
		$this->assertInstanceOf(ObjectData::class, $result);
	}
}
