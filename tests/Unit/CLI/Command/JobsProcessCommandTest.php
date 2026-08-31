<?php

declare(strict_types=1);

use DI\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\JobsProcessCommand;
use TotalCMS\Domain\JobQueue\Service\JobQueueDrainer;
use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\TotalCMS;

use function Tests\Unit\CLI\Command\createTestConfig;

require_once __DIR__ . '/helpers.php';

// `tcms jobs:process` is the cron entry point for the whole queue. Nobody reads
// its output on a good day, so what matters is that it holds its lock, reports
// honestly, and hands the deadline through to the drainer — a silent no-op here
// means the queue simply stops being processed.

/**
 * Drive the command over a real JobQueueDrainer (it is final, so it cannot be
 * mocked) wrapped around a scripted JobRunner. That exercises the actual drain
 * loop rather than a stand-in for it.
 *
 * @param array<string,mixed>                                            $queue     initial queue status
 * @param array<string,mixed>                                            $overrides jobRunner returns
 * @param array<int,array{type:string,collection:string,success:bool}>   $jobs      what the queue yields, in order
 */
function jobsCommandTester(
	string $systemParent,
	array $queue = ['Total' => 0],
	array $overrides = [],
	array $jobs = [],
): CommandTester {
	$remaining = $jobs;

	$runner = test()->createMock(JobRunner::class);
	$runner->method('resetStuckJobs')->willReturn($overrides['stuck'] ?? 0);
	$runner->method('maintenance')->willReturn($overrides['maintenance'] ?? ['pruned' => 0, 'vacuumed' => false]);
	$runner->method('getQueueStatus')->willReturn($queue);
	$runner->method('getQueueByType')->willReturn($overrides['byType'] ?? []);
	$runner->method('retryFailedJobsWithStats')->willReturn(
		$overrides['retry'] ?? ['total_failed' => 0, 'retried' => 0, 'skipped' => 0]
	);
	$runner->method('enableImportOptimization')->willReturn([]);
	$runner->method('hasPendingJobs')->willReturnCallback(
		function () use (&$remaining): bool {
			return $remaining !== [];
		}
	);
	$runner->method('processNextJobWithDetails')->willReturnCallback(
		function () use (&$remaining): ?array {
			$next = array_shift($remaining);
			if ($next === null) {
				return null;
			}

			return [
				'job'     => ['type' => $next['type'], 'collection' => $next['collection']],
				'success' => $next['success'],
			];
		}
	);

	$container = test()->createMock(Container::class);
	$container->method('get')->willReturn(new JobQueueDrainer($runner));

	$totalcms         = test()->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $systemParent]);
	$totalcms->method('jobRunner')->willReturn($runner);
	$totalcms->method('container')->willReturn($container);

	return new CommandTester(new JobsProcessCommand($totalcms));
}

beforeEach(function (): void {
	// Each test gets its own datadir because the command takes an exclusive
	// flock on {datadir}/.system/.processJobs.lock and only releases it at
	// shutdown — a shared path would make every test after the first report
	// "already running".
	$this->datadir = sys_get_temp_dir() . '/tcms-jobs-test-' . uniqid('', true);
	mkdir($this->datadir . '/.system', 0700, true);
});

afterEach(function (): void {
	// scandir, not glob('/*'): the lock is .processJobs.lock and glob skips
	// dotfiles, which left the directory non-empty and rmdir() warning.
	$system = $this->datadir . '/.system';
	foreach (array_diff(scandir($system) ?: [], ['.', '..']) as $entry) {
		unlink($system . '/' . $entry);
	}
	rmdir($system);
	rmdir($this->datadir);
});

describe('jobs:process with an empty queue', function (): void {
	it('says so and exits successfully', function (): void {
		$tester = jobsCommandTester($this->datadir);

		expect($tester->execute([]))->toBe(Command::SUCCESS);
		expect($tester->getDisplay())->toContain('No jobs in queue.');
	});

	it('still reports a machine-readable result in json mode', function (): void {
		$tester = jobsCommandTester($this->datadir);

		expect($tester->execute(['--json' => true]))->toBe(Command::SUCCESS);

		$json = json_decode($tester->getDisplay(), true);
		expect($json['processed'])->toBe(0);
		expect($json['succeeded'])->toBe(0);
		expect($json['failed'])->toBe(0);
		expect($json)->toHaveKey('maintenance');
	});

	it('runs maintenance before the empty-queue exit, not after', function (): void {
		// A queue holding nothing but old failures is exactly the case that
		// needs pruning, and it is also the case that returns early.
		$tester = jobsCommandTester($this->datadir, ['Total' => 0], [
			'maintenance' => ['pruned' => 4, 'vacuumed' => false],
		]);

		$tester->execute([]);

		expect($tester->getDisplay())->toContain('Pruned 4 failed job(s)');
	});
});

