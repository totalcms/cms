<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Object\Support;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Auth\Data\AccessActor;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * The guard wraps a real {@see AuthFieldPolicy} (both are final, so they're
 * exercised together rather than mocked). These tests assert the guard's own
 * responsibilities — the API-key/OAuth trust bypass and reading the actor that
 * BaseAccessMiddleware attached — by observing whether the policy was allowed
 * to act.
 */
final class PrivilegedFieldGuardTest extends TestCase
{
	/**
	 * @param array<string,mixed>|null          $existing
	 * @param array<string,array<string,mixed>> $definitions extra property definitions (e.g. a forced-value flag)
	 */
	private function policy(bool $superAdmin, ?array $existing, array $definitions = [], string $owner = ''): AuthFieldPolicy
	{
		$schema             = new SchemaData();
		$schema->id         = 'auth';
		$schema->properties = array_merge(['groups' => [], 'name' => [], 'title' => []], $definitions);

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

		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['enable' => true, 'collection' => 'auth'];

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$ownerProperties = $this->createMock(OwnerPropertyResolver::class);
		$ownerProperties->method('resolve')->willReturn($owner);

		return new AuthFieldPolicy($schemaFetcher, $userValidation, $objectFetcher, $ownerProperties, $config, $loggerFactory);
	}

	private function config(): Config
	{
		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['enable' => true, 'collection' => 'auth'];

		return $config;
	}

	private function guard(AuthFieldPolicy $policy): PrivilegedFieldGuard
	{
		return new PrivilegedFieldGuard($policy);
	}

	/**
	 * A request as BaseAccessMiddleware hands it on: carrying the resolved
	 * actor (`$actor = null` for a request that never went through it).
	 *
	 * @param array<string,mixed> $attributes extra request attributes (e.g. accessAuthority)
	 */
	private function request(?string $authMethod, array $attributes = [], ?AccessActor $actor = new AccessActor('member1', '')): ServerRequestInterface
	{
		$attributes['authMethod'] = $authMethod;
		if ($actor !== null) {
			$attributes['accessActor'] = $actor;
		}

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $attributes[$name] ?? $default,
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

	public function testAdminOAuthBearerRequestsBypassFieldFiltering(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: ['groups' => ['member']]));

