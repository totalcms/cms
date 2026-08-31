<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\PasskeyService;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * The two halves of passkey login that PasskeyServiceTest does not reach:
 * resolving a credential ID to its owner, and writing back the sign count
 * afterwards.
 *
 * Both are private, and both are the parts a WebAuthn ceremony fixture is not
 * needed for — they are plain logic over stored user records, so reflection
 * reaches them without inventing an attestation blob. They matter: the first
 * decides *whose* account an assertion logs into, and the second maintains the
 * counter that is the only signal a credential has been cloned.
 */
final class PasskeyCredentialLookupTest extends TestCase
{
	private ObjectFetcher $objectFetcher;
	private ObjectPatcher $objectPatcher;
	private IndexReader $indexReader;
	private PasskeyService $service;

	protected function setUp(): void
	{
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectPatcher = $this->createMock(ObjectPatcher::class);
		$this->indexReader   = $this->createMock(IndexReader::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->createMock(LoggerInterface::class));

		$config            = $this->createMock(Config::class);
		$config->domain    = 'localhost';
		$config->auth      = ['collection' => 'auth'];
		$config->dashboard = ['title' => 'Test CMS'];

		$this->service = new PasskeyService(
			$this->createMock(\Odan\Session\SessionInterface::class),
			$config,
			$this->objectFetcher,
			$this->objectPatcher,
			$this->indexReader,
			$loggerFactory,
		);
	}

	/** @param array<string,mixed> $user */
	private function objectData(array $user): ObjectData
	{
		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn($user);

		return $object;
	}

	/** @param array<int,array<string,mixed>> $users */
	private function indexOf(array $users): void
	{
		$this->indexReader->method('fetchIndex')->willReturn(
			new IndexData(array_map(static fn (array $u): array => ['id' => $u['id']], $users))
		);

		$byId = [];
		foreach ($users as $user) {
			$byId[(string)$user['id']] = $user;
		}

		$this->objectFetcher->method('fetchObject')->willReturnCallback(
			function (string $collection, string $id) use ($byId): ObjectData {
				if (!isset($byId[$id])) {
					throw new \RuntimeException("no such object {$id}");
				}

				return $this->objectData($byId[$id]);
			}
		);
	}

	/** @return array<string,mixed>|null */
	private function findCredential(string $credentialId): ?array
	{
		$method = new \ReflectionMethod(PasskeyService::class, 'findCredentialById');

		/** @var array<string,mixed>|null $result */
		$result = $method->invoke($this->service, $credentialId);

		return $result;
	}

	/** @param array<string,mixed> $user */
	private function updateAfterAuth(array $user, string $credentialId, int $signCount): void
	{
		(new \ReflectionMethod(PasskeyService::class, 'updatePasskeyAfterAuth'))
			->invoke($this->service, $user, 'auth', $credentialId, $signCount);
	}

	// ── Resolving a credential to its owner ──────────────────────────────────

	public function testFindsTheUserOwningACredential(): void
	{
		$this->indexOf([
			['id' => 'alice', 'passkeys' => [['credentialId' => 'cred-a']]],
			['id' => 'bob', 'passkeys' => [['credentialId' => 'cred-b']]],
		]);

		$found = $this->findCredential('cred-b');

		// Resolving to the wrong record here logs the assertion into the wrong
		// account, so the identity of the match is the whole point.
		$this->assertNotNull($found);
		$this->assertSame('bob', $found['user']['id']);
		$this->assertSame('cred-b', $found['passkey']['credentialId']);
		$this->assertSame('auth', $found['collection']);
	}

	public function testReturnsNullForAnUnknownCredential(): void
	{
		$this->indexOf([['id' => 'alice', 'passkeys' => [['credentialId' => 'cred-a']]]]);

		$this->assertNull($this->findCredential('cred-does-not-exist'));
	}

	public function testMatchesTheCredentialIdExactlyRatherThanLoosely(): void
	{
		// A prefix or case-insensitive match would let one credential
		// authenticate as the holder of another.
		$this->indexOf([['id' => 'alice', 'passkeys' => [['credentialId' => 'cred-abc']]]]);

		$this->assertNull($this->findCredential('cred-ab'));
		$this->assertNull($this->findCredential('CRED-ABC'));
		$this->assertNotNull($this->findCredential('cred-abc'));
	}

	public function testSearchesEveryPasskeyAUserHasRegistered(): void
	{
		$this->indexOf([['id' => 'alice', 'passkeys' => [
			['credentialId' => 'laptop'],
			['credentialId' => 'phone'],
			['credentialId' => 'yubikey'],
		]]]);

		$found = $this->findCredential('yubikey');

		$this->assertNotNull($found);
		$this->assertSame('yubikey', $found['passkey']['credentialId']);
	}

