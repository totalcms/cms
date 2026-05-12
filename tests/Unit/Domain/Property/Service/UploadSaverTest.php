<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\UploadSaver;

describe('UploadSaver', function (): void {
	beforeEach(function (): void {
		$this->storage = $this->createMock(PropertyRepository::class);
		$this->saver   = new UploadSaver($this->storage);
	});

	test('save delegates to PropertyRepository and returns built path', function (): void {
		$this->storage
			->expects($this->once())
			->method('saveFile')
			->with('blog', 'post-1', 'upload', '/tmp/my file.pdf')
			->willReturn(['name' => 'my-file.pdf']);

		$result = $this->saver->save('blog', 'post-1', 'upload', '/tmp/my file.pdf');

		expect($result)->toBe('blog/post-1/upload/my-file.pdf');
	});

	test('save casts non-string name values via PathUtils::buildPath', function (): void {
		$this->storage
			->method('saveFile')
			->willReturn(['name' => 'numeric-123']);

		$result = $this->saver->save('c', 'o', 'p', '/tmp/x');

		expect($result)->toBe('c/o/p/numeric-123');
	});

	test('save with subpath stores file at nested path and returns nested path', function (): void {
		$this->storage
			->expects($this->once())
			->method('saveFile')
			->with('blog', 'post-1', 'mydeck', '/tmp/photo.jpg', 'item-3/pic')
			->willReturn(['name' => 'photo.jpg']);

		$result = $this->saver->save('blog', 'post-1', 'mydeck', '/tmp/photo.jpg', 'item-3/pic');

		expect($result)->toBe('blog/post-1/mydeck/item-3/pic/photo.jpg');
	});
});
