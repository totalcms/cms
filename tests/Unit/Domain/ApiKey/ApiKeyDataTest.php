<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ApiKey;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;

final class ApiKeyDataTest extends TestCase
{
	public function testConstructorSetsAllProperties(): void
	{
		$data = [
			'id'       => 'test-id-123',
			'name'     => 'Test API Key',
			'key'      => 'tcms_abc123def456',
			'created'  => '2025-01-15T10:30:00Z',
			'lastUsed' => '2025-01-16T14:20:00Z',
			'scopes'   => [
				'methods' => ['GET', 'POST'],
				'paths'   => ['/api/collections'],
			],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertEquals('test-id-123', $apiKey->id);
		$this->assertEquals('Test API Key', $apiKey->name);
		$this->assertEquals('tcms_abc123def456', $apiKey->key);
		$this->assertEquals('2025-01-15T10:30:00Z', $apiKey->created);
		$this->assertEquals('2025-01-16T14:20:00Z', $apiKey->lastUsed);
		$this->assertEquals(['GET', 'POST'], $apiKey->scopes['methods']);
		$this->assertEquals(['/api/collections'], $apiKey->scopes['paths']);
	}

	public function testConstructorHandlesNullLastUsed(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['GET'], 'paths' => ['*']],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertNull($apiKey->lastUsed);
	}

	public function testConstructorHandlesMissingScopes(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
		];

		$apiKey = new ApiKeyData($data);

		$this->assertEquals(['methods' => [], 'paths' => []], $apiKey->scopes);
	}

	public function testGetMaskedKeyShowsOnlyPrefix(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_abc123def456ghi789jkl012',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => [], 'paths' => []],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertEquals('tcms_abc12...', $apiKey->getMaskedKey());
	}

	public function testGetMaskedKeyHandlesShortKeys(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'short',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => [], 'paths' => []],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertEquals('short', $apiKey->getMaskedKey());
	}

	public function testAllowsMethodReturnsTrueForAllowedMethod(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET', 'POST'],
				'paths'   => ['*'],
			],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertTrue($apiKey->allowsMethod('GET'));
		$this->assertTrue($apiKey->allowsMethod('POST'));
	}

	public function testAllowsMethodReturnsFalseForDisallowedMethod(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertFalse($apiKey->allowsMethod('POST'));
		$this->assertFalse($apiKey->allowsMethod('DELETE'));
	}

	public function testAllowsMethodIsCaseInsensitive(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertTrue($apiKey->allowsMethod('get'));
		$this->assertTrue($apiKey->allowsMethod('Get'));
		$this->assertTrue($apiKey->allowsMethod('GET'));
	}

	public function testAllowsPathWithWildcard(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		];

		$apiKey = new ApiKeyData($data);

		$this->assertTrue($apiKey->allowsPath('/api/collections/blog'));
		$this->assertTrue($apiKey->allowsPath('/api/users/123'));
		$this->assertTrue($apiKey->allowsPath('/anything/goes'));
	}

	public function testAllowsPathWithSpecificPaths(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/api/collections/blog', '/api/collections/news'],
			],
		];

		$apiKey = new ApiKeyData($data);

		// str_starts_with means these all match
		$this->assertTrue($apiKey->allowsPath('/api/collections/blog'));
		$this->assertTrue($apiKey->allowsPath('/api/collections/blog/123'));
		$this->assertTrue($apiKey->allowsPath('/api/collections/news'));
		$this->assertTrue($apiKey->allowsPath('/api/collections/news/456'));
		// These don't start with allowed paths
		$this->assertFalse($apiKey->allowsPath('/api/collections/events/789'));
		$this->assertFalse($apiKey->allowsPath('/api/users/123'));
	}

	public function testToArrayReturnsAllProperties(): void
	{
		$data = [
			'id'       => 'test-id',
			'name'     => 'Test',
			'key'      => 'tcms_test',
			'created'  => '2025-01-15T10:30:00Z',
			'lastUsed' => '2025-01-16T14:20:00Z',
			'scopes'   => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		];

		$apiKey = new ApiKeyData($data);
		$array  = $apiKey->toArray();

		$this->assertEquals($data, $array);
	}

	public function testToArrayHandlesNullLastUsed(): void
	{
		$data = [
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['*'],
			],
		];

		$apiKey = new ApiKeyData($data);
		$array  = $apiKey->toArray();

		$this->assertNull($array['lastUsed']);
	}
}
