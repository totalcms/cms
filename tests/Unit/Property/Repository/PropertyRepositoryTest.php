<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Tests for PropertyRepository - critical file and directory operations for properties.
 */
class PropertyRepositoryTest extends TestCase
{
	private PropertyRepository $propertyRepository;
	private \PHPUnit\Framework\MockObject\MockObject $mockFilesystem;

	protected function setUp(): void
	{
		$this->mockFilesystem     = $this->createMock(StorageAdapterInterface::class);
		$this->propertyRepository = new PropertyRepository($this->mockFilesystem);
	}

	public function testDeleteDirectory(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->with('test-collection/test-object/test-property/test-name');

		$this->propertyRepository->deleteDirectory('test-collection', 'test-object', 'test-property', 'test-name');
	}

	public function testDeleteDirectoryWithSubpath(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->with('test-collection/test-object/test-property/subfolder/test-name');

		$this->propertyRepository->deleteDirectory('test-collection', 'test-object', 'test-property', 'test-name', 'subfolder');
	}

	public function testDeleteDirectoryThrowsExceptionOnFailure(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->willThrowException(new \Exception('Delete failed'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to delete directory');

		$this->propertyRepository->deleteDirectory('test-collection', 'test-object', 'test-property');
	}

	public function testDeletePropertyCache(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->with('test-collection/test-object/test-property/.cache');

		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/.cache')
			->willReturn(false);

		$result = $this->propertyRepository->deletePropertyCache('test-collection', 'test-object', 'test-property');

		$this->assertTrue($result);
	}

	public function testDeletePropertyCacheReturnsFalseIfStillExists(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->with('test-collection/test-object/test-property/.cache');

		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/.cache')
			->willReturn(true);

		$result = $this->propertyRepository->deletePropertyCache('test-collection', 'test-object', 'test-property');

		$this->assertFalse($result);
	}

	public function testDeletePropertyCacheThrowsExceptionOnFailure(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->willThrowException(new \Exception('Delete failed'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to delete cache directory');

		$this->propertyRepository->deletePropertyCache('test-collection', 'test-object', 'test-property');
	}

	public function testDeleteFileCache(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->with('test-collection/test-object/test-property/.cache/test-file.jpg');

		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/.cache/test-file.jpg')
			->willReturn(false);

		$result = $this->propertyRepository->deleteFileCache('test-collection', 'test-object', 'test-property', 'test-file.jpg');

		$this->assertTrue($result);
	}

	public function testDeleteFileCacheThrowsExceptionOnFailure(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->willThrowException(new \Exception('Delete failed'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to delete cache directory');

		$this->propertyRepository->deleteFileCache('test-collection', 'test-object', 'test-property', 'test-file.jpg');
	}

	public function testDeleteFile(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('delete')
			->with('test-collection/test-object/test-property/test-file.jpg');

		// Should also delete file cache
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->with('test-collection/test-object/test-property/.cache/test-file.jpg');

		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/.cache/test-file.jpg')
			->willReturn(false);

		$this->propertyRepository->deleteFile('test-collection', 'test-object', 'test-property', 'test-file.jpg');
	}

	public function testDeleteFileWithSubpath(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('delete')
			->with('test-collection/test-object/test-property/subfolder/test-file.jpg');

		// Should also delete file cache
		$this->mockFilesystem
			->expects($this->once())
			->method('deleteDirectory')
			->with('test-collection/test-object/test-property/.cache/test-file.jpg');

		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/.cache/test-file.jpg')
			->willReturn(false);

		$this->propertyRepository->deleteFile('test-collection', 'test-object', 'test-property', 'test-file.jpg', 'subfolder');
	}

	public function testDeleteFileThrowsExceptionOnFailure(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('delete')
			->willThrowException(new \Exception('Delete failed'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to delete file test-file.jpg');

		$this->propertyRepository->deleteFile('test-collection', 'test-object', 'test-property', 'test-file.jpg');
	}

	public function testSaveFile(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/test-file.jpg')
			->willReturn(false);

		$this->mockFilesystem
			->expects($this->once())
			->method('import')
			->with('/tmp/test-file.jpg', 'test-collection/test-object/test-property/test-file.jpg')
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('fileSize')
			->with('test-collection/test-object/test-property/test-file.jpg')
			->willReturn(12345);

		$this->mockFilesystem
			->expects($this->once())
			->method('mimeType')
			->with('test-collection/test-object/test-property/test-file.jpg')
			->willReturn('image/jpeg');

		$result = $this->propertyRepository->saveFile('test-collection', 'test-object', 'test-property', '/tmp/test-file.jpg');

		$this->assertIsArray($result);
		$this->assertEquals('test-file.jpg', $result['name']);
		$this->assertEquals(12345, $result['size']);
		$this->assertEquals('image/jpeg', $result['mime']);
		$this->assertArrayHasKey('uploadDate', $result);
	}

	public function testSaveFileWithUniqueNameWhenFileExists(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/test-file.jpg')
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('import')
			->with($this->matchesRegularExpression('/\/tmp\/test-file\.jpg$/'), $this->matchesRegularExpression('/test-collection\/test-object\/test-property\/test-file-[a-z0-9]{5}\.jpg$/'))
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('fileSize')
			->willReturn(12345);

		$this->mockFilesystem
			->expects($this->once())
			->method('mimeType')
			->willReturn('image/jpeg');

		$result = $this->propertyRepository->saveFile('test-collection', 'test-object', 'test-property', '/tmp/test-file.jpg');

		$this->assertIsArray($result);
		$this->assertMatchesRegularExpression('/test-file-[a-z0-9]{5}\.jpg/', $result['name']);
	}

	public function testSaveFileWithSubpath(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/subfolder/test-file.jpg')
			->willReturn(false);

		$this->mockFilesystem
			->expects($this->once())
			->method('import')
			->with('/tmp/test-file.jpg', 'test-collection/test-object/test-property/subfolder/test-file.jpg')
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('fileSize')
			->willReturn(12345);

		$this->mockFilesystem
			->expects($this->once())
			->method('mimeType')
			->willReturn('image/jpeg');

		$result = $this->propertyRepository->saveFile('test-collection', 'test-object', 'test-property', '/tmp/test-file.jpg', 'subfolder');

		$this->assertIsArray($result);
		$this->assertEquals('test-file.jpg', $result['name']);
	}

	public function testSaveFileThrowsExceptionOnImportFailure(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->willReturn(false);

		$this->mockFilesystem
			->expects($this->once())
			->method('import')
			->willReturn(false);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File not saved');

		$this->propertyRepository->saveFile('test-collection', 'test-object', 'test-property', '/tmp/test-file.jpg');
	}

	public function testMoveFile(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/new-location/test-file.jpg')
			->willReturn(false);

		$this->mockFilesystem
			->expects($this->once())
			->method('move')
			->with('test-collection/test-object/test-property/test-file.jpg', 'test-collection/test-object/test-property/new-location/test-file.jpg')
			->willReturn(true);

		$result = $this->propertyRepository->moveFile('test-collection', 'test-object', 'test-property', 'test-file.jpg', null, 'new-location');

		$this->assertTrue($result);
	}

	public function testMoveFileThrowsExceptionIfDestinationExists(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/new-location/test-file.jpg')
			->willReturn(true);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File already exists in destination');

		$this->propertyRepository->moveFile('test-collection', 'test-object', 'test-property', 'test-file.jpg', null, 'new-location');
	}

	public function testFileExists(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/test-file.jpg')
			->willReturn(true);

		$result = $this->propertyRepository->fileExists('test-collection', 'test-object', 'test-property', 'test-file.jpg');

		$this->assertTrue($result);
	}

	public function testFileExistsWithSubpath(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('test-collection/test-object/test-property/subfolder/test-file.jpg')
			->willReturn(false);

		$result = $this->propertyRepository->fileExists('test-collection', 'test-object', 'test-property', 'test-file.jpg', 'subfolder');

		$this->assertFalse($result);
	}

	public function testStreamFile(): void
	{
		$mockStream = fopen('php://memory', 'r');

		$this->mockFilesystem
			->expects($this->once())
			->method('readStream')
			->with('test-collection/test-object/test-property/test-file.jpg')
			->willReturn($mockStream);

		$result = $this->propertyRepository->streamFile('test-collection', 'test-object', 'test-property', 'test-file.jpg');

		$this->assertIsResource($result);
		$this->assertEquals($mockStream, $result);

		fclose($mockStream);
	}

	public function testStreamFileWithSubpath(): void
	{
		$mockStream = fopen('php://memory', 'r');

		$this->mockFilesystem
			->expects($this->once())
			->method('readStream')
			->with('test-collection/test-object/test-property/subfolder/test-file.jpg')
			->willReturn($mockStream);

		$result = $this->propertyRepository->streamFile('test-collection', 'test-object', 'test-property', 'test-file.jpg', 'subfolder');

		$this->assertIsResource($result);
		fclose($mockStream);
	}

	public function testMimeType(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('mimeType')
			->with('test-collection/test-object/test-property/test-file.jpg')
			->willReturn('image/jpeg');

		$result = $this->propertyRepository->mimeType('test-collection', 'test-object', 'test-property', 'test-file.jpg');

		$this->assertEquals('image/jpeg', $result);
	}

	public function testMimeTypeWithSubpath(): void
	{
		$this->mockFilesystem
			->expects($this->once())
			->method('mimeType')
			->with('test-collection/test-object/test-property/subfolder/test-file.jpg')
			->willReturn('image/png');

		$result = $this->propertyRepository->mimeType('test-collection', 'test-object', 'test-property', 'test-file.jpg', 'subfolder');

		$this->assertEquals('image/png', $result);
	}

	public function testSaveImage(): void
	{
		// Create a simple temporary test image
		$tempImage = tempnam(sys_get_temp_dir(), 'test_image') . '.jpg';
		$image     = imagecreate(100, 50);
		$white     = imagecolorallocate($image, 255, 255, 255);
		imagefill($image, 0, 0, $white);
		imagejpeg($image, $tempImage);
		unset($image); // GD images are auto-garbage collected since PHP 8.0

		$this->mockFilesystem
			->expects($this->once())
			->method('import')
			->with($tempImage, $this->matchesRegularExpression('/test-collection\/test-object\/test-property\/test_image[^\/]*\.jpg$/'))
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('fileSize')
			->willReturn(12345);

		$this->mockFilesystem
			->expects($this->once())
			->method('mimeType')
			->willReturn('image/jpeg');

		$result = $this->propertyRepository->saveImage('test-collection', 'test-object', 'test-property', $tempImage);

		$this->assertIsArray($result);
		$this->assertMatchesRegularExpression('/test_image[^\.]*\.jpg/', $result['name']);
		$this->assertEquals(12345, $result['size']);
		$this->assertEquals('image/jpeg', $result['mime']);
		$this->assertEquals(100, $result['width']);
		$this->assertEquals(50, $result['height']);
		$this->assertArrayHasKey('uploadDate', $result);

		// Clean up
		unlink($tempImage);
	}

	public function testSaveImageThrowsExceptionForInvalidImage(): void
	{
		// Create a text file that's not an image
		$tempFile = tempnam(sys_get_temp_dir(), 'not_image') . '.txt';
		file_put_contents($tempFile, 'This is not an image');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to process image file');

		try {
			$this->propertyRepository->saveImage('test-collection', 'test-object', 'test-property', $tempFile);
		} finally {
			unlink($tempFile);
		}
	}

	public function testSaveImageThrowsExceptionOnImportFailure(): void
	{
		// Create a simple test image
		$tempImage = tempnam(sys_get_temp_dir(), 'test_image') . '.jpg';
		$image     = imagecreate(10, 10);
		$white     = imagecolorallocate($image, 255, 255, 255);
		imagefill($image, 0, 0, $white);
		imagejpeg($image, $tempImage);
		unset($image); // GD images are auto-garbage collected since PHP 8.0

		$this->mockFilesystem
			->expects($this->once())
			->method('import')
			->willReturn(false);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Image not saved');

		try {
			$this->propertyRepository->saveImage('test-collection', 'test-object', 'test-property', $tempImage);
		} finally {
			unlink($tempImage);
		}
	}
}
