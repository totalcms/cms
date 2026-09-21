<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Seo\IndexNow;

use Monolog\Level;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Seo\IndexNow\IndexNowListener;
use TotalCMS\Domain\Seo\IndexNow\IndexNowOutbox;
use TotalCMS\Domain\Seo\IndexNow\IndexNowSubmitter;
use TotalCMS\Domain\Sitemap\Service\SitemapUrlResolver;
use TotalCMS\Factory\LoggerFactory;

/**
 * Driven through a real EventDispatcher so the payload arrives exactly as
 * the container's $lazy() registration delivers it.
 *
 * That means `id`, and `object` / `previous` as live ObjectData. A listener
 * reading a key the payload does not carry passes a hand-built unit test and
 * does nothing in production.
 */
final class IndexNowListenerTest extends TestCase
{
	private EventDispatcher $dispatcher;
	private MockObject $resolver;
	private MockObject $submitter;
	private MockObject $outbox;
	private MockObject $repository;
	private MockObject $jobs;
	private LoggerFactory $loggerFactory;

	protected function setUp(): void
	{
		$this->resolver   = $this->createMock(SitemapUrlResolver::class);
		$this->submitter  = $this->createMock(IndexNowSubmitter::class);
		$this->outbox     = $this->createMock(IndexNowOutbox::class);
		$this->repository = $this->createMock(JobRepository::class);
		$this->jobs       = $this->createMock(JobQueuer::class);

		$this->loggerFactory = new LoggerFactory(['level' => Level::Debug, 'test' => new NullLogger()]);
		$this->submitter->method('isConfigured')->willReturn(true);
		$this->repository->method('hasPendingJob')->willReturn(false);

		$this->dispatcher = new EventDispatcher(new NullLogger());
		$this->wire(new IndexNowListener($this->resolver, $this->submitter, $this->outbox, $this->repository, $this->jobs, $this->loggerFactory));
	}

	private function wire(IndexNowListener $listener): void
	{
		$this->dispatcher->listen('object.created', $listener->onObjectSaved(...));
		$this->dispatcher->listen('object.updated', $listener->onObjectSaved(...));
		$this->dispatcher->listen('object.deleted', $listener->onObjectDeleted(...));
		$this->dispatcher->listen('import.created', $listener->onImportSaved(...));
		$this->dispatcher->listen('import.updated', $listener->onImportSaved(...));
		$this->dispatcher->listen('import.completed', $listener->onImportCompleted(...));
	}

	public function testAnImportBuffersItsUrlsAndFlushesThemInOneWriteOnCompletion(): void
	{
		$this->resolver->method('urlFor')->willReturnCallback(fn (string $c, array $o): string => "https://example.com/blog/{$o['id']}");

		// Nothing reaches the outbox while records stream in…
		$this->outbox->expects($this->once())->method('add')
			->with(['https://example.com/blog/p1', 'https://example.com/blog/p2', 'https://example.com/blog/p3']);
		$this->jobs->expects($this->once())->method('queueJob');

		$this->dispatcher->dispatch('import.created', new ObjectEventPayload('blog', 'p1', new ObjectData('p1', [])));
		$this->dispatcher->dispatch('import.updated', new ObjectEventPayload('blog', 'p2', new ObjectData('p2', []), new ObjectData('p2', [])));
		$this->dispatcher->dispatch('import.created', new ObjectEventPayload('blog', 'p3', new ObjectData('p3', [])));
		// …and one write when the import completes.
		$this->dispatcher->dispatch('import.completed', new ImportEventPayload('blog', 3, ['p1', 'p3'], ['p2']));
	}

	public function testABufferAtTheCapFlushesWithoutWaitingForCompletion(): void
	{
		$this->resolver->method('urlFor')->willReturnCallback(fn (string $c, array $o): string => "https://example.com/blog/{$o['id']}");

		$writes = [];
		$this->outbox->method('add')->willReturnCallback(function (array $urls) use (&$writes): void {
			$writes[] = count($urls);
		});

		for ($i = 1; $i <= 250; $i++) {
			$this->dispatcher->dispatch('import.created', new ObjectEventPayload('blog', "p{$i}", new ObjectData("p{$i}", [])));
		}
		$this->dispatcher->dispatch('import.completed', new ImportEventPayload('blog', 250));

		$this->assertSame([100, 100, 50], $writes);
	}

	public function testAnImporterThatNeverSignalsCompletionStillFlushesAtProcessEnd(): void
	{
		// Deck imports and queued single-object imports fire import.* per
		// record with no import.completed; the destructor is their flush.
		$this->resolver->method('urlFor')->willReturn('https://example.com/blog/p1');
		$this->outbox->expects($this->once())->method('add')->with(['https://example.com/blog/p1']);

		$listener = new IndexNowListener($this->resolver, $this->submitter, $this->outbox, $this->repository, $this->jobs, $this->loggerFactory);
		$listener->onImportSaved((new ObjectEventPayload('blog', 'p1', new ObjectData('p1', [])))->toArray());
		unset($listener);
	}

