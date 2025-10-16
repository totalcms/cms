<?php

namespace Tests\Unit\Domain\Template\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateRemover;

final class TemplateRemoverTest extends TestCase
{
	private TemplateRemover $remover;
	private TemplateRepository $storage;

	protected function setUp(): void
	{
		$this->storage = $this->createMock(TemplateRepository::class);
		$this->remover = new TemplateRemover($this->storage);
	}

	public function testDeleteTemplateSuccessfully(): void
	{
		$this->storage->expects($this->once())
			->method('reservedTemplateExists')
			->with('custom-template')
			->willReturn(false);

		$this->storage->expects($this->once())
			->method('deleteTemplate')
			->with('custom-template', null)
			->willReturn(true);

		$result = $this->remover->deleteTemplate('custom-template');

		$this->assertTrue($result);
	}

	public function testDeleteTemplateWithFolder(): void
	{
		$this->storage->method('reservedTemplateExists')->willReturn(false);

		$this->storage->expects($this->once())
			->method('deleteTemplate')
			->with('my-template', 'custom-folder')
			->willReturn(true);

		$result = $this->remover->deleteTemplate('my-template', 'custom-folder');

		$this->assertTrue($result);
	}

	public function testDeleteTemplateReturnsFalseWhenNotFound(): void
	{
		$this->storage->method('reservedTemplateExists')->willReturn(false);

		$this->storage->expects($this->once())
			->method('deleteTemplate')
			->with('nonexistent', null)
			->willReturn(false);

		$result = $this->remover->deleteTemplate('nonexistent');

		$this->assertFalse($result);
	}

	public function testDeleteTemplateThrowsExceptionForBuiltInTemplate(): void
	{
		$this->storage->expects($this->once())
			->method('reservedTemplateExists')
			->with('blog')
			->willReturn(true);

		$this->storage->expects($this->never())
			->method('deleteTemplate');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('Cannot delete a built-in template.');

		$this->remover->deleteTemplate('blog');
	}

	public function testDeleteTemplateChecksReservedBeforeDeletion(): void
	{
		$this->storage->expects($this->once())
			->method('reservedTemplateExists')
			->with('test-template')
			->willReturn(false);

		$this->storage->expects($this->once())
			->method('deleteTemplate')
			->willReturn(true);

		$this->remover->deleteTemplate('test-template');
	}

	public function testDeleteTemplateThrowsForReservedTemplateWithFolder(): void
	{
		$this->storage->expects($this->once())
			->method('reservedTemplateExists')
			->with('feed')
			->willReturn(true);

		$this->expectException(\DomainException::class);

		$this->remover->deleteTemplate('feed', 'my-folder');
	}

	public function testDeleteTemplatePassesNullFolderByDefault(): void
	{
		$this->storage->method('reservedTemplateExists')->willReturn(false);

		$this->storage->expects($this->once())
			->method('deleteTemplate')
			->with('my-template', null)
			->willReturn(true);

		$this->remover->deleteTemplate('my-template');
	}
}
