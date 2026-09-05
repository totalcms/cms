<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\FileRemover;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

/**
 * FileRemover is the fallback for every property type without a dedicated
 * remover — which includes `gallery` AND the single-value `image` and `file`.
 *
 * Its filter loop only ever suited the gallery shape. GalleryData::transform()
 * returns a LIST of file maps; ImageData and FileData return a flat map of one
 * file's own fields, so iterating handed `$file['name']` a string and threw
 * "Cannot access offset of type string on string" — a 500 on every attempt to
 * delete the file from a single-image or single-file property.
 */
class FileRemoverTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $propFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectPatcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;

	protected function setUp(): void
	{
		$this->storage       = $this->createMock(PropertyRepository::class);
		$this->propFetcher   = $this->createMock(PropertyFetcher::class);
		$this->objectPatcher = $this->createMock(ObjectPatcher::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectFetcher->method('existsObject')->willReturn(true);
	}

	public function testEmptiesASingleImagePropertyRatherThanThrowing(): void
	{
		$this->propFetcher->method('fetchProperty')->willReturn(
			new ImageData(['name' => 'upload-test.png', 'mime' => 'image/png', 'size' => 100])
		);

		$this->objectPatcher->expects($this->once())
			->method('patchObject')
			->with('faculty', 'arlene-hines', ['facultyPhoto' => []])
			->willReturn($this->createMock(ObjectData::class));

		$this->remover()->deleteFile('faculty', 'arlene-hines', 'facultyPhoto', 'upload-test.png');
	}

	public function testStillFiltersAGalleryDownToTheRemainingImages(): void
	{
		// GalleryData takes the image list directly, not wrapped in an 'images' key.
		$this->propFetcher->method('fetchProperty')->willReturn(new GalleryData([
			['name' => 'delete-me.png', 'mime' => 'image/png', 'size' => 100],
			['name' => 'keep-me.png', 'mime' => 'image/png', 'size' => 200],
		]));

		$this->objectPatcher->expects($this->once())
			->method('patchObject')
			->with('gallery', 'obj-1', $this->callback(static function (array $data): bool {
				$images = $data['photos'];

				return count($images) === 1 && $images[0]['name'] === 'keep-me.png';
			}))
			->willReturn($this->createMock(ObjectData::class));

		$this->remover()->deleteFile('gallery', 'obj-1', 'photos', 'delete-me.png');
	}

	private function remover(): FileRemover
	{
		return new FileRemover($this->storage, $this->propFetcher, $this->objectPatcher, $this->objectFetcher);
	}
}
