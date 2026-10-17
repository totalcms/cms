<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Object\Support;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * The guard wraps a real {@see AuthFieldPolicy} (both are final, so they're
 * exercised together rather than mocked). These tests assert the guard's own
 * responsibilities — the API-key/OAuth trust bypass and actor resolution from
 * the session — by observing whether the policy was allowed to act.
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

	private function guard(AuthFieldPolicy $policy, string $actor = 'member1'): PrivilegedFieldGuard
	{
		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? $actor : null,
		);

		return new PrivilegedFieldGuard($policy, $session, $this->config());
	}

	/**
	 * @param array<string,mixed> $attributes extra request attributes (e.g. accessAuthority, oauth_user_id)
	 */
	private function request(?string $authMethod, array $attributes = []): ServerRequestInterface
	{
		$attributes['authMethod'] = $authMethod;

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
		// The session is empty for a bearer request: the actor must come from the token.
		$guard   = $this->guard($policy, actor: '');
		$request = $this->request('oauth_bearer', [
			'accessAuthority' => new \stdClass(),
			'oauth_user_id'   => 'members:alex',
		]);

		$out = $guard->guard($request, 'portfolio', 'new-site', ['title' => 'Mine', 'draft' => false, 'owner' => 'sam']);

		expect($out['draft'])->toBeTrue();
		expect($out['owner'])->toBe('alex');
		expect($out['title'])->toBe('Mine');
	}

	public function testNonAdminOAuthBearerResolvesActorCollectionFromToken(): void
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
		$request = $this->request('oauth_bearer', ['accessAuthority' => new \stdClass(), 'oauth_user_id' => 'members:alex']);

		$this->guard($policy, actor: '')->guardProperty($request, 'portfolio', 'site-1', 'draft');
		$this->addToAssertionCount(1); // no exception: resolved as alex in members

		$this->expectException(HttpForbiddenException::class);
		$other = $this->request('oauth_bearer', ['accessAuthority' => new \stdClass(), 'oauth_user_id' => 'auth:alex']);
		$this->guard($policy, actor: '')->guardProperty($other, 'portfolio', 'site-1', 'draft');
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
		$guard = $this->guard($this->policy(superAdmin: true, existing: null), actor: 'admin');

		$guard->guardProperty($this->request(null), 'auth', 'member1', 'groups');
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

		$out = $this->guard($policy, actor: 'admin')->guardProperty($this->request(null), 'auth', 'site-1', 'title');

		expect($out)->toBe([]);
	}
}
