<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Download;

use Odan\Session\PhpSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\Download\DownloadFileFromDepotAction;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DepotFileFetcher;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * The download URL `/download/{coll}/{id}/{prop}/{path:.+}` is shared between
 * legacy depot files (single-segment filename) and nested files (card/deck
 * children). The action dispatches based on `directoryExists` — these tests
 * lock in which fetcher is called for which shape.
 *
 * Each test stubs the chosen fetcher's `fileExists` to false so the action
 * short-circuits with HttpNotFoundException; we don't need to drive the full
 * download pipeline (auth, password, streaming) to verify dispatch.
 */
final class DownloadFileFromDepotActionDispatchTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $depotFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $fileFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private DownloadFileFromDepotAction $action;

	protected function setUp(): void
	{
		$this->depotFetcher = $this->createMock(DepotFileFetcher::class);
		$this->fileFetcher  = $this->createMock(FileFetcher::class);
		$this->storage      = $this->createMock(PropertyRepository::class);

		// PhpSession is `final` (can't be mocked). Instantiate without options;
		// the action's session calls (getFlash/set/get/delete/save) all resolve
		// to working in-memory operations on a fresh instance.
		$this->action = new DownloadFileFromDepotAction(
			$this->depotFetcher,
			$this->fileFetcher,
			$this->storage,
			$this->createMock(TwigRenderer::class),
			$this->createMock(FileAccessManager::class),
			$this->createMock(ObjectUpdater::class),
			new PhpSession(),
			$this->createMock(Config::class),
			$this->createMock(PropertyFetcher::class),
			$this->createMock(TranslationService::class),
		);
	}

	private function request(): ServerRequestInterface
	{
		// HttpNotFoundException needs a request with a URI for its constructor —
		// stub the chain getUri()->__toString() so the exception can render.
		$uri = $this->createMock(UriInterface::class);
		$uri->method('__toString')->willReturn('https://example.test/api/download/blog/post-1/mycard/file');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn([]);

		return $request;
	}

	public function testCardChildPathDispatchesToFileFetcher(): void
	{
		// Path resolves to a directory under the parent → nested file.
		$this->storage->expects($this->once())
			->method('directoryExists')
			->with('blog', 'post-1', 'mycard', 'file')
			->willReturn(true);

		// fetchFile resolves the FileData so the action can populate `args['name']`.
		$this->fileFetcher->expects($this->once())
			->method('fetchFile')
			->with('blog', 'post-1', 'mycard', 'file')
			->willReturn(new FileData(['name' => 'document.pdf']));

		// fileExists check happens inside parent::__invoke. Returning false
		// short-circuits with 404 — that's fine, we're proving dispatch.
		$this->fileFetcher->expects($this->once())
			->method('fileExists')
			->with('blog', 'post-1', 'mycard', 'file')
			->willReturn(false);

		// Depot fetcher must not be touched at all.
		$this->depotFetcher->expects($this->never())->method('fileExists');
		$this->depotFetcher->expects($this->never())->method('fetchFile');

		$this->expectException(HttpNotFoundException::class);

		($this->action)(
			$this->request(),
			$this->createMock(ResponseInterface::class),
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'mycard', 'path' => 'file'],
		);
	}

	public function testDeckChildMultiSegmentPathDispatchesToFileFetcher(): void
	{
		$this->storage->method('directoryExists')
			->with('blog', 'post-1', 'mydeck', 'item-3/file')
			->willReturn(true);

		$this->fileFetcher->method('fetchFile')
			->with('blog', 'post-1', 'mydeck', 'item-3/file')
			->willReturn(new FileData(['name' => 'deck-doc.pdf']));

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
		// `report.pdf` is a filename in the depot — no directory under the
		// property exists at that path → dispatch falls through to depot.
		$this->storage->method('directoryExists')->willReturn(false);

		$this->depotFetcher->expects($this->once())
			->method('fileExists')
			->with('blog', 'post-1', 'depot', 'report.pdf', null)
			->willReturn(false);

		// Nested fetcher must not be invoked when the path doesn't resolve to
		// a directory — that's the whole point of dispatching on filesystem state.
		$this->fileFetcher->expects($this->never())->method('fileExists');
		$this->fileFetcher->expects($this->never())->method('fetchFile');

		$this->expectException(HttpNotFoundException::class);

		($this->action)(
			$this->request(),
			$this->createMock(ResponseInterface::class),
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'depot', 'path' => 'report.pdf'],
		);
	}

	public function testEmptyPathDispatchesToDepotFetcher(): void
	{
		// No path at all — dispatch must never call directoryExists with empty
		// path, and must fall through to the depot branch.
		$this->storage->expects($this->never())->method('directoryExists');

		$this->depotFetcher->expects($this->once())
			->method('fileExists')
			->willReturn(false);

		$this->fileFetcher->expects($this->never())->method('fileExists');

		$this->expectException(HttpNotFoundException::class);

		($this->action)(
			$this->request(),
			$this->createMock(ResponseInterface::class),
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'depot'],
		);
	}
}
