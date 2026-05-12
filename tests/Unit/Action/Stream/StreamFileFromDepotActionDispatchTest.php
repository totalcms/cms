<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Stream;

use Odan\Session\PhpSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\Stream\StreamFileFromDepotAction;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

/**
 * Stream counterpart of {@see DownloadFileFromDepotActionDispatchTest}.
 * The action shares its dispatch shape with the download action — these
 * tests guard against the two diverging.
 */
final class StreamFileFromDepotActionDispatchTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $depotFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $fileFetcher;
	private StreamFileFromDepotAction $action;

	protected function setUp(): void
	{
		$this->depotFetcher = $this->createMock(DepotFileFetcher::class);
		$this->fileFetcher  = $this->createMock(FileFetcher::class);

		$this->action = new StreamFileFromDepotAction(
			$this->depotFetcher,
			$this->fileFetcher,
			$this->createMock(FileAccessManager::class),
			$this->createMock(ObjectUpdater::class),
			new PhpSession(),
			$this->createMock(PropertyFetcher::class),
		);
	}

	private function request(): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.test/stream/blog/post-1/mycard/file');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn([]);

		return $request;
	}

	public function testCardChildPathDispatchesToFileFetcher(): void
	{
		$this->fileFetcher->expects($this->once())
			->method('isNestedDirectory')
			->with('blog', 'post-1', 'mycard', 'file')
			->willReturn(true);

		$this->fileFetcher->expects($this->once())
			->method('fetchFile')
			->with('blog', 'post-1', 'mycard', 'file')
			->willReturn(new FileData(['name' => 'video.mp4']));

		$this->fileFetcher->expects($this->once())
			->method('fileExists')
			->with('blog', 'post-1', 'mycard', 'file')
			->willReturn(false);

		$this->depotFetcher->expects($this->never())->method('fileExists');

		$this->expectException(HttpNotFoundException::class);

		($this->action)(
			$this->request(),
			$this->createMock(ResponseInterface::class),
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'mycard', 'path' => 'file'],
		);
	}

	public function testDeckChildMultiSegmentPathDispatchesToFileFetcher(): void
	{
		$this->fileFetcher->method('isNestedDirectory')->willReturn(true);

		$this->fileFetcher->method('fetchFile')->willReturn(new FileData(['name' => 'deck.mp4']));

		$this->fileFetcher->expects($this->once())
			->method('fileExists')
			->with('blog', 'post-1', 'mydeck', 'item-3/file')
			->willReturn(false);

		$this->depotFetcher->expects($this->never())->method('fileExists');

		$this->expectException(HttpNotFoundException::class);

		($this->action)(
			$this->request(),
			$this->createMock(ResponseInterface::class),
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'mydeck', 'path' => 'item-3/file'],
		);
	}

	public function testDepotFilePathDispatchesToDepotFetcher(): void
	{
		$this->fileFetcher->method('isNestedDirectory')->willReturn(false);

		$this->depotFetcher->expects($this->once())
			->method('fileExists')
			->with('blog', 'post-1', 'depot', 'video.mp4', null)
			->willReturn(false);

		$this->fileFetcher->expects($this->never())->method('fileExists');

		$this->expectException(HttpNotFoundException::class);

		($this->action)(
			$this->request(),
			$this->createMock(ResponseInterface::class),
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'depot', 'path' => 'video.mp4'],
		);
	}
}
