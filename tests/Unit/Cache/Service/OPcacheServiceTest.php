<?php

declare(strict_types=1);

namespace Tests\Unit\Cache\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\OPcacheService;

final class OPcacheServiceTest extends TestCase
{
	private OPcacheService $opcacheService;

	protected function setUp(): void
	{
		$this->opcacheService = new OPcacheService();
	}

	public function testConstructor(): void
	{
		$service = new OPcacheService();
		$this->assertInstanceOf(OPcacheService::class, $service);
	}

	public function testIsInstalled(): void
	{
		$isInstalled = $this->opcacheService->isInstalled();
		
		// Should return boolean indicating if OPcache functions are available
		$this->assertIsBool($isInstalled);
		
		// Should match actual function availability
		$expectedInstalled = function_exists('opcache_get_status');
		$this->assertEquals($expectedInstalled, $isInstalled);
	}

	public function testIsAvailable(): void
	{
		$isAvailable = $this->opcacheService->isAvailable();
		
		// Should return boolean
		$this->assertIsBool($isAvailable);
		
		// Should be consistent with installation status
		if (!$this->opcacheService->isInstalled()) {
			$this->assertFalse($isAvailable);
		}
	}

	public function testIsActive(): void
	{
		$isActive = $this->opcacheService->isActive();
		
		// Should return boolean
		$this->assertIsBool($isActive);
		
		// Should match isAvailable since they're the same for OPcache
		$this->assertEquals($this->opcacheService->isAvailable(), $isActive);
	}

	public function testGetAlwaysReturnsNull(): void
	{
		// OPcache doesn't support key-value storage
		$result = $this->opcacheService->get('any_key');
		$this->assertNull($result);
		
		$result = $this->opcacheService->get('another_key');
		$this->assertNull($result);
	}

	public function testSetAlwaysReturnsFalse(): void
	{
		// OPcache doesn't support key-value storage
		$result = $this->opcacheService->set('key', 'value');
		$this->assertFalse($result);
		
		$result = $this->opcacheService->set('key', 'value', 3600);
		$this->assertFalse($result);
		
		// Test with different data types
		$result = $this->opcacheService->set('key', ['array' => 'value']);
		$this->assertFalse($result);
	}

	public function testDeleteWithNonExistentFile(): void
	{
		$nonExistentFile = '/non/existent/file/path_' . uniqid() . '.php';
		$result = $this->opcacheService->delete($nonExistentFile);
		
		$this->assertFalse($result);
	}

	public function testDeleteWithExistingFile(): void
	{
		if (!$this->opcacheService->isAvailable()) {
			$this->markTestSkipped('OPcache is not available');
		}

		// Create a temporary PHP file
		$tempFile = tempnam(sys_get_temp_dir(), 'opcache_test_') . '.php';
		file_put_contents($tempFile, '<?php echo "test";');
		
		try {
			// Try to delete/invalidate the file
			$result = $this->opcacheService->delete($tempFile);
			
			// Result should be boolean
			$this->assertIsBool($result);
			
		} finally {
			// Clean up
			if (file_exists($tempFile)) {
				unlink($tempFile);
			}
		}
	}

	public function testClearWhenNotAvailable(): void
	{
		if ($this->opcacheService->isAvailable()) {
			$this->markTestSkipped('OPcache is available, cannot test unavailable scenario');
		}

		$result = $this->opcacheService->clear();
		$this->assertFalse($result);
	}

	public function testClearWhenAvailable(): void
	{
		if (!$this->opcacheService->isAvailable()) {
			$this->markTestSkipped('OPcache is not available');
		}

		$result = $this->opcacheService->clear();
		
		// Should return boolean
		$this->assertIsBool($result);
	}

	public function testDoesNotHaveClearByPatternMethod(): void
	{
		// OPcache doesn't support pattern-based clearing, so method doesn't exist
		$this->assertFalse(method_exists($this->opcacheService, 'clearByPattern'));
	}

	public function testGetStatsWhenNotAvailable(): void
	{
		if ($this->opcacheService->isAvailable()) {
			$this->markTestSkipped('OPcache is available, cannot test unavailable scenario');
		}

		$stats = $this->opcacheService->getStats();
		
		$this->assertIsArray($stats);
		$this->assertArrayHasKey('available', $stats);
		$this->assertFalse($stats['available']);
	}