		// BaseAccessMiddleware sets no accessAuthority for an admin bearer.
		$out = $guard->guard($this->request('oauth_bearer'), 'auth', 'member1', ['groups' => ['admin']]);
		expect($out)->toBe(['groups' => ['admin']]);
	}

	public function testNonAdminOAuthBearerIsTreatedAsTheTokenUser(): void
	{
		$policy = $this->policy(
			superAdmin: false,
			existing: null,
			definitions: ['draft' => ['protected' => ['value' => true]], 'owner' => []],
			owner: 'owner',
		);
		// BaseAccessMiddleware resolved the actor from the token's sub claim.
		$guard   = $this->guard($policy);
		$request = $this->request('oauth_bearer', ['accessAuthority' => new \stdClass()], new AccessActor('alex', 'members'));

		$out = $guard->guard($request, 'portfolio', 'new-site', ['title' => 'Mine', 'draft' => false, 'owner' => 'sam']);

		expect($out['draft'])->toBeTrue();
		expect($out['owner'])->toBe('alex');
		expect($out['title'])->toBe('Mine');
	}

	public function testNonAdminOAuthBearerPassesActorCollectionFromAttribute(): void
	{
		$schema             = new SchemaData();
		$schema->id         = 'portfolio';
		$schema->properties = ['title' => [], 'draft' => ['protected' => true]];

		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		// Only alex-in-members is a super admin: the guard must pass both from the token.
		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->willReturnCallback(
			static fn (string $id, string $collection = ''): bool => $id === 'alex' && $collection === 'members',
		);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());
		$ownerProperties = $this->createMock(OwnerPropertyResolver::class);
		$ownerProperties->method('resolve')->willReturn('');

		$policy  = new AuthFieldPolicy($schemaFetcher, $userValidation, $this->createMock(ObjectFetcher::class), $ownerProperties, $this->config(), $loggerFactory);
		$request = $this->request('oauth_bearer', ['accessAuthority' => new \stdClass()], new AccessActor('alex', 'members'));

		$this->guard($policy)->guardProperty($request, 'portfolio', 'site-1', 'draft');
		$this->addToAssertionCount(1); // no exception: resolved as alex in members

		$this->expectException(HttpForbiddenException::class);
		$other = $this->request('oauth_bearer', ['accessAuthority' => new \stdClass()], new AccessActor('alex', 'auth'));
		$this->guard($policy)->guardProperty($other, 'portfolio', 'site-1', 'draft');
	}

	public function testSessionRequestEnforcesPolicyForNonAdmin(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: ['groups' => ['member']]));

		// Untrusted session user: privileged field reverted to the stored value.
		$out = $guard->guard($this->request(null), 'auth', 'member1', ['groups' => ['admin'], 'name' => 'New']);
		expect($out['groups'])->toBe(['member']);
		expect($out['name'])->toBe('New');
	}

	public function testRequestWithoutActorAttributeIsTreatedAsAnonymous(): void
	{
		// isSuperAdmin would say yes to anyone: only an empty actor id (the
		// policy never asks about it) keeps the privileged field reverted.
		$guard = $this->guard($this->policy(superAdmin: true, existing: ['groups' => ['member']]));

		$out = $guard->guard($this->request(null, [], null), 'auth', 'member1', ['groups' => ['admin']]);
		expect($out['groups'])->toBe(['member']);

		$this->expectException(HttpForbiddenException::class);
		$guard->guardProperty($this->request(null, [], null), 'auth', 'member1', 'groups');
	}

	public function testAdminOAuthBearerWithNoActorAttributeIsStillTrusted(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: ['groups' => ['member']]));

		$out = $guard->guard($this->request('oauth_bearer', [], null), 'auth', 'member1', ['groups' => ['admin']]);
		expect($out)->toBe(['groups' => ['admin']]);
		expect($guard->guardProperty($this->request('oauth_bearer', [], null), 'auth', 'member1', 'groups'))->toBe([]);
	}

	public function testGuardPropertyBypassesForTrustedRequests(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: null));

		$guard->guardProperty($this->request('apikey'), 'auth', 'member1', 'groups');
		$this->addToAssertionCount(1); // no exception
	}

	public function testGuardPropertyThrowsForNonAdminOnPrivilegedProperty(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: null));

		$this->expectException(HttpForbiddenException::class);
		$guard->guardProperty($this->request(null), 'auth', 'member1', 'groups');
	}

	public function testGuardPropertyAllowsNonPrivilegedProperty(): void
	{
		$guard = $this->guard($this->policy(superAdmin: false, existing: null));

		$guard->guardProperty($this->request(null), 'auth', 'member1', 'name');
		$this->addToAssertionCount(1); // no exception
	}

	public function testGuardPropertyAllowsSuperAdminOnPrivilegedProperty(): void
	{
		$guard = $this->guard($this->policy(superAdmin: true, existing: null));

		$guard->guardProperty($this->request(null, [], new AccessActor('admin', '')), 'auth', 'member1', 'groups');
		$this->addToAssertionCount(1); // no exception
	}

	public function testGuardPropertyReturnsForcedValuesForNonAdmin(): void
	{
		$policy = $this->policy(superAdmin: false, existing: ['draft' => false], definitions: ['draft' => ['protected' => ['value' => true]]]);

		$out = $this->guard($policy)->guardProperty($this->request(null), 'auth', 'site-1', 'title');

		expect($out)->toBe(['draft' => true]);
	}

	public function testGuardPropertyReturnsNothingForTrustedRequests(): void
	{
		$policy = $this->policy(superAdmin: false, existing: ['draft' => false], definitions: ['draft' => ['protected' => ['value' => true]]]);

		$out = $this->guard($policy)->guardProperty($this->request('apikey'), 'auth', 'site-1', 'title');

		expect($out)->toBe([]);
	}

	public function testGuardPropertyReturnsNothingForSuperAdmin(): void
	{
		$policy = $this->policy(superAdmin: true, existing: ['draft' => false], definitions: ['draft' => ['protected' => ['value' => true]]]);

		$out = $this->guard($policy)->guardProperty($this->request(null, [], new AccessActor('admin', '')), 'auth', 'site-1', 'title');

		expect($out)->toBe([]);
	}
}
