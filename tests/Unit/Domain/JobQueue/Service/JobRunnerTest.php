<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\Domain\Mailer\Exception\EmailRateLimitException;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Domain\Search\Job\ReindexJob;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// JobRunner is where imports, index rebuilds, exports, data-view updates, bulk
// email and search reindexing all actually execute — usually from cron, with
// nobody watching. The behaviour that matters is the lifecycle around a job
// rather than any single handler: a job that succeeds must be removed, one
// that throws must be recorded as failed rather than silently dropped or left
// in progress forever, and a rate-limited email must go back on the queue
// instead of burning a retry.

function jobRunnerJob(string $type, string $collection = 'blog', string $payload = '{}', int $attempts = 0): JobData
{
	$job              = new JobData();
	$job->id          = 'job-1';
	$job->type        = $type;
	$job->payload     = $payload;
	$job->status      = JobData::STATUS_PENDING;
	$job->collection  = $collection;
	$job->attempts    = $attempts;
	$job->createdAt   = '2026-01-01T00:00:00+00:00';
	$job->updatedAt   = '2026-01-01T00:00:00+00:00';
	$job->lastError   = '';
	$job->scheduledAt = '2026-01-01T00:00:00+00:00';

	return $job;
}

/** @return array<string,mixed> */
function jobRunnerMocks(): array
{
	return [
		'jobRepository'        => test()->createMock(JobRepository::class),
		'objectImporter'       => test()->createMock(ObjectImporter::class),
		'objectExporter'       => test()->createMock(ObjectExporter::class),
		'indexBuilder'         => test()->createMock(IndexBuilder::class),
		'factoryImporter'      => test()->createMock(FactoryImporter::class),
		'collectionRepository' => test()->createMock(CollectionRepository::class),
		'viewBuilder'          => test()->createMock(DataViewBuilder::class),
		'emailService'         => test()->createMock(EmailService::class),
		'bulkMailerRepository' => test()->createMock(BulkMailerRepository::class),
		'objectFetcher'        => test()->createMock(ObjectFetcher::class),
		'searchReindexJob'     => test()->createMock(ReindexJob::class),
	];
}

/**
 * @param array<string,mixed> $m
 * @param array<string,mixed> $smtp rate-limit config; empty disables the check
 */
function jobRunnerFrom(array $m, array $smtp = []): JobRunner
{
	$config        = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->smtp  = $smtp;
	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return new JobRunner(
		$m['jobRepository'],
		$m['objectImporter'],
		$m['objectExporter'],
		$m['indexBuilder'],
		$m['factoryImporter'],
		$m['collectionRepository'],
		$m['viewBuilder'],
		$m['emailService'],
		$m['bulkMailerRepository'],
		$m['objectFetcher'],
		$m['searchReindexJob'],
		$config,
		$loggerFactory,
	);
}

describe('JobRunner::processNextJob lifecycle', function (): void {
	it('deletes a job that ran cleanly', function (): void {
		$job = jobRunnerJob(JobData::TYPE_REBUILD);
		$m   = jobRunnerMocks();
		$m['indexBuilder']->method('buildIndex')->willReturn(new IndexData([]));
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		$m['jobRepository']->expects(test()->once())->method('delete')->with($job);
		$m['jobRepository']->expects(test()->never())->method('markFailed');

		jobRunnerFrom($m)->processNextJob();
	});

	it('records a throwing job as failed and does not delete it', function (): void {
		// The job has to survive so it can be retried; deleting it here would
		// lose the work silently.
		$job = jobRunnerJob(JobData::TYPE_REBUILD);
		$m   = jobRunnerMocks();
		$m['indexBuilder']->method('buildIndex')->willThrowException(new RuntimeException('index blew up'));
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		$m['jobRepository']->expects(test()->once())->method('markFailed')->with($job, 'index blew up');
		$m['jobRepository']->expects(test()->never())->method('delete');

		jobRunnerFrom($m)->processNextJob();
	});

	it('swallows the handler exception rather than aborting the queue', function (): void {
		// processPendingJobs() loops over this; letting the throwable escape
		// would stop every remaining job behind one bad one.
		$job = jobRunnerJob(JobData::TYPE_REBUILD);
		$m   = jobRunnerMocks();
		$m['indexBuilder']->method('buildIndex')->willThrowException(new RuntimeException('boom'));
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);

		jobRunnerFrom($m)->processNextJob();

		expect(true)->toBeTrue();
	});

	it('puts a rate-limited email job back to pending instead of failing it', function (): void {
		// A rate limit is not the job's fault — it must not consume a retry.
		$job = jobRunnerJob(JobData::TYPE_EMAIL);
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		// One send allowed per hour, one already sent.
		$m['bulkMailerRepository']->method('countSentSince')->willReturn(1);
		$m['jobRepository']->expects(test()->once())->method('resetJobStatus')->with($job);
		$m['jobRepository']->expects(test()->never())->method('markFailed');
		$m['jobRepository']->expects(test()->never())->method('delete');

		expect(fn () => jobRunnerFrom($m, ['maxPerHour' => 1])->processNextJob())
			->toThrow(EmailRateLimitException::class);
	});
});

