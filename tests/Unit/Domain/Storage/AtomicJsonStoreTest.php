<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Backed by a real temp filesystem: the temp+rename commit and the sidecar
 * flock are the invariants under test, and neither can be mocked past.
 */
final class AtomicJsonStoreTest extends TestCase
{
	private string $root;
	/** @var list<array{level:string,message:string}> */
	private array $logs = [];
	private AtomicJsonStore $store;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-ajs-' . uniqid();
		mkdir($this->root . '/.system', 0755, true);
		$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->root)));
		$logs    = &$this->logs;
		$logger  = new class ($logs) extends AbstractLogger {
			/** @param list<array{level:string,message:string}> $logs */
			public function __construct(private array &$logs)
			{
			}

			public function log($level, \Stringable|string $message, array $context = []): void
			{
				$this->logs[] = ['level' => (string)$level, 'message' => (string)$message];
			}
		};
		$this->store = new AtomicJsonStore($storage, $this->root, $logger);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->root . '/.system/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($this->root . '/.system');
		@rmdir($this->root);
	}

	private function raw(string $path, string $content): void
	{
		file_put_contents($this->root . '/' . $path, $content);
	}

	public function testMissingAndBlankFilesLoadAsEmpty(): void
	{
		expect($this->store->load('.system/x.json', CorruptPolicy::Throw))->toBe([]);
		$this->raw('.system/x.json', "  \n");
		expect($this->store->load('.system/x.json', CorruptPolicy::Throw))->toBe([]);
		expect($this->store->isCorrupt('.system/x.json'))->toBeFalse();
	}

	public function testSaveWritesAtomicallyAndLeavesNoTempFile(): void
	{
		expect($this->store->save('.system/x.json', ['a' => 1]))->toBeTrue();

		expect(json_decode((string)file_get_contents($this->root . '/.system/x.json'), true))->toBe(['a' => 1]);
		expect(glob($this->root . '/.system/x.json.tmp.*'))->toBe([]);
	}

	public function testThrowPolicyThrowsAndNeverWrites(): void
	{
		$this->raw('.system/x.json', '{"half":');

		try {
			$this->store->load('.system/x.json', CorruptPolicy::Throw);
			$this->fail('expected exception');
		} catch (\RuntimeException $e) {
			expect($e->getMessage())->toMatch('/not valid JSON/');
		}
		expect(file_get_contents($this->root . '/.system/x.json'))->toBe('{"half":');
	}

	public function testRefuseWritesLoadsEmptyLogsAndBlocksSave(): void
	{
		$this->raw('.system/x.json', '{"half":');

		expect($this->store->load('.system/x.json', CorruptPolicy::RefuseWrites))->toBe([]);
		expect($this->store->isCorrupt('.system/x.json'))->toBeTrue();
		expect($this->logs[0]['level'])->toBe('error');

		expect($this->store->save('.system/x.json', ['a' => 1]))->toBeFalse();
		expect(file_get_contents($this->root . '/.system/x.json'))->toBe('{"half":');
		expect(end($this->logs)['message'])->toContain('Refusing');
	}

	public function testRefuseWritesClearsOnceTheFileReadsCleanly(): void
	{
		$this->raw('.system/x.json', '{"half":');
		$this->store->load('.system/x.json', CorruptPolicy::RefuseWrites);
		$this->raw('.system/x.json', '{"ok":true}');

		expect($this->store->load('.system/x.json', CorruptPolicy::RefuseWrites))->toBe(['ok' => true]);
		expect($this->store->isCorrupt('.system/x.json'))->toBeFalse();
		expect($this->store->save('.system/x.json', ['a' => 1]))->toBeTrue();
	}

	public function testTreatAsEmptyLoadsEmptyWarnsAndAllowsSave(): void
	{
		$this->raw('.system/x.json', 'nope');

		expect($this->store->load('.system/x.json', CorruptPolicy::TreatAsEmpty))->toBe([]);
		expect($this->logs[0]['level'])->toBe('warning');
		expect($this->store->save('.system/x.json', ['a' => 1]))->toBeTrue();
	}

	public function testNonObjectJsonIsCorrupt(): void
	{
		$this->raw('.system/x.json', '"just a string"');

		expect($this->store->load('.system/x.json', CorruptPolicy::RefuseWrites))->toBe([]);
		expect($this->store->isCorrupt('.system/x.json'))->toBeTrue();
	}

	public function testMutateAppliesTheCallbackAndCommits(): void
	{
		$this->store->save('.system/x.json', ['n' => 1]);

		$ok = $this->store->mutate('.system/x.json', fn (array $d): array => ['n' => $d['n'] + 1], CorruptPolicy::Throw);

		expect($ok)->toBeTrue();
		expect($this->store->load('.system/x.json', CorruptPolicy::Throw))->toBe(['n' => 2]);
	}

	public function testMutateWithLockCreatesAndReleasesTheSidecar(): void
	{
		$this->store->mutate('.system/x.json', fn (array $d): array => ['n' => 1], CorruptPolicy::Throw, lock: true);

		$lockPath = $this->root . '/.system/x.json.lock';
		expect(file_exists($lockPath))->toBeTrue();

		// A second exclusive lock succeeds only if the first was released.
		$h = fopen($lockPath, 'c');
		expect($h)->not->toBeFalse();
		expect(flock($h, LOCK_EX | LOCK_NB))->toBeTrue();
		flock($h, LOCK_UN);
		fclose($h);
	}

	public function testMutateWithRefuseWritesOnCorruptReturnsFalseWithoutCallingTheCallback(): void
	{
		$this->raw('.system/x.json', '{');
		$called = false;

		$ok = $this->store->mutate('.system/x.json', function (array $d) use (&$called): array {
			$called = true;

			return $d;
		}, CorruptPolicy::RefuseWrites);

		expect($ok)->toBeFalse();
		expect($called)->toBeFalse();
		expect(file_get_contents($this->root . '/.system/x.json'))->toBe('{');
	}

	public function testSecretSetsModeOnTheCommittedFile(): void
	{
		$this->store->save('.system/s.json', ['k' => 'v'], secret: true);

		expect(fileperms($this->root . '/.system/s.json') & 0777)->toBe(0600);
	}
}
