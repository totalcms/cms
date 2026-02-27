<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;
use Symfony\Component\Uid\Uuid;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

class PasskeyService
{
	private readonly LoggerInterface $logger;
	private readonly SerializerInterface $serializer;
	private readonly AuthenticatorAttestationResponseValidator $attestationValidator;
	private readonly AuthenticatorAssertionResponseValidator $assertionValidator;

	public function __construct(
		private readonly SessionInterface $session,
		private readonly Config $config,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectPatcher $objectPatcher,
		private readonly IndexReader $indexReader,
		private readonly LoggerFactory $loggerFactory,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(LoginService::ACCESS_LOG)->createLogger('passkey');

		$ceremonyFactory = new CeremonyStepManagerFactory();

		$origin = $this->getOrigin();
		if ($origin !== '') {
			$ceremonyFactory->setAllowedOrigins([$origin]);
		}

		$this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
			$ceremonyFactory->creationCeremony()
		);
		$this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
			$ceremonyFactory->requestCeremony()
		);

		$attestationManager = new AttestationStatementSupportManager();
		$serializerFactory  = new WebauthnSerializerFactory($attestationManager);
		$this->serializer   = $serializerFactory->create();
	}

	/**
	 * Generate registration options for a user.
	 *
	 * @return array<string,mixed>
	 */
	public function generateRegistrationOptions(string $userId, string $collection): array
	{
		$user       = $this->objectFetcher->fetchObject($collection, $userId)->toArray();
		$rpEntity   = $this->getRpEntity();
		$userEntity = $this->getUserEntity($user);

		// Build exclude list from existing passkeys
		$excludeCredentials = [];
		$passkeys           = $user['passkeys'] ?? [];
		if (is_array($passkeys)) {
			foreach ($passkeys as $passkey) {
				if (!is_array($passkey) || !isset($passkey['credentialId'])) {
					continue;
				}
				$excludeCredentials[] = PublicKeyCredentialDescriptor::create(
					PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
					Base64UrlSafe::decodeNoPadding((string)$passkey['credentialId']),
					is_array($passkey['transports'] ?? null) ? $passkey['transports'] : [],
				);
			}
		}

		$challenge = random_bytes(32);

		$options = PublicKeyCredentialCreationOptions::create(
			rp: $rpEntity,
			user: $userEntity,
			challenge: $challenge,
			pubKeyCredParams: [
				PublicKeyCredentialParameters::createPk(-7),   // ES256
				PublicKeyCredentialParameters::createPk(-257), // RS256
			],
			authenticatorSelection: AuthenticatorSelectionCriteria::create(
				userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
				residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
			),
			attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
			excludeCredentials: $excludeCredentials,
			timeout: 300000,
		);

		// Store in session for verification
		$optionsJson = $this->serializer->serialize($options, 'json');
		$this->session->set(SessionKeys::WEBAUTHN_REGISTER_OPTIONS, $optionsJson);

		/** @var array<string,mixed> $result */
		$result = json_decode($optionsJson, true);

		return $result;
	}

	/**
	 * Verify a registration response and save the credential.
	 *
	 * @return array<string,mixed>
	 */
	public function verifyRegistration(string $userId, string $collection, string $clientResponse, string $name = ''): array
	{
		$optionsJson = $this->session->get(SessionKeys::WEBAUTHN_REGISTER_OPTIONS);
		$this->session->delete(SessionKeys::WEBAUTHN_REGISTER_OPTIONS);

		if (!is_string($optionsJson) || $optionsJson === '') {
			throw new \RuntimeException('No registration options found in session');
		}

		/** @var PublicKeyCredentialCreationOptions $options */
		$options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');

		/** @var PublicKeyCredential $credential */
		$credential = $this->serializer->deserialize($clientResponse, PublicKeyCredential::class, 'json');

		$host = $this->config->domain;

		$attestationResponse = $credential->response;
		if (!$attestationResponse instanceof AuthenticatorAttestationResponse) {
			throw new \RuntimeException('Invalid attestation response');
		}

		$credentialSource = $this->attestationValidator->check(
			$attestationResponse,
			$options,
			$host,
		);

		// Build passkey entry
		$passkey = [
			'credentialId' => Base64UrlSafe::encodeUnpadded($credentialSource->publicKeyCredentialId),
			'publicKey'    => Base64UrlSafe::encodeUnpadded($credentialSource->credentialPublicKey),
			'signCount'    => $credentialSource->counter,
			'transports'   => $credentialSource->transports,
			'aaguid'       => $credentialSource->aaguid->toRfc4122(),
			'userHandle'   => Base64UrlSafe::encodeUnpadded($credentialSource->userHandle),
			'name'         => $name !== '' ? $name : 'Passkey ' . date('Y-m-d'),
			'createdAt'    => date('c'),
			'lastUsed'     => date('c'),
		];

		// Add to user's passkeys array
		$user     = $this->objectFetcher->fetchObject($collection, $userId)->toArray();
		$passkeys = $user['passkeys'] ?? [];
		if (!is_array($passkeys)) {
			$passkeys = [];
		}
		$passkeys[] = $passkey;

		$this->objectPatcher->patchObject($collection, $userId, ['passkeys' => $passkeys]);

		$this->logger->info("Passkey registered for user {$collection}/{$userId}: {$passkey['name']}");

		return [
			'credentialId' => $passkey['credentialId'],
			'name'         => $passkey['name'],
			'createdAt'    => $passkey['createdAt'],
		];
	}

	/**
	 * Generate authentication options (discoverable, no user needed).
	 *
	 * @return array<string,mixed>
	 */
	public function generateAuthenticationOptions(): array
	{
		$challenge = random_bytes(32);

		$options = PublicKeyCredentialRequestOptions::create(
			challenge: $challenge,
			rpId: $this->config->domain,
			userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
			timeout: 300000,
		);

		$optionsJson = $this->serializer->serialize($options, 'json');
		$this->session->set(SessionKeys::WEBAUTHN_AUTH_OPTIONS, $optionsJson);

		/** @var array<string,mixed> $result */
		$result = json_decode($optionsJson, true);

		return $result;
	}

	/**
	 * Verify an authentication response and return the user data.
	 *
	 * @return array<string,mixed>
	 */
	public function verifyAuthentication(string $clientResponse): array
	{
		$optionsJson = $this->session->get(SessionKeys::WEBAUTHN_AUTH_OPTIONS);
		$this->session->delete(SessionKeys::WEBAUTHN_AUTH_OPTIONS);

		if (!is_string($optionsJson) || $optionsJson === '') {
			throw new \RuntimeException('No authentication options found in session');
		}

		/** @var PublicKeyCredentialRequestOptions $options */
		$options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');

		/** @var PublicKeyCredential $credential */
		$credential = $this->serializer->deserialize($clientResponse, PublicKeyCredential::class, 'json');

		// Look up credential by ID across all users
		$credentialIdBase64 = Base64UrlSafe::encodeUnpadded($credential->rawId);
		$found              = $this->findCredentialById($credentialIdBase64);

		if ($found === null) {
			throw new \RuntimeException('Passkey not recognized');
		}

		$passkey    = $found['passkey'];
		$user       = $found['user'];
		$collection = $found['collection'];

		// Reconstruct PublicKeyCredentialSource for verification
		$credentialSource = PublicKeyCredentialSource::create(
			publicKeyCredentialId: Base64UrlSafe::decodeNoPadding((string)$passkey['credentialId']),
			type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
			transports: is_array($passkey['transports'] ?? null) ? $passkey['transports'] : [],
			attestationType: 'none',
			trustPath: EmptyTrustPath::create(),
			aaguid: Uuid::fromString((string)$passkey['aaguid']),
			credentialPublicKey: Base64UrlSafe::decodeNoPadding((string)$passkey['publicKey']),
			userHandle: Base64UrlSafe::decodeNoPadding((string)$passkey['userHandle']),
			counter: (int)($passkey['signCount'] ?? 0),
		);

		$host = $this->config->domain;

		$assertionResponse = $credential->response;
		if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
			throw new \RuntimeException('Invalid assertion response');
		}

		$updatedSource = $this->assertionValidator->check(
			$credentialSource,
			$assertionResponse,
			$options,
			$host,
			$credentialSource->userHandle,
		);

		// Update sign count and last used
		$this->updatePasskeyAfterAuth($user, $collection, $credentialIdBase64, $updatedSource->counter);

		$this->logger->info("Passkey login for user {$collection}/{$user['id']}");

		return [
			'user'       => $user,
			'collection' => $collection,
		];
	}

	/**
	 * List passkeys for a user (safe subset, no raw keys).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function listPasskeys(string $userId, string $collection): array
	{
		$user     = $this->objectFetcher->fetchObject($collection, $userId)->toArray();
		$passkeys = $user['passkeys'] ?? [];
		if (!is_array($passkeys)) {
			return [];
		}

		$result = [];
		foreach ($passkeys as $passkey) {
			if (!is_array($passkey)) {
				continue;
			}
			$result[] = [
				'credentialId' => $passkey['credentialId'] ?? '',
				'name'         => $passkey['name'] ?? 'Unnamed',
				'createdAt'    => $passkey['createdAt'] ?? '',
				'lastUsed'     => $passkey['lastUsed'] ?? '',
			];
		}

		return $result;
	}

	/**
	 * Delete a passkey by credential ID.
	 */
	public function deletePasskey(string $userId, string $collection, string $credentialId): void
	{
		$user     = $this->objectFetcher->fetchObject($collection, $userId)->toArray();
		$passkeys = $user['passkeys'] ?? [];
		if (!is_array($passkeys)) {
			return;
		}

		$filtered = array_values(array_filter($passkeys, function (mixed $passkey) use ($credentialId): bool {
			return is_array($passkey) && ($passkey['credentialId'] ?? '') !== $credentialId;
		}));

		$this->objectPatcher->patchObject($collection, $userId, ['passkeys' => $filtered]);

		$this->logger->info("Passkey deleted for user {$collection}/{$userId}: {$credentialId}");
	}

	private function getRpEntity(): PublicKeyCredentialRpEntity
	{
		$rpName = 'Total CMS';
		if (isset($this->config->dashboard['title']) && is_string($this->config->dashboard['title'])) {
			$rpName = $this->config->dashboard['title'];
		}

		return PublicKeyCredentialRpEntity::create(
			name: $rpName,
			id: $this->config->domain,
		);
	}

	/**
	 * @param array<string,mixed> $user
	 */
	private function getUserEntity(array $user): PublicKeyCredentialUserEntity
	{
		$userId      = (string)($user['id'] ?? '');
		$userHandle  = Base64UrlSafe::encodeUnpadded($userId);
		$displayName = (string)($user['name'] ?? $user['email'] ?? $userId);
		$userName    = (string)($user['email'] ?? $userId);

		return PublicKeyCredentialUserEntity::create(
			name: $userName,
			id: $userHandle,
			displayName: $displayName,
		);
	}

	private function getOrigin(): string
	{
		$domain = $this->config->domain;
		if ($domain === '' || $domain === 'localhost') {
			return 'http://localhost';
		}

		return 'https://' . $domain;
	}

	/**
	 * Find a credential by its base64url-encoded ID across all users.
	 *
	 * @return array{passkey: array<string,mixed>, user: array<string,mixed>, collection: string}|null
	 */
	private function findCredentialById(string $credentialIdBase64): ?array
	{
		$collection = $this->config->auth['collection'];
		$index      = $this->indexReader->fetchIndex($collection);

		foreach ($index->objects as $indexEntry) {
			if (!isset($indexEntry['id'])) {
				continue;
			}

			try {
				$user = $this->objectFetcher->fetchObject($collection, (string)$indexEntry['id'])->toArray();
			} catch (\Throwable) {
				continue;
			}

			$passkeys = $user['passkeys'] ?? [];
			if (!is_array($passkeys)) {
				continue;
			}

			foreach ($passkeys as $passkey) {
				if (!is_array($passkey)) {
					continue;
				}
				if (($passkey['credentialId'] ?? '') === $credentialIdBase64) {
					return [
						'passkey'    => $passkey,
						'user'       => $user,
						'collection' => $collection,
					];
				}
			}
		}

		return null;
	}

	/**
	 * Update a passkey's sign count and last used date after authentication.
	 *
	 * @param array<string,mixed> $user
	 */
	private function updatePasskeyAfterAuth(array $user, string $collection, string $credentialIdBase64, int $newSignCount): void
	{
		$passkeys = $user['passkeys'] ?? [];
		if (!is_array($passkeys)) {
			return;
		}

		$updated = false;
		foreach ($passkeys as $index => $passkey) {
			if (!is_array($passkey)) {
				continue;
			}
			if (($passkey['credentialId'] ?? '') === $credentialIdBase64) {
				$passkeys[$index]['signCount'] = $newSignCount;
				$passkeys[$index]['lastUsed']  = date('c');
				$updated                       = true;
				break;
			}
		}

		if ($updated) {
			$this->objectPatcher->patchObject($collection, (string)$user['id'], ['passkeys' => $passkeys]);
		}
	}
}
