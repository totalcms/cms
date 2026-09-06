<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ApiKey;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use Psr\Log\NullLogger;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

final class ApiKeyRepositoryTest extends TestCase
{
	private ApiKeyRepository $repository;

	protected function setUp(): void
	{
		// Clean up any existing test data before starting
		// The sidecar lock is cleared alongside the data file so the .system
		// directory can still be removed when it is otherwise empty.
		foreach (['/.system/apikeys.json', '/.system/apikeys.json.lock'] as $relative) {
			$testFile = sys_get_temp_dir() . $relative;
			if (file_exists($testFile)) {
				unlink($testFile);
			}
		}

		$systemDir = sys_get_temp_dir() . '/.system';
		if (is_dir($systemDir) && count(scandir($systemDir)) === 2) {
			rmdir($systemDir);
		}

		$filesystem = new StorageFilesystemAdapter(
			new Filesystem(
				new LocalFilesystemAdapter(sys_get_temp_dir())
			)
		);
		$this->repository = new ApiKeyRepository($filesystem, new AtomicJsonStore($filesystem, sys_get_temp_dir(), new NullLogger()));
	}

	protected function tearDown(): void
	{
		// Clean up test file
		// The sidecar lock is cleared alongside the data file so the .system
		// directory can still be removed when it is otherwise empty.
		foreach (['/.system/apikeys.json', '/.system/apikeys.json.lock'] as $relative) {
			$testFile = sys_get_temp_dir() . $relative;
			if (file_exists($testFile)) {
				unlink($testFile);
			}
		}

		// Clean up .system directory if empty
		$systemDir = sys_get_temp_dir() . '/.system';
		if (is_dir($systemDir) && count(scandir($systemDir)) === 2) {
			rmdir($systemDir);
		}
	}

	public function testGetAllReturnsEmptyArrayWhenFileDoesNotExist(): void
	{
		$keys = $this->repository->getAll();

		$this->assertIsArray($keys);
		$this->assertEmpty($keys);
	}