describe('JobRunner job dispatch', function (): void {
	it('routes an import job to the object importer', function (): void {
		$job = jobRunnerJob(JobData::TYPE_IMPORT, payload: '{"id":"post-1"}');
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		$m['objectImporter']->expects(test()->once())->method('importObject')
			->with('blog', ['id' => 'post-1']);

		jobRunnerFrom($m)->processNextJob();
	});

	it('routes a rebuild job to the index builder', function (): void {
		$job = jobRunnerJob(JobData::TYPE_REBUILD);
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		$m['indexBuilder']->expects(test()->once())->method('buildIndex')
			->with('blog')->willReturn(new IndexData([]));

		jobRunnerFrom($m)->processNextJob();
	});

	it('routes a search reindex job to the reindex job', function (): void {
		$job = jobRunnerJob(JobData::TYPE_SEARCH_REINDEX);
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		$m['searchReindexJob']->expects(test()->once())->method('run')->with($job);

		jobRunnerFrom($m)->processNextJob();
	});

	it('discards a job of unknown type instead of retrying it forever', function (): void {
		// Documents current behaviour: an unrecognised type logs an error, but
		// processJob() does not throw, so processNextJob() deletes the job. That
		// is deliberate — an unknown type will never become known on a retry.
		$job = jobRunnerJob('not-a-real-type');
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		$m['jobRepository']->expects(test()->once())->method('delete')->with($job);
		$m['objectImporter']->expects(test()->never())->method('importObject');

		jobRunnerFrom($m)->processNextJob();
	});

	it('skips an import whose payload is not valid JSON, without failing the job', function (): void {
		$job = jobRunnerJob(JobData::TYPE_IMPORT, payload: '{not json');
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchNextJob')->willReturn($job);
		$m['objectImporter']->expects(test()->never())->method('importObject');
		$m['jobRepository']->expects(test()->once())->method('delete');

		jobRunnerFrom($m)->processNextJob();
	});
});

describe('JobRunner::retryFailedJobs', function (): void {
	it('resets a failed job that still has retries left', function (): void {
		$job = jobRunnerJob(JobData::TYPE_REBUILD, attempts: 1);
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchFailedJobs')->willReturn([$job]);
		$m['jobRepository']->expects(test()->once())->method('resetJobStatus')->with($job);

		jobRunnerFrom($m)->retryFailedJobs();
	});

	it('leaves a job alone once it has hit the retry ceiling', function (): void {
		// Three attempts is the cap; retrying past it would loop forever.
		$job = jobRunnerJob(JobData::TYPE_REBUILD, attempts: 3);
		$m   = jobRunnerMocks();
		$m['jobRepository']->method('fetchFailedJobs')->willReturn([$job]);
		$m['jobRepository']->expects(test()->never())->method('resetJobStatus');

		jobRunnerFrom($m)->retryFailedJobs();
	});

	it('retries the eligible jobs and skips the exhausted ones in one pass', function (): void {
		$ok       = jobRunnerJob(JobData::TYPE_REBUILD, attempts: 2);
		$ok->id   = 'retry-me';
		$done     = jobRunnerJob(JobData::TYPE_REBUILD, attempts: 5);
		$done->id = 'give-up';

		$m = jobRunnerMocks();
		$m['jobRepository']->method('fetchFailedJobs')->willReturn([$ok, $done]);
		$m['jobRepository']->expects(test()->once())->method('resetJobStatus')->with($ok);

		jobRunnerFrom($m)->retryFailedJobs();
	});

	it('does nothing when there is nothing failed', function (): void {
		$m = jobRunnerMocks();
		$m['jobRepository']->method('fetchFailedJobs')->willReturn([]);
		$m['jobRepository']->expects(test()->never())->method('resetJobStatus');

		jobRunnerFrom($m)->retryFailedJobs();
	});
});

describe('JobRunner queue helpers', function (): void {
	it('reports whether work is waiting', function (): void {
		$m = jobRunnerMocks();
		$m['jobRepository']->method('hasPendingJobs')->willReturn(true);

		expect(jobRunnerFrom($m)->hasPendingJobs())->toBeTrue();
	});
});
