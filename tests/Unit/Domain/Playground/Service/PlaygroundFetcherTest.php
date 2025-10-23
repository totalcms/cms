<?php

namespace Tests\Unit\Domain\Playground\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Playground\Service\PlaygroundFetcher;

final class PlaygroundFetcherTest extends TestCase
{
	private PlaygroundFetcher $fetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;

	protected function setUp(): void
	{
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->fetcher       = new PlaygroundFetcher($this->objectFetcher);
	}

	public function testGetSnippetFetchesFromPlaygroundCollection(): void
	{
		$objectData = $this->createObjectData('snippet-1');

		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with(PlaygroundData::COLLECTION_ID, 'snippet-1')
			->willReturn($objectData);

		$result = $this->fetcher->getSnippet('snippet-1');

		$this->assertSame($objectData, $result);
	}

	public function testGetSnippetReturnsObjectData(): void
	{
		$objectData = $this->createObjectData('test-snippet');

		$this->objectFetcher->method('fetchObject')->willReturn($objectData);

		$result = $this->fetcher->getSnippet('test-snippet');

		$this->assertInstanceOf(ObjectData::class, $result);
	}

	public function testGetSnippetPassesCorrectCollectionId(): void
	{
		$objectData = $this->createObjectData('my-snippet');

		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with(
				$this->equalTo(PlaygroundData::COLLECTION_ID),
				$this->anything()
			)
			->willReturn($objectData);

		$this->fetcher->getSnippet('my-snippet');
	}

	public function testSnippetExistsReturnsTrueWhenExists(): void
	{
		$this->objectFetcher->expects($this->once())
			->method('existsObject')
			->with(PlaygroundData::COLLECTION_ID, 'snippet-1')
			->willReturn(true);

		$result = $this->fetcher->snippetExists('snippet-1');

		$this->assertTrue($result);
	}

	public function testSnippetExistsReturnsFalseWhenNotExists(): void
	{
		$this->objectFetcher->expects($this->once())
			->method('existsObject')
			->with(PlaygroundData::COLLECTION_ID, 'nonexistent')
			->willReturn(false);

		$result = $this->fetcher->snippetExists('nonexistent');

		$this->assertFalse($result);
	}

	public function testSnippetExistsUsesPlaygroundCollection(): void
	{
		$this->objectFetcher->expects($this->once())
			->method('existsObject')
			->with(
				$this->equalTo(PlaygroundData::COLLECTION_ID),
				$this->anything()
			)
			->willReturn(false);

		$this->fetcher->snippetExists('test');
	}

	public function testMultipleGetSnippetCalls(): void
	{
		$snippet1 = $this->createObjectData('snippet-1');
		$snippet2 = $this->createObjectData('snippet-2');

		$this->objectFetcher->expects($this->exactly(2))
			->method('fetchObject')
			->willReturnOnConsecutiveCalls($snippet1, $snippet2);

		$result1 = $this->fetcher->getSnippet('snippet-1');
		$result2 = $this->fetcher->getSnippet('snippet-2');

		$this->assertSame($snippet1, $result1);
		$this->assertSame($snippet2, $result2);
	}

	public function testMultipleSnippetExistsCalls(): void
	{
		$this->objectFetcher->expects($this->exactly(3))
			->method('existsObject')
			->willReturnOnConsecutiveCalls(true, false, true);

		$this->assertTrue($this->fetcher->snippetExists('exists-1'));
		$this->assertFalse($this->fetcher->snippetExists('nonexistent'));
		$this->assertTrue($this->fetcher->snippetExists('exists-2'));
	}

	private function createObjectData(string $id): ObjectData
	{
		return new ObjectData($id, [
			'name' => "Snippet {$id}",
		]);
	}
}