describe('jobs:process locking', function (): void {
	it('refuses to start when another processor already holds the lock', function (): void {
		// Two cron runs overlapping would otherwise process the same jobs twice.
		$lock   = $this->datadir . '/.system/.processJobs.lock';
		$handle = fopen($lock, 'c');
		flock($handle, LOCK_EX | LOCK_NB);

		$tester = jobsCommandTester($this->datadir);

		expect($tester->execute([]))->toBe(Command::FAILURE);
		expect($tester->getDisplay())->toContain('already running');

		flock($handle, LOCK_UN);
		fclose($handle);
	});
});

describe('jobs:process reporting', function (): void {
	it('summarises what the drainer actually did', function (): void {
		$tester = jobsCommandTester(
			$this->datadir,
			['Total' => 3, 'Pending' => 3],
			jobs: [
				['type' => 'import', 'collection' => 'blog', 'success' => true],
				['type' => 'import', 'collection' => 'blog', 'success' => true],
				['type' => 'import', 'collection' => 'blog', 'success' => false],
			]
		);

		expect($tester->execute([]))->toBe(Command::SUCCESS);
		$display = $tester->getDisplay();

		expect($display)->toContain('Processing Summary');
		expect($display)->toContain('Total Processed');
		expect($display)->toContain('Succeeded');
		expect($display)->toContain('Failed');
	});

	it('reports recovered stuck jobs so a previous crash is visible', function (): void {
		$tester = jobsCommandTester(
			$this->datadir,
			['Total' => 1],
			['stuck' => 2],
			jobs: [['type' => 'import', 'collection' => 'blog', 'success' => true]]
		);

		$tester->execute([]);

		expect($tester->getDisplay())->toContain('Recovered 2 stuck job(s)');
	});

	it('reports the retry pass', function (): void {
		$tester = jobsCommandTester(
			$this->datadir,
			['Total' => 1],
			['retry' => ['total_failed' => 5, 'retried' => 3, 'skipped' => 2]],
			jobs: [['type' => 'import', 'collection' => 'blog', 'success' => true]]
		);

		$tester->execute([]);
		$display = $tester->getDisplay();

		expect($display)->toContain('Failed jobs found: 5');
		expect($display)->toContain('Retried:');
		expect($display)->toContain('Skipped (max attempts): 2');
	});

	it('emits the full breakdown in json mode', function (): void {
		$tester = jobsCommandTester(
			$this->datadir,
			['Total' => 4],
			jobs: [
				['type' => 'import', 'collection' => 'blog', 'success' => true],
				['type' => 'import', 'collection' => 'blog', 'success' => true],
				['type' => 'import', 'collection' => 'blog', 'success' => true],
				['type' => 'export', 'collection' => 'blog', 'success' => true],
			]
		);

		expect($tester->execute(['--json' => true]))->toBe(Command::SUCCESS);

		$json = json_decode($tester->getDisplay(), true);
		expect($json['processed'])->toBe(4);
		expect($json['succeeded'])->toBe(4);
		expect($json['by_type'])->toBe(['export' => 1, 'import' => 3]);   // ksorted
		expect($json['by_collection'])->toBe(['blog' => 4]);
		expect($json['deadline_hit'])->toBeFalse();
	});
});

describe('jobs:process deadline', function (): void {
	// The drainer checks its budget BEFORE pulling each job, so --max-seconds=0
	// trips on the first check with the queue still full. That exercises the
	// real deadline path without making a test wait.

	it('stops before starting any job and says the rest stay queued', function (): void {
		// Operators need to know the run was cut short rather than the queue
		// having been empty.
		$tester = jobsCommandTester($this->datadir, ['Total' => 9], jobs: [
			['type' => 'import', 'collection' => 'blog', 'success' => true],
			['type' => 'import', 'collection' => 'blog', 'success' => true],
		]);

		expect($tester->execute(['--max-seconds' => '0']))->toBe(Command::SUCCESS);
		expect($tester->getDisplay())->toContain('picked up on the next run');
	});

	it('flags the deadline stop in json mode and processes nothing', function (): void {
		$tester = jobsCommandTester($this->datadir, ['Total' => 9], jobs: [
			['type' => 'import', 'collection' => 'blog', 'success' => true],
		]);

		$tester->execute(['--max-seconds' => '0', '--json' => true]);

		$json = json_decode($tester->getDisplay(), true);
		expect($json['deadline_hit'])->toBeTrue();
		expect($json['processed'])->toBe(0);
	});

	it('drains the whole queue when no deadline is given', function (): void {
		$tester = jobsCommandTester($this->datadir, ['Total' => 3], jobs: [
			['type' => 'import', 'collection' => 'blog', 'success' => true],
			['type' => 'export', 'collection' => 'pages', 'success' => true],
			['type' => 'import', 'collection' => 'blog', 'success' => false],
		]);

		$tester->execute(['--json' => true]);

		$json = json_decode($tester->getDisplay(), true);
		expect($json['processed'])->toBe(3);
		expect($json['succeeded'])->toBe(2);
		expect($json['failed'])->toBe(1);
		expect($json['deadline_hit'])->toBeFalse();
	});
});
