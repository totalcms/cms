<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Object\Support;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Auth\Service\UserValidationService;

/**
 * The guard wraps a real {@see AuthFieldPolicy} (both are final, so they're
 * exercised together rather than mocked). These tests assert the guard's own
 * responsibilities — the API-key/OAuth trust bypass and actor resolution from
 * the session — by observing whether the policy was allowed to act.
 */
final class PrivilegedFieldGuardTest extends TestCase
{
	/** @param array<string,mixed>|null $existing */
	private function policy(bool $superAdmin, ?array $existing): AuthFieldPolicy
	{
		$schema             = new SchemaData();
		$schema->id         = 'auth';
		$schema->properties = ['groups' => [], 'name' => []];

		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->willReturn($superAdmin);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('existsObject')->willReturn($existing !== null);
		if ($existing !== null) {
			$object = $this->createMock(ObjectData::class);
			$object->method('toArray')->willReturn($existing);
			$objectFetcher->method('fetchObject')->willReturn($object);
		}

		return new AuthFieldPolicy($schemaFetcher, $userValidation, $objectFetcher);
	}

	private function guard(AuthFieldPolicy $policy, string $actor = 'member1'): PrivilegedFieldGuard
	{
		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? $actor : null,
		);

		return new PrivilegedFieldGuard($policy, $session);
	}

	private function request(?string $authMethod): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnCallback(
			static fn (string $name): mixed => $name === 'authMethod' ? $authMethod : null,
		);

		return $request;
	}

	public function testApiKeyRequestsBypassFieldFiltering(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: ['groups' => ['member']]));

		// Trusted credential: the privileged value survives untouched.
		$out = $guard->guard($this->request('apikey'), 'auth', 'member1', ['groups' => ['admin']]);
		expect($out)->toBe(['groups' => ['admin']]);
	}

	public function testOAuthBearerRequestsBypassFieldFiltering(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: ['groups' => ['member']]));

		$out = $guard->guard($this->request('oauth_bearer'), 'auth', 'member1', ['groups' => ['admin']]);
		expect($out)->toBe(['groups' => ['admin']]);
	}

	public function testSessionRequestEnforcesPolicyForNonAdmin(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: ['groups' => ['member']]));

		// Untrusted session user: privileged field reverted to the stored value.
		$out = $guard->guard($this->request(null), 'auth', 'member1', ['groups' => ['admin'], 'name' => 'New']);
		expect($out['groups'])->toBe(['member']);
		expect($out['name'])->toBe('New');
	}

	public function testGuardPropertyBypassesForTrustedRequests(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: null));

		$guard->guardProperty($this->request('apikey'), 'auth', 'groups');
		$this->addToAssertionCount(1); // no exception
	}

	public function testGuardPropertyThrowsForNonAdminOnPrivilegedProperty(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: null));

		$this->expectException(HttpForbiddenException::class);
		$guard->guardProperty($this->request(null), 'auth', 'groups');
	}

	public function testGuardPropertyAllowsNonPrivilegedProperty(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: null));

		$guard->guardProperty($this->request(null), 'auth', 'name');
		$this->addToAssertionCount(1); // no exception
	}

	public function testGuardPropertyAllowsSuperAdminOnPrivilegedProperty(): void
	{
		$guard = $this->guard($this->policy(superAdmin: true, existing: null), actor: 'admin');

		$guard->guardProperty($this->request(null), 'auth', 'groups');
		$this->addToAssertionCount(1); // no exception
	}
}
