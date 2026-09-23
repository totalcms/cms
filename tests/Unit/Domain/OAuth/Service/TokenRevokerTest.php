<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use Defuse\Crypto\Crypto;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthServerFactory;
use TotalCMS\Domain\OAuth\Service\TokenRevoker;

/**
 * RFC 7009 revocation used to live inline in the action, re-deriving the
 * refresh-token encryption key from the factory's internals — a change to
 * the derivation would have made revocation a silent no-op behind a 200.
 * The revoker now asks the factory for the key.
 */
final class TokenRevokerTest extends TestCase
{
	private const KEY = 'unit-test-encryption-key';

	private string $tmp = '';
	private OAuthGrantRepository $grants;
	private CacheManager&MockObject $cache;
	private LoggerInterface&MockObject $log;
	private TokenRevoker $revoker;

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/tcms-revoke-' . uniqid();
		mkdir($this->tmp, 0755, true);

		$this->grants = new OAuthGrantRepository(...jsonStoreArgs($this->tmp . '/grants.json'));
		$this->cache  = $this->createMock(CacheManager::class);
		$this->log    = $this->createMock(LoggerInterface::class);
		$factory      = $this->createMock(OAuthServerFactory::class);
		$factory->method('encryptionKey')->willReturn(self::KEY);

		$this->revoker = new TokenRevoker(
			$this->grants,
			new OAuthRevocationList($this->cache, 3600),
			new OAuthActivityLogger($this->log),
			$factory,
		);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->tmp));
	}

	private function client(): OAuthClientData
	{
		return new OAuthClientData('client-a', 'A', password_hash('s', PASSWORD_DEFAULT), [], [], false, true, '2026-01-01T00:00:00+00:00', 'tests');
	}

	private function refreshToken(string $refreshId): string
	{
		return Crypto::encryptWithPassword((string)json_encode(['client_id' => 'client-a', 'refresh_token_id' => $refreshId]), self::KEY);
	}

	private function seedGrant(string $refreshId, string $clientId = 'client-a'): void
	{
		$this->grants->save(new OAuthGrantData('grant-1', $clientId, 'user', [], hash('sha256', $refreshId), '2026-01-01T00:00:00+00:00', '2027-01-01T00:00:00+00:00'));
	}

	private function accessToken(string $audience): string
	{
		$config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(str_repeat('k', 32)));

		return $config->builder()->identifiedBy('jti-9')->permittedFor($audience)->getToken($config->signer(), $config->signingKey())->toString();
	}

	private function expectRevokedLog(string $type, string $id): void
	{
		$this->log->expects($this->once())->method('info')
			->with('OAuth token revoked', $this->callback(fn (array $ctx): bool => $ctx['token_type'] === $type && $ctx['token_id'] === $id && $ctx['client_id'] === 'client-a'));
	}

	public function testARefreshTokenDropsItsGrantWhenItBelongsToTheClient(): void
	{
		$this->seedGrant('rid-1');
		$this->expectRevokedLog('refresh_token', 'grant-1');

		$this->assertSame('refresh_token', $this->revoker->revoke($this->refreshToken('rid-1'), '', $this->client()));
		$this->assertNull($this->grants->find('grant-1'));
	}

	public function testAnotherClientsRefreshTokenIsANoOp(): void
	{
		$this->seedGrant('rid-1', 'client-b');
		$this->log->expects($this->never())->method('info');

		$this->assertNull($this->revoker->revoke($this->refreshToken('rid-1'), 'refresh_token', $this->client()));
		$this->assertNotNull($this->grants->find('grant-1'));
	}

	public function testGarbageIsANoOp(): void
	{
		$this->cache->expects($this->never())->method('storeComputedData');
		$this->log->expects($this->never())->method('info');

		$this->assertNull($this->revoker->revoke('not-a-token', '', $this->client()));
	}

	public function testAnAccessTokenForTheClientLandsOnTheRevocationList(): void
	{
		$this->cache->expects($this->once())->method('storeComputedData')->with($this->stringContains('jti-9'), true, 3600);
		$this->expectRevokedLog('access_token', 'jti-9');

		$this->assertSame('access_token', $this->revoker->revoke($this->accessToken('client-a'), '', $this->client()));
	}

	public function testAnAccessTokenIssuedToAnotherClientIsANoOp(): void
	{
		$this->cache->expects($this->never())->method('storeComputedData');

		$this->assertNull($this->revoker->revoke($this->accessToken('client-b'), 'access_token', $this->client()));
	}
}
