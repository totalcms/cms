<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Service\MediaMetaPolicy;

final class MediaMetaPolicyTest extends TestCase
{
	public function testEditableKeysPerType(): void
	{
		$this->assertContains('alt', MediaMetaPolicy::editableKeys('gallery'));
		$this->assertNotContains('name', MediaMetaPolicy::editableKeys('image'));
		$this->assertNotContains('password', MediaMetaPolicy::editableKeys('file'));
		$this->assertContains('password', MediaMetaPolicy::editableKeys('depot', protection: true));
		$this->assertSame([], MediaMetaPolicy::editableKeys('text'));
	}

	public function testMergeKeepsIdentityKeysAndMergesNestedOnes(): void
	{
		$stored = ['name' => 'a.jpg', 'hash' => 'h1', 'alt' => '', 'exif' => ['make' => 'Sony']];

		$merged = MediaMetaPolicy::merge('image', $stored, ['name' => 'b.jpg', 'hash' => 'h2', 'alt' => 'A', 'exif' => ['title' => 'T']]);

		$this->assertSame(['name' => 'a.jpg', 'hash' => 'h1', 'alt' => 'A', 'exif' => ['make' => 'Sony', 'title' => 'T']], $merged);
	}

	public function testRefusedKeysIgnoresUnchangedEchoes(): void
	{
		$stored = ['name' => 'a.pdf', 'size' => 10, 'protected' => true];

		$this->assertSame([], MediaMetaPolicy::refusedKeys('file', $stored, ['name' => 'a.pdf', 'size' => 10, 'comments' => 'x']));
		$this->assertSame(['size', 'protected'], MediaMetaPolicy::refusedKeys('file', $stored, ['size' => 11, 'protected' => false]));
		$this->assertSame([], MediaMetaPolicy::refusedKeys('file', $stored, ['protected' => false], protection: true));
	}
}
