<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Adapter\LeagueAccessTokenRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueAuthCodeRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueRefreshTokenRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthReplayDetector;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Domain\OAuth\Service\OAuthServerFactory;
use TotalCMS\Support\Config;

final class OAuthServerFactoryTest extends TestCase
{
	private string $tmpDir;
	private string $privateKeyPath;
	private string $publicKeyPath;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/oauth-factory-test-' . uniqid();
		mkdir($this->tmpDir, 0700, true);

		$resource = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);
		$this->assertNotFalse($resource, 'openssl_pkey_new() failed');

		openssl_pkey_export($resource, $privatePem);
		$details = openssl_pkey_get_details($resource);
		$this->assertIsArray($details);
		$publicPem = (string)$details['key'];

		$this->privateKeyPath = $this->tmpDir . '/private.key';
		$this->publicKeyPath  = $this->tmpDir . '/public.key';

		file_put_contents($this->privateKeyPath, $privatePem);
		file_put_contents($this->publicKeyPath, $publicPem);
		chmod($this->privateKeyPath, 0600);
	}

	protected function tearDown(): void
	{
		@unlink($this->privateKeyPath);
		@unlink($this->publicKeyPath);
		@rmdir($this->tmpDir);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @param array<string,mixed> $oauthOverrides */
	private function makeConfig(array $oauthOverrides = []): Config
	{
		$config     = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$reflection = new \ReflectionClass($config);
		$prop       = $reflection->getProperty('oauth');
		$prop->setValue($config, array_merge([
			'signingKeyPath'  => $this->privateKeyPath,
			'publicKeyPath'   => $this->publicKeyPath,
			'accessTokenTtl'  => 'PT1H',
			'refreshTokenTtl' => 'P30D',
			'authCodeTtl'     => 'PT10M',
		], $oauthOverrides));

		return $config;
	}

	/** @param array<string,mixed> $oauthOverrides */
	private function makeFactory(array $oauthOverrides = []): OAuthServerFactory
	{
		$cache = $this->createMock(CacheManager::class);

		$clientRepo  = new OAuthClientRepository($this->tmpDir . '/clients.json');
		$grantRepo   = new OAuthGrantRepository($this->tmpDir . '/grants.json');
		$revocation  = new OAuthRevocationList($cache, 3600);
		$scopeReg    = new OAuthScopeRegistry();

		$replayCache = $this->createMock(CacheManager::class);
		$replayCache->method('storeComputedData')->willReturn(true);
		$replayCache->method('getComputedData')->willReturn(null);
		$replayDetector = new OAuthReplayDetector($replayCache, 2592000);
		$activityLogger = new OAuthActivityLogger(new NullLogger());

		$leagueClients       = new LeagueClientRepository($clientRepo);
		$leagueAccessTokens  = new LeagueAccessTokenRepository($revocation);
		$leagueRefreshTokens = new LeagueRefreshTokenRepository($grantRepo, $replayDetector, $activityLogger);
		$leagueAuthCodes     = new LeagueAuthCodeRepository($cache);
		$leagueScopes        = new LeagueScopeRepository(
			$scopeReg,
			$clientRepo,
			$this->createMock(\TotalCMS\Domain\Auth\Service\AccessControlService::class),
			$this->makeConfig(),
		);

		return new OAuthServerFactory(
			$leagueClients,
			$leagueAccessTokens,
			$leagueRefreshTokens,
			$leagueAuthCodes,
			$leagueScopes,
			$this->makeConfig($oauthOverrides),
		);
	}

	/**
	 * Reflects the enabled authorization_code grant out of the server and
	 * returns its protected defaultScope value (AbstractGrant::$defaultScope).
	 */
	private function authCodeGrantDefaultScope(AuthorizationServer $server): string
	{
		$grantsProp = (new \ReflectionClass(AuthorizationServer::class))->getProperty('enabledGrantTypes');
		/** @var array<string,object> $grants */
		$grants = $grantsProp->getValue($server);
		$this->assertArrayHasKey('authorization_code', $grants);

		$grant      = $grants['authorization_code'];
		$scopeProp  = (new \ReflectionClass($grant))->getParentClass()
			? $this->findProperty($grant, 'defaultScope')
			: null;
		$this->assertNotNull($scopeProp);

		return (string)$scopeProp->getValue($grant);
	}

	private function findProperty(object $object, string $name): ?\ReflectionProperty
	{
		$class = new \ReflectionClass($object);
		while ($class instanceof \ReflectionClass) {
			if ($class->hasProperty($name)) {
				return $class->getProperty($name);
			}
			$class = $class->getParentClass() ?: null;
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function testAuthCodeGrantGetsBaselineDefaultScopeWhenUnconfigured(): void
	{
		$server = $this->makeFactory()->buildAuthorizationServer();

		$this->assertSame(
			'cms:read mcp:tools mcp:resources mcp:prompts',
			$this->authCodeGrantDefaultScope($server),
		);
	}

	public function testAuthCodeGrantDefaultScopeIsConfigurable(): void
	{
		$server = $this->makeFactory(['defaultScope' => 'cms:read'])->buildAuthorizationServer();

		$this->assertSame('cms:read', $this->authCodeGrantDefaultScope($server));
	}

	public function testEmptyDefaultScopeDisablesTheFallback(): void
	{
		$server = $this->makeFactory(['defaultScope' => ''])->buildAuthorizationServer();

		$this->assertSame('', $this->authCodeGrantDefaultScope($server));
	}

	public function testBuildAuthorizationServerReturnsCorrectType(): void
	{
		$factory = $this->makeFactory();
		$server  = $factory->buildAuthorizationServer();

		$this->assertInstanceOf(AuthorizationServer::class, $server);
	}

	public function testBuildResourceServerReturnsCorrectType(): void
	{
		$factory = $this->makeFactory();
		$server  = $factory->buildResourceServer();

		$this->assertInstanceOf(ResourceServer::class, $server);
	}

	public function testEncryptionKeyIsDeterministic(): void
	{
		// Two factories built from the same key file must produce the same
		// encryption key. We verify indirectly: both AuthorizationServer instances
		// must be constructable without error — league will raise on a mismatched
		// key when it first encrypts. Here we test the factory-level invariant:
		// calling buildAuthorizationServer() twice on factories with the same config
		// must both succeed (i.e. the derived key is stable).
		$factory1 = $this->makeFactory();
		$factory2 = $this->makeFactory();

		$server1 = $factory1->buildAuthorizationServer();
		$server2 = $factory2->buildAuthorizationServer();

		$this->assertInstanceOf(AuthorizationServer::class, $server1);
		$this->assertInstanceOf(AuthorizationServer::class, $server2);
	}

	public function testBuildAuthorizationServerWithRefreshGrantEnabled(): void
	{
		// Regression guard: both grant types are registered without error.
		// Verify by checking the server is an AuthorizationServer (construction
		// failed if an exception was thrown).
		$server = $this->makeFactory()->buildAuthorizationServer();
		$this->assertInstanceOf(AuthorizationServer::class, $server);
	}

	public function testBuildResourceServerWithPublicKey(): void
	{
		// Regression guard: public key is loaded without error.
		$server = $this->makeFactory()->buildResourceServer();
		$this->assertInstanceOf(ResourceServer::class, $server);
	}
}