	public function testSaveCreatesNewApiKey(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id-123',
			'name'    => 'Test Key',
			'key'     => 'tcms_test123',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		]);

		$this->repository->save($apiKey);

		$keys = $this->repository->getAll();
		$this->assertCount(1, $keys);
		$this->assertEquals('test-id-123', $keys[0]->id);
		$this->assertEquals('Test Key', $keys[0]->name);
	}

	public function testSaveMultipleKeys(): void
	{
		$apiKey1 = new ApiKeyData([
			'id'      => 'id-1',
			'name'    => 'Key 1',
			'key'     => 'tcms_key1',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		$apiKey2 = new ApiKeyData([
			'id'      => 'id-2',
			'name'    => 'Key 2',
			'key'     => 'tcms_key2',
			'created' => '2025-01-15T11:00:00Z',
			'scopes'  => ['methods' => ['POST'], 'paths' => ['/api/collections/*']],
		]);

		$this->repository->save($apiKey1);
		$this->repository->save($apiKey2);

		$keys = $this->repository->getAll();
		$this->assertCount(2, $keys);
	}

	public function testFindByIdReturnsCorrectKey(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'find-me',
			'name'    => 'Findable Key',
			'key'     => 'tcms_findme',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		$this->repository->save($apiKey);

		$found = $this->repository->findById('find-me');

		$this->assertInstanceOf(ApiKeyData::class, $found);
		$this->assertEquals('find-me', $found->id);
		$this->assertEquals('Findable Key', $found->name);
	}

	public function testFindByIdReturnsNullWhenNotFound(): void
	{
		$found = $this->repository->findById('non-existent');

		$this->assertNull($found);
	}

	public function testFindByKeyReturnsCorrectKey(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_findbykey',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		$this->repository->save($apiKey);

		$found = $this->repository->findByKey('tcms_findbykey');

		$this->assertInstanceOf(ApiKeyData::class, $found);
		$this->assertEquals('tcms_findbykey', $found->key);
	}

	public function testFindByKeyReturnsNullWhenNotFound(): void
	{
		$found = $this->repository->findByKey('non-existent-key');

		$this->assertNull($found);
	}

	public function testUpdateModifiesExistingKey(): void
	{
		// Create initial key
		$apiKey = new ApiKeyData([
			'id'       => 'update-me',
			'name'     => 'Original Name',
			'key'      => 'tcms_update',
			'created'  => '2025-01-15T10:30:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		$this->repository->save($apiKey);

		// Update with new lastUsed
		$updatedKey = new ApiKeyData([
			'id'       => 'update-me',
			'name'     => 'Original Name',
			'key'      => 'tcms_update',
			'created'  => '2025-01-15T10:30:00Z',
			'lastUsed' => '2025-01-16T14:20:00Z',
			'scopes'   => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		$this->repository->update($updatedKey);

		$found = $this->repository->findById('update-me');
		$this->assertEquals('2025-01-16T14:20:00Z', $found->lastUsed);
	}

	public function testDeleteRemovesKey(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'delete-me',
			'name'    => 'To Delete',
			'key'     => 'tcms_delete',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		$this->repository->save($apiKey);

		$this->assertCount(1, $this->repository->getAll());

		$deleted = $this->repository->delete('delete-me');

		$this->assertTrue($deleted);
		$this->assertCount(0, $this->repository->getAll());
	}

	public function testDeleteReturnsFalseWhenKeyNotFound(): void
	{
		$deleted = $this->repository->delete('non-existent');

		$this->assertFalse($deleted);
	}

	public function testDeleteMaintainsArrayIndexing(): void
	{
		// Create 3 keys
		for ($i = 1; $i <= 3; $i++) {
			$apiKey = new ApiKeyData([
				'id'      => "id-$i",
				'name'    => "Key $i",
				'key'     => "tcms_key$i",
				'created' => '2025-01-15T10:30:00Z',
				'scopes'  => ['methods' => ['GET'], 'paths' => ['*']],
			]);
			$this->repository->save($apiKey);
		}

		// Delete middle one
		$this->repository->delete('id-2');

		$keys = $this->repository->getAll();
		$this->assertCount(2, $keys);

		// Array should be re-indexed
		$this->assertEquals('id-1', $keys[0]->id);
		$this->assertEquals('id-3', $keys[1]->id);
	}

	public function testUpdateLastUsedUpdatesTimestamp(): void
	{
		$apiKey = new ApiKeyData([
			'id'       => 'test-id',
			'name'     => 'Test',
			'key'      => 'tcms_lastused',
			'created'  => '2025-01-15T10:30:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		$this->repository->save($apiKey);

		// Wait a moment to ensure timestamp is different
		sleep(1);

		$this->repository->updateLastUsed('tcms_lastused');

		$found = $this->repository->findById('test-id');
		$this->assertNotNull($found->lastUsed);
		$this->assertStringContainsString(date('Y'), $found->lastUsed);
	}

	public function testUpdateLastUsedDoesNothingWhenKeyNotFound(): void
	{
		// Should not throw exception
		$this->repository->updateLastUsed('non-existent-key');

		$this->assertTrue(true); // No exception = pass
	}

	/**
	 * The production data-loss bug: a torn read used to decode to nothing, and
	 * the very next write persisted that nothing over every real key. Refusing
	 * to read a malformed file is what makes overwriting it impossible.
	 */
	public function testMalformedFileThrowsInsteadOfReadingAsEmpty(): void
	{
		$this->writeRawKeyFile('{"apikeys": [{"id": "half-writ');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/not valid JSON/');

		$this->repository->getAll();
	}

	public function testMalformedFileIsNeverOverwritten(): void
	{
		$corrupt = '{"apikeys": [{"id": "half-writ';
		$this->writeRawKeyFile($corrupt);

		$apiKey = new ApiKeyData([
			'id'      => 'new-key',
			'name'    => 'New Key',
			'key'     => 'tcms_new',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['GET'], 'paths' => ['*']],
		]);

		try {
			$this->repository->save($apiKey);
			$this->fail('Saving over a malformed key file should have thrown.');
		} catch (\RuntimeException) {
			// expected
		}

		$this->assertSame(
			$corrupt,
			file_get_contents(sys_get_temp_dir() . '/.system/apikeys.json'),
			'The malformed file must be left exactly as found, not replaced.'
		);
	}

	public function testEmptyFileIsTreatedAsNoKeys(): void
	{
		// Distinct from malformed: a zero-byte file is what a never-used
		// install looks like, so it must stay usable rather than throw.
		$this->writeRawKeyFile('');

		$this->assertSame([], $this->repository->getAll());
	}

	public function testUpdateLastUsedIsDebouncedWithinTheWindow(): void
	{
		$this->repository->save(new ApiKeyData([
			'id'       => 'debounce-id',
			'name'     => 'Debounced',
			'key'      => 'tcms_debounce',
			'created'  => '2025-01-15T10:30:00Z',
			'lastUsed' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'   => ['methods' => ['GET'], 'paths' => ['*']],
		]));

		$path   = sys_get_temp_dir() . '/.system/apikeys.json';
		$before = filemtime($path);

		// A just-stamped key is inside the debounce window, so authenticating
		// again must not rewrite the file at all.
		$this->repository->updateLastUsed('tcms_debounce');
		clearstatcache(true, $path);

		$this->assertSame($before, filemtime($path));
	}

	private function writeRawKeyFile(string $contents): void
	{
		$dir = sys_get_temp_dir() . '/.system';

		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($dir . '/apikeys.json', $contents);
	}
}
