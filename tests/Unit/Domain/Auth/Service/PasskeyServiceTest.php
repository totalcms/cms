<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\PasskeyService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class PasskeyServiceTest extends TestCase
{
	private PasskeyService $service;
	private MockObject $session;
	private MockObject $config;
	private MockObject $objectFetcher;
	private MockObject $objectPatcher;
	private MockObject $indexReader;
	private MockObject $loggerFactory;

	protected function setUp(): void
	{
		$this->session       = $this->createMock(SessionInterface::class);
		$this->config        = $this->createMock(Config::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectPatcher = $this->createMock(ObjectPatcher::class);
		$this->indexReader   = $this->createMock(IndexReader::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);

		// Mock logger factory chain
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		// Configure config mock
		$this->config->domain    = 'localhost';
		$this->config->auth      = ['collection' => 'auth'];
		$this->config->dashboard = ['title' => 'Test CMS'];

		$this->service = new PasskeyService(
			$this->session,
			$this->config,
			$this->objectFetcher,
			$this->objectPatcher,
			$this->indexReader,
			$this->loggerFactory,
		);
	}

	public function testGenerateRegistrationOptionsReturnsValidStructure(): void
	{
		$userId     = 'admin';
		$collection = 'auth';

		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'name'     => 'Admin User',
			'passkeys' => [],
		]);

		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with($collection, $userId)
			->willReturn($userData);

		$this->session->expects($this->once())
			->method('set')
			->with(
				SessionKeys::WEBAUTHN_REGISTER_OPTIONS,
				$this->isType('string')
			);

		$options = $this->service->generateRegistrationOptions($userId, $collection);

		$this->assertArrayHasKey('challenge', $options);
		$this->assertArrayHasKey('rp', $options);
		$this->assertArrayHasKey('user', $options);
		$this->assertArrayHasKey('pubKeyCredParams', $options);
		$this->assertArrayHasKey('authenticatorSelection', $options);

		// RP entity
		$this->assertSame('Test CMS', $options['rp']['name']);
		$this->assertSame('localhost', $options['rp']['id']);

		// User entity
		$this->assertSame('admin@test.com', $options['user']['name']);
		$this->assertSame('Admin User', $options['user']['displayName']);

		// Challenge should be base64url-encoded (no padding)
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $options['challenge']);

		// Attestation
		$this->assertSame('none', $options['attestation']);
	}

	public function testGenerateRegistrationOptionsExcludesExistingPasskeys(): void
	{
		$userId     = 'admin';
		$collection = 'auth';

		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'name'     => 'Admin User',
			'passkeys' => [
				[
					'credentialId' => 'dGVzdC1jcmVkZW50aWFs',
					'publicKey'    => 'dGVzdC1rZXk',
					'transports'   => ['internal'],
					'signCount'    => 0,
					'aaguid'       => '00000000-0000-0000-0000-000000000000',
					'userHandle'   => 'YWRtaW4',
					'name'         => 'Test Passkey',
					'createdAt'    => '2026-01-01T00:00:00+00:00',
					'lastUsed'     => '2026-01-01T00:00:00+00:00',
				],
			],
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);
		$this->session->method('set');

		$options = $this->service->generateRegistrationOptions($userId, $collection);

		$this->assertArrayHasKey('excludeCredentials', $options);
		$this->assertCount(1, $options['excludeCredentials']);
		$this->assertSame('public-key', $options['excludeCredentials'][0]['type']);
	}

	public function testGenerateAuthenticationOptionsReturnsValidStructure(): void
	{
		$this->session->expects($this->once())
			->method('set')
			->with(
				SessionKeys::WEBAUTHN_AUTH_OPTIONS,
				$this->isType('string')
			);

		$options = $this->service->generateAuthenticationOptions();

		$this->assertArrayHasKey('challenge', $options);
		$this->assertArrayHasKey('rpId', $options);
		$this->assertSame('localhost', $options['rpId']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $options['challenge']);
	}

	public function testVerifyRegistrationThrowsWithoutSessionOptions(): void
	{
		$this->session->method('get')
			->with(SessionKeys::WEBAUTHN_REGISTER_OPTIONS)
			->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No registration options found in session');

		$this->service->verifyRegistration('admin', 'auth', '{}');
	}

	public function testVerifyAuthenticationThrowsWithoutSessionOptions(): void
	{
		$this->session->method('get')
			->with(SessionKeys::WEBAUTHN_AUTH_OPTIONS)
			->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No authentication options found in session');

		$this->service->verifyAuthentication('{}');
	}

	public function testListPasskeysReturnsEmptyArrayWhenNoPasskeys(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'    => 'admin',
			'email' => 'admin@test.com',
		]);

		$this->objectFetcher->method('fetchObject')
			->with('auth', 'admin')
			->willReturn($userData);

		$result = $this->service->listPasskeys('admin', 'auth');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testListPasskeysReturnsSafeSubset(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'passkeys' => [
				[
					'credentialId' => 'cred-123',
					'publicKey'    => 'secret-key-data',
					'signCount'    => 5,
					'transports'   => ['internal'],
					'aaguid'       => '00000000-0000-0000-0000-000000000000',
					'userHandle'   => 'YWRtaW4',
					'name'         => 'My MacBook',
					'createdAt'    => '2026-01-15T10:00:00+00:00',
					'lastUsed'     => '2026-02-20T14:30:00+00:00',
				],
			],
		]);

		$this->objectFetcher->method('fetchObject')
			->with('auth', 'admin')
			->willReturn($userData);

		$result = $this->service->listPasskeys('admin', 'auth');

		$this->assertCount(1, $result);
		$passkey = $result[0];

		// Should include safe fields
		$this->assertSame('cred-123', $passkey['credentialId']);
		$this->assertSame('My MacBook', $passkey['name']);
		$this->assertSame('2026-01-15T10:00:00+00:00', $passkey['createdAt']);
		$this->assertSame('2026-02-20T14:30:00+00:00', $passkey['lastUsed']);

		// Should NOT include sensitive fields
		$this->assertArrayNotHasKey('publicKey', $passkey);
		$this->assertArrayNotHasKey('signCount', $passkey);
		$this->assertArrayNotHasKey('transports', $passkey);
		$this->assertArrayNotHasKey('aaguid', $passkey);
		$this->assertArrayNotHasKey('userHandle', $passkey);
	}

	public function testDeletePasskeyRemovesCorrectCredential(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'passkeys' => [
				[
					'credentialId' => 'keep-this',
					'publicKey'    => 'key-1',
					'name'         => 'Passkey 1',
				],
				[
					'credentialId' => 'delete-this',
					'publicKey'    => 'key-2',
					'name'         => 'Passkey 2',
				],
				[
					'credentialId' => 'keep-this-too',
					'publicKey'    => 'key-3',
					'name'         => 'Passkey 3',
				],
			],
		]);

		$this->objectFetcher->method('fetchObject')
			->with('auth', 'admin')
			->willReturn($userData);

		$this->objectPatcher->expects($this->once())
			->method('patchObject')
			->with(
				'auth',
				'admin',
				$this->callback(function (array $data): bool {
					if (!isset($data['passkeys']) || !is_array($data['passkeys'])) {
						return false;
					}
					$passkeys = $data['passkeys'];
					// Should have 2 remaining passkeys
					if (count($passkeys) !== 2) {
						return false;
					}
					// Deleted credential should not be present
					$ids = array_column($passkeys, 'credentialId');

					return in_array('keep-this', $ids, true)
						&& in_array('keep-this-too', $ids, true)
						&& !in_array('delete-this', $ids, true);
				})
			);

		$this->service->deletePasskey('admin', 'auth', 'delete-this');
	}

	public function testDeletePasskeyHandlesNoPasskeys(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'    => 'admin',
			'email' => 'admin@test.com',
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);

		// With no passkeys key, still patches with empty array (harmless no-op)
		$this->objectPatcher->expects($this->once())
			->method('patchObject')
			->with(
				'auth',
				'admin',
				$this->callback(fn (array $data): bool => $data['passkeys'] === [])
			);

		$this->service->deletePasskey('admin', 'auth', 'nonexistent');
	}

	public function testDeletePasskeyHandlesNonexistentCredential(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'passkeys' => [
				['credentialId' => 'existing-cred', 'publicKey' => 'key-1', 'name' => 'Passkey 1'],
			],
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);

		// Should still patch, but the filtered array will still contain all passkeys
		$this->objectPatcher->expects($this->once())
			->method('patchObject')
			->with(
				'auth',
				'admin',
				$this->callback(fn (array $data): bool => count($data['passkeys']) === 1
						&& $data['passkeys'][0]['credentialId'] === 'existing-cred')
			);

		$this->service->deletePasskey('admin', 'auth', 'nonexistent-cred');
	}

	public function testListPasskeysHandlesNonArrayPasskeys(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'passkeys' => 'invalid-string',
		]);

		$this->objectFetcher->method('fetchObject')
			->with('auth', 'admin')
			->willReturn($userData);

		$result = $this->service->listPasskeys('admin', 'auth');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testGenerateRegistrationOptionsUsesEmailAsFallbackDisplayName(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'passkeys' => [],
			// No 'name' field
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);
		$this->session->method('set');

		$options = $this->service->generateRegistrationOptions('admin', 'auth');

		// Should fall back to email for display name
		$this->assertSame('admin@test.com', $options['user']['displayName']);
	}

	public function testGenerateRegistrationOptionsHasRequiredPubKeyCredParams(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'name'     => 'Admin',
			'passkeys' => [],
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);
		$this->session->method('set');

		$options = $this->service->generateRegistrationOptions('admin', 'auth');

		$this->assertCount(2, $options['pubKeyCredParams']);

		$algs = array_column($options['pubKeyCredParams'], 'alg');
		$this->assertContains(-7, $algs);    // ES256
		$this->assertContains(-257, $algs);  // RS256
	}

	public function testGenerateRegistrationOptionsRequiresResidentKey(): void
	{
		$userData = $this->createMock(ObjectData::class);
		$userData->method('toArray')->willReturn([
			'id'       => 'admin',
			'email'    => 'admin@test.com',
			'name'     => 'Admin',
			'passkeys' => [],
		]);

		$this->objectFetcher->method('fetchObject')->willReturn($userData);
		$this->session->method('set');

		$options = $this->service->generateRegistrationOptions('admin', 'auth');

		$this->assertArrayHasKey('authenticatorSelection', $options);
		$this->assertSame('required', $options['authenticatorSelection']['residentKey']);
	}
}
