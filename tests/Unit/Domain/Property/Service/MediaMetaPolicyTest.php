<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\ImageData;
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

	public function testCreditMergesOneLevelAndIsCleaned(): void
	{
		$stored = ['name' => 'a.jpg', 'credit' => ['author' => 'Nadin Sh', 'source' => 'Pexels']];

		$merged = MediaMetaPolicy::merge('gallery', $stored, ['credit' => [
			'license'   => ' Pexels License ',
			'authorUrl' => 'javascript:alert(1)',
			'sourceUrl' => 'https://www.pexels.com/photo/1/',
			'extra'     => 'dropped',
		]]);

		$this->assertSame([
			'author'    => 'Nadin Sh',
			'source'    => 'Pexels',
			'sourceUrl' => 'https://www.pexels.com/photo/1/',
			'license'   => 'Pexels License',
		], $merged['credit']);
	}

	public function testClearingEveryCreditFieldLeavesNoCredit(): void
	{
		$merged = MediaMetaPolicy::merge('image', ['credit' => ['author' => 'A']], ['credit' => ['author' => '', 'source' => '']]);

		$this->assertSame([], $merged['credit']);
		$this->assertArrayNotHasKey('credit', (new ImageData($merged))->transform());
	}

	public function testImageDataKeepsACreditAndOnlyThen(): void
	{
		$this->assertArrayNotHasKey('credit', (new ImageData(['name' => 'a.jpg']))->transform());
		$this->assertSame(['author' => 'A'], (new ImageData(['name' => 'a.jpg', 'credit' => ['author' => 'A', 'bogus' => 'x']]))->transform()['credit']);
	}
}