	public function testImportsOfUnlistedRecordsBufferNothing(): void
	{
		$this->resolver->method('urlFor')->willReturn(null);
		$this->outbox->expects($this->never())->method('add');

		$this->dispatcher->dispatch('import.created', new ObjectEventPayload('blog', 'draft', new ObjectData('draft', [])));
		$this->dispatcher->dispatch('import.completed', new ImportEventPayload('blog', 1, ['draft']));
	}

	public function testASaveAppendsTheObjectsSitemapUrlAndEnsuresOneJobIsPending(): void
	{
		$object = new ObjectData('hello', []);
		$this->resolver->method('urlFor')->with('blog', $object->toArray())->willReturn('https://example.com/blog/hello');

		$this->outbox->expects($this->once())->method('add')->with(['https://example.com/blog/hello']);
		$this->jobs->expects($this->once())->method('queueJob')->with(JobData::TYPE_INDEXNOW, IndexNowOutbox::JOB_COLLECTION);

		$this->dispatcher->dispatch('object.created', new ObjectEventPayload('blog', 'hello', $object));
	}

	public function testNoSecondJobIsQueuedWhileOneIsPending(): void
	{
		$repository = $this->createMock(JobRepository::class);
		$repository->method('hasPendingJob')->with(JobData::TYPE_INDEXNOW, IndexNowOutbox::JOB_COLLECTION)->willReturn(true);
		$dispatcher = new EventDispatcher(new NullLogger());
		$listener   = new IndexNowListener($this->resolver, $this->submitter, $this->outbox, $repository, $this->jobs, $this->loggerFactory);
		$dispatcher->listen('object.updated', $listener->onObjectSaved(...));
		$this->resolver->method('urlFor')->willReturn('https://example.com/blog/hello');

		$this->outbox->expects($this->once())->method('add');
		$this->jobs->expects($this->never())->method('queueJob');

		$dispatcher->dispatch('object.updated', new ObjectEventPayload('blog', 'hello', new ObjectData('hello', []), new ObjectData('hello', [])));
	}

	public function testAnUpdateThatMovesAUrlAppendsBothTheOldAndTheNew(): void
	{
		$previous = new ObjectData('hello', []);
		$current  = new ObjectData('hello', []);
		$this->resolver->method('urlFor')->willReturnOnConsecutiveCalls('https://example.com/new', 'https://example.com/old');

		$this->outbox->expects($this->once())->method('add')->with(['https://example.com/new', 'https://example.com/old']);

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('blog', 'hello', $current, $previous));
	}

	public function testAnUnchangedUrlIsAppendedOnce(): void
	{
		$this->resolver->method('urlFor')->willReturn('https://example.com/blog/hello');

		$this->outbox->expects($this->once())->method('add')->with(['https://example.com/blog/hello']);

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('blog', 'hello', new ObjectData('hello', []), new ObjectData('hello', [])));
	}

	public function testADeleteAppendsTheUrlItHadSoEnginesRecrawlAndDropIt(): void
	{
		$previous = new ObjectData('hello', []);
		$this->resolver->method('urlFor')->with('blog', $previous->toArray())->willReturn('https://example.com/blog/hello');

		$this->outbox->expects($this->once())->method('add')->with(['https://example.com/blog/hello']);

		$this->dispatcher->dispatch('object.deleted', new ObjectEventPayload('blog', 'hello', null, $previous));
	}

	public function testNothingIsAppendedWhenTheSitemapWouldNotListIt(): void
	{
		$this->resolver->method('urlFor')->willReturn(null);
		$this->outbox->expects($this->never())->method('add');
		$this->jobs->expects($this->never())->method('queueJob');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('blog', 'draft-post', new ObjectData('draft-post', []), new ObjectData('draft-post', [])));
	}

	public function testNothingIsAppendedWhenIndexNowIsOff(): void
	{
		$submitter = $this->createMock(IndexNowSubmitter::class);
		$submitter->method('isConfigured')->willReturn(false);
		$listener = new IndexNowListener($this->resolver, $submitter, $this->outbox, $this->repository, $this->jobs, $this->loggerFactory);
		$this->resolver->method('urlFor')->willReturn('https://example.com/blog/hello');

		$this->outbox->expects($this->never())->method('add');
		$this->jobs->expects($this->never())->method('queueJob');

		$listener->onObjectSaved((new ObjectEventPayload('blog', 'hello', new ObjectData('hello', [])))->toArray());
	}

	public function testSiteSeoIsNotConsultedWhenNothingResolves(): void
	{
		// The loader memoises per request, so reading it during a save that
		// resolves to nothing — the seo-site record's own save, above all —
		// would pin stale settings. A miss must short-circuit before it.
		$submitter = $this->createMock(IndexNowSubmitter::class);
		$submitter->expects($this->never())->method('isConfigured');
		$listener = new IndexNowListener($this->resolver, $submitter, $this->outbox, $this->repository, $this->jobs, $this->loggerFactory);
		$this->resolver->method('urlFor')->willReturn(null);

		$listener->onObjectSaved((new ObjectEventPayload('seo-site', 'seo-site', new ObjectData('seo-site', [])))->toArray());
	}

	public function testADeleteWithNoPreviousStateAppendsNothing(): void
	{
		$this->outbox->expects($this->never())->method('add');

		$this->dispatcher->dispatch('object.deleted', new ObjectEventPayload('blog', 'hello'));
	}
}