	public function testGetStatsWhenAvailable(): void
	{
		if (!$this->opcacheService->isAvailable()) {
			$this->markTestSkipped('OPcache is not available');
		}

		$stats = $this->opcacheService->getStats();
		
		$this->assertIsArray($stats);
		$this->assertArrayHasKey('available', $stats);
		$this->assertTrue($stats['available']);
		
		// Should contain OPcache-specific metrics
		$this->assertArrayHasKey('memory_usage', $stats);
		$this->assertArrayHasKey('scripts_cached', $stats);
		$this->assertArrayHasKey('hit_rate', $stats);
	}

	public function testGetName(): void
	{
		$name = $this->opcacheService->getName();
		$this->assertEquals('OPcache', $name);
		$this->assertIsString($name);
	}

	public function testGetRecommendationsWhenNotAvailable(): void
	{
		if ($this->opcacheService->isAvailable()) {
			$this->markTestSkipped('OPcache is available, cannot test unavailable scenario');
		}

		$recommendations = $this->opcacheService->getRecommendations();
		
		$this->assertIsArray($recommendations);
		$this->assertNotEmpty($recommendations);
		$this->assertStringContainsString('not available', $recommendations[0]);
	}

	public function testGetRecommendationsWhenAvailable(): void
	{
		if (!$this->opcacheService->isAvailable()) {
			$this->markTestSkipped('OPcache is not available');
		}

		$recommendations = $this->opcacheService->getRecommendations();
		
		$this->assertIsArray($recommendations);
		$this->assertNotEmpty($recommendations);
		$this->assertStringContainsString('active', $recommendations[0]);
	}

	public function testImplementsCacheInterface(): void
	{
		$this->assertInstanceOf(\TotalCMS\Domain\Cache\Service\CacheInterface::class, $this->opcacheService);
	}

	public function testHasRequiredMethods(): void
	{
		$requiredMethods = [
			'isAvailable',
			'isInstalled', 
			'isActive',
			'get',
			'set',
			'delete',
			'clear',
			'getStats',
			'getName',
			'getRecommendations'
		];

		foreach ($requiredMethods as $method) {
			$this->assertTrue(method_exists($this->opcacheService, $method), 
				"Method {$method} should exist");
		}
	}

	public function testOPcacheSpecificBehavior(): void
	{
		// OPcache has specific behavior different from other cache services
		
		// 1. No key-value storage support
		$this->assertNull($this->opcacheService->get('any_key'));
		$this->assertFalse($this->opcacheService->set('key', 'value'));
		
		// 2. Delete works only with file paths
		$this->assertFalse($this->opcacheService->delete('non_file_key'));
		
		// 3. No pattern support - method doesn't exist
		$this->assertFalse(method_exists($this->opcacheService, 'clearByPattern'));
		
		// 4. isActive should equal isAvailable
		$this->assertEquals($this->opcacheService->isAvailable(), $this->opcacheService->isActive());
	}

	public function testConsistentBehaviorAcrossInstances(): void
	{
		$service1 = new OPcacheService();
		$service2 = new OPcacheService();
		
		// Both instances should behave identically
		$this->assertEquals($service1->isInstalled(), $service2->isInstalled());
		$this->assertEquals($service1->isAvailable(), $service2->isAvailable());
		$this->assertEquals($service1->isActive(), $service2->isActive());
		$this->assertEquals($service1->getName(), $service2->getName());
		
		// Non-functional operations should consistently return same values
		$this->assertEquals($service1->get('key'), $service2->get('key'));
		$this->assertEquals($service1->set('key', 'value'), $service2->set('key', 'value'));
	}

	public function testEdgeCasesForDeleteMethod(): void
	{
		// Test delete with various input types
		$this->assertFalse($this->opcacheService->delete(''));
		$this->assertFalse($this->opcacheService->delete('/'));
		$this->assertFalse($this->opcacheService->delete('relative_path.php'));
		
		// Test with special characters
		$this->assertFalse($this->opcacheService->delete('/path/with spaces/file.php'));
		$this->assertFalse($this->opcacheService->delete('/path/with-special@chars.php'));
	}
}