	public function testKeepsSearchingPastAUserWhoseRecordCannotBeRead(): void
	{
		// One unreadable record must not strand every user indexed after it —
		// that would lock people out of passkey login for someone else's
		// corrupt file.
		$this->indexReader->method('fetchIndex')->willReturn(
			new IndexData([['id' => 'broken'], ['id' => 'alice']])
		);
		$this->objectFetcher->method('fetchObject')->willReturnCallback(
			function (string $collection, string $id): ObjectData {
				if ($id === 'broken') {
					throw new \RuntimeException('unreadable');
				}

				return $this->objectData(['id' => 'alice', 'passkeys' => [['credentialId' => 'cred-a']]]);
			}
		);

		$found = $this->findCredential('cred-a');

		$this->assertNotNull($found);
		$this->assertSame('alice', $found['user']['id']);
	}

	public function testIgnoresUsersWithNoPasskeysOrMalformedEntries(): void
	{
		$this->indexOf([
			['id' => 'no-passkeys'],
			['id' => 'wrong-type', 'passkeys' => 'not-an-array'],
			['id' => 'ragged', 'passkeys' => ['garbage', ['credentialId' => 'cred-a']]],
		]);

		$found = $this->findCredential('cred-a');

		$this->assertNotNull($found);
		$this->assertSame('ragged', $found['user']['id']);
	}

	public function testSkipsIndexEntriesWithNoId(): void
	{
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([[], ['id' => 'alice']]));
		$this->objectFetcher->method('fetchObject')->willReturn(
			$this->objectData(['id' => 'alice', 'passkeys' => [['credentialId' => 'cred-a']]])
		);

		$this->assertNotNull($this->findCredential('cred-a'));
	}

	// ── Writing back after a successful assertion ────────────────────────────

	public function testStoresTheNewSignCountAndLastUsedDate(): void
	{
		$user = ['id' => 'alice', 'passkeys' => [
			['credentialId' => 'cred-a', 'signCount' => 4, 'name' => 'Laptop'],
		]];

		$this->objectPatcher->expects($this->once())->method('patchObject')
			->with('auth', 'alice', $this->callback(function (array $patch): bool {
				$passkey = $patch['passkeys'][0];

				// The sign count is the clone-detection signal: an authenticator
				// replaying an old count is how a copied credential shows up.
				// Failing to persist it silently disables that check.
				$this->assertSame(9, $passkey['signCount']);
				$this->assertNotEmpty($passkey['lastUsed']);
				// Untouched fields must survive the patch.
				$this->assertSame('Laptop', $passkey['name']);

				return true;
			}));

		$this->updateAfterAuth($user, 'cred-a', 9);
	}

	public function testUpdatesOnlyTheCredentialThatWasUsed(): void
	{
		$user = ['id' => 'alice', 'passkeys' => [
			['credentialId' => 'laptop', 'signCount' => 1],
			['credentialId' => 'phone', 'signCount' => 7],
		]];

		$this->objectPatcher->expects($this->once())->method('patchObject')
			->with('auth', 'alice', $this->callback(function (array $patch): bool {
				$this->assertSame(1, $patch['passkeys'][0]['signCount']);
				$this->assertSame(42, $patch['passkeys'][1]['signCount']);
				$this->assertArrayNotHasKey('lastUsed', $patch['passkeys'][0]);

				return true;
			}));

		$this->updateAfterAuth($user, 'phone', 42);
	}

	public function testWritesNothingWhenTheCredentialIsNotOnTheRecord(): void
	{
		// No match means no write at all, rather than a patch that rewrites the
		// list with nothing changed.
		$this->objectPatcher->expects($this->never())->method('patchObject');

		$this->updateAfterAuth(
			['id' => 'alice', 'passkeys' => [['credentialId' => 'laptop', 'signCount' => 1]]],
			'unknown-credential',
			5,
		);
	}

	public function testWritesNothingWhenThePasskeyListIsMalformed(): void
	{
		$this->objectPatcher->expects($this->never())->method('patchObject');

		$this->updateAfterAuth(['id' => 'alice', 'passkeys' => 'not-an-array'], 'laptop', 5);
		$this->updateAfterAuth(['id' => 'alice'], 'laptop', 5);
	}

	public function testAcceptsASignCountOfZeroFromAuthenticatorsThatDoNotCount(): void
	{
		// Plenty of authenticators always report 0. Treating that as "nothing to
		// store" would leave a stale count behind.
		$user = ['id' => 'alice', 'passkeys' => [['credentialId' => 'cred-a', 'signCount' => 3]]];

		$this->objectPatcher->expects($this->once())->method('patchObject')
			->with('auth', 'alice', $this->callback(function (array $patch): bool {
				$this->assertSame(0, $patch['passkeys'][0]['signCount']);

				return true;
			}));

		$this->updateAfterAuth($user, 'cred-a', 0);
	}
}
