<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ApiKey;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;

final class ApiKeyPermissionCheckerTest extends TestCase
{
	private ApiKeyPermissionChecker $checker;

	protected function setUp(): void
	{
		$this->checker = new ApiKeyPermissionChecker();
	}

	public function testAllowsMethodReturnsTrueForAllowedMethod(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET', 'POST'],
				'paths'   => ['*'],
			],
		]);

		$this->assertTrue($this->checker->allowsMethod($apiKey, 'GET'));
		$this->assertTrue($this->checker->allowsMethod($apiKey, 'POST'));
	}

	public function testAllowsMethodReturnsFalseForDisallowedMethod(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		]);

		$this->assertFalse($this->checker->allowsMethod($apiKey, 'POST'));
		$this->assertFalse($this->checker->allowsMethod($apiKey, 'DELETE'));
	}

	public function testAllowsMethodIsCaseInsensitive(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		]);

		$this->assertTrue($this->checker->allowsMethod($apiKey, 'get'));
		$this->assertTrue($this->checker->allowsMethod($apiKey, 'Get'));
		$this->assertTrue($this->checker->allowsMethod($apiKey, 'GET'));
	}

	public function testAllowsPathWithWildcard(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/collections/blog'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/users/123'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/anything/goes'));
	}

	public function testAllowsPathWithSpecificPaths(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/api/collections/blog', '/api/collections/news'],
			],
		]);

		// str_starts_with means these all match
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/collections/blog'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/collections/blog/123'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/collections/news'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/collections/news/456'));
		// These don't start with allowed paths
		$this->assertFalse($this->checker->allowsPath($apiKey, '/api/collections/events/789'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/api/users/123'));
	}

	public function testAllowsPathWithChildPaths(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/text'],
			],
		]);

		// Middleware strips base path, so we only test the route portion
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/text'));
		$this->assertTrue($this->checker->allowsPath($apiKey, 'collections/text')); // Works with or without leading slash

		// Should match child paths
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/text/123'));
		$this->assertTrue($this->checker->allowsPath($apiKey, 'collections/text/456'));

		// Should not match different paths
		$this->assertFalse($this->checker->allowsPath($apiKey, '/collections/blog'));
		$this->assertFalse($this->checker->allowsPath($apiKey, 'collections/blog/123'));
	}

	public function testAllowsPathIsCaseInsensitive(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/Collections/Text'],
			],
		]);

		// Case insensitive matching
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/text'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/COLLECTIONS/TEXT'));
		$this->assertTrue($this->checker->allowsPath($apiKey, 'Collections/Text'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/text/123')); // Child paths also case insensitive
	}

	public function testAllowsPathExactMatch(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/text'],
			],
		]);

		// Exact match
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/text'));
	}

	public function testAllowsCombinesMethodAndPathChecks(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET', 'POST'],
				'paths'   => ['/collections/blog'],
			],
		]);

		// Both method and path allowed
		$this->assertTrue($this->checker->allows($apiKey, 'GET', '/collections/blog'));
		$this->assertTrue($this->checker->allows($apiKey, 'POST', '/collections/blog'));

		// Method not allowed
		$this->assertFalse($this->checker->allows($apiKey, 'DELETE', '/collections/blog'));

		// Path not allowed
		$this->assertFalse($this->checker->allows($apiKey, 'GET', '/collections/news'));

		// Neither allowed
		$this->assertFalse($this->checker->allows($apiKey, 'DELETE', '/collections/news'));
	}
}
