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

	public function testAuthorizesAutomationWebhookViaMethodAndPathScope(): void
	{
		// Firing a webhook is just POST /automations/<id> — gated by the normal
		// method+path scopes, no special automations flag.
		$scoped = new ApiKeyData([
			'id'      => 'k1',
			'name'    => 'Scoped',
			'key'     => 'tcms_scoped',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['POST'], 'paths' => ['/automations']],
		]);
		$wrongPath = new ApiKeyData([
			'id'      => 'k2',
			'name'    => 'Wrong path',
			'key'     => 'tcms_wrongpath',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['POST'], 'paths' => ['/collections']],
		]);
		$wrongMethod = new ApiKeyData([
			'id'      => 'k3',
			'name'    => 'Wrong method',
			'key'     => 'tcms_wrongmethod',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => ['methods' => ['GET'], 'paths' => ['/automations']],
		]);

		$this->assertTrue($this->checker->allows($scoped, 'POST', '/automations/daily'));
		$this->assertFalse($this->checker->allows($wrongPath, 'POST', '/automations/daily'));
		$this->assertFalse($this->checker->allows($wrongMethod, 'POST', '/automations/daily'));
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

	/**
	 * A prefix grant only matches on a segment boundary.
	 *
	 * A key scoped to "/collections/blog" must not also reach
	 * "/collections/blog-archive" or "/collections/blogroll" merely
	 * because the string happens to start with the granted path.
	 * See task-3b-brief.md "Must now deny".
	 */
	public function testAllowsPathDeniesSiblingCollectionsWithSharedPrefix(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/blog'],
			],
		]);

		$this->assertFalse($this->checker->allowsPath($apiKey, '/collections/blog-archive'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/collections/blogroll'));
	}

	public function testAllowsPathDeniesSiblingResourceWithSharedPrefix(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/upload'],
			],
		]);

		$this->assertFalse($this->checker->allowsPath($apiKey, '/uploads'));
	}

	/**
	 * A parent segment still grants its whole subtree, and an exact
	 * grant still matches itself and its own children. See task-3b-brief.md
	 * "Must continue to grant".
	 */
	public function testAllowsPathStillGrantsParentSegmentSubtree(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/blog'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/blog/123'));
	}

	public function testAllowsPathStillGrantsExactMatchAndItsSubtree(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/blog'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/blog'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/blog/123'));
	}

	public function testAllowsPathBoundaryCheckIsCaseInsensitive(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/COLLECTIONS/Blog'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/blog'));
	}

	public function testAllowsPathTreatsTrailingSlashOnGrantedPathAsSubtreeGrant(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/blog/'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/blog'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/collections/blog/123'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/collections/blog-archive'));
	}

	// The /api route-group prefix is a routing artifact, not part of the grant
	// vocabulary: the endpoint picker stores grants without it ("/sync",
	// "/collections/blog") while request paths carry it, and keys created
	// directly via POST /apikeys may carry it in the grant instead. Matching
	// must succeed regardless of which side has the prefix.

	public function testPickerShapeGrantMatchesApiPrefixedRequestPath(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/sync'],
			],
		]);

		// The customer bug: "Sync Manager" grant vs the real route path
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/sync/export'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/sync/import'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/sync'));

		// Prefix stripping must not blur segment boundaries or lookalikes
		$this->assertFalse($this->checker->allowsPath($apiKey, '/api/synchronize'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/apikeys/sync'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/api/collections/blog'));
	}

	public function testPickerShapeCollectionGrantMatchesApiPrefixedRequestPath(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/collections/blog'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/collections/blog'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/collections/blog/123'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/api/collections/blog-archive'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/api/collections/news'));
	}

	public function testApiPrefixedGrantMatchesEitherRequestPathShape(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/api/sync'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/api/sync/export'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/sync/export'));
	}

	public function testPublicRouteGrantsAreUnaffectedByApiPrefixNormalization(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'test-id',
			'name'    => 'Test',
			'key'     => 'tcms_test',
			'created' => '2025-01-15T10:30:00Z',
			'scopes'  => [
				'methods' => ['GET'],
				'paths'   => ['/mcp', '/automations', '/xmlrpc.php'],
			],
		]);

		$this->assertTrue($this->checker->allowsPath($apiKey, '/mcp'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/automations/daily'));
		$this->assertTrue($this->checker->allowsPath($apiKey, '/xmlrpc.php'));
		$this->assertFalse($this->checker->allowsPath($apiKey, '/collections/blog'));
	}
}
