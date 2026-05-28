<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use TotalCMS\Domain\OAuth\Adapter\LeagueAccessTokenRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueAuthCodeRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueRefreshTokenRepository;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeRepository;
use TotalCMS\Support\Config;

/**
 * Assembles league's AuthorizationServer and ResourceServer wired with
 * T3's six adapter repositories and configuration from $config->oauth[].
 */
final class OAuthServerFactory
{
	public function __construct(
		private readonly LeagueClientRepository $clients,
		private readonly LeagueAccessTokenRepository $accessTokens,
		private readonly LeagueRefreshTokenRepository $refreshTokens,
		private readonly LeagueAuthCodeRepository $authCodes,
		private readonly LeagueScopeRepository $scopes,
		private readonly Config $config,
	) {
	}

	public function buildAuthorizationServer(): AuthorizationServer
	{
		$privateKey    = new CryptKey((string)$this->config->oauth['signingKeyPath']);
		$encryptionKey = $this->deriveEncryptionKey();

		$server = new AuthorizationServer(
			$this->clients,
			$this->accessTokens,
			$this->scopes,
			$privateKey,
			$encryptionKey,
		);

		$authCodeGrant = new AuthCodeGrant(
			$this->authCodes,
			$this->refreshTokens,
			new \DateInterval((string)$this->config->oauth['authCodeTtl']),
		);
		$authCodeGrant->setRefreshTokenTTL(new \DateInterval((string)$this->config->oauth['refreshTokenTtl']));

		// Strip the `plain` PKCE verifier. AuthCodeGrant's constructor
		// unconditionally registers both S256 and plain verifiers; the
		// codeChallengeVerifiers property is private so reflection is
		// the only way to remove plain after construction. We require
		// S256 only — `plain` would let an attacker who steals an
		// authorization code use it directly without the verifier.
		$verifiersProp = (new \ReflectionClass(AuthCodeGrant::class))
			->getProperty('codeChallengeVerifiers');
		/** @var array<string,mixed> $verifiers */
		$verifiers = $verifiersProp->getValue($authCodeGrant);
		unset($verifiers['plain']);
		$verifiersProp->setValue($authCodeGrant, $verifiers);

		$server->enableGrantType(
			$authCodeGrant,
			new \DateInterval((string)$this->config->oauth['accessTokenTtl']),
		);

		$refreshGrant = new RefreshTokenGrant($this->refreshTokens);
		$refreshGrant->setRefreshTokenTTL(new \DateInterval((string)$this->config->oauth['refreshTokenTtl']));
		$server->enableGrantType(
			$refreshGrant,
			new \DateInterval((string)$this->config->oauth['accessTokenTtl']),
		);

		return $server;
	}

	public function buildResourceServer(): ResourceServer
	{
		// Public keys are public — operator-readable / world-readable is the
		// expected posture. league's CryptKey enforces a strict 0400/0440/
		// 0600/0640/0660 allowlist that doesn't accommodate the typical 0644
		// shipped by `tcms oauth:setup`. The third arg disables the check on
		// the public key only; the private key (in buildAuthorizationServer)
		// still gets the full league enforcement.
		$publicKey = new CryptKey((string)$this->config->oauth['publicKeyPath'], null, false);

		return new ResourceServer($this->accessTokens, $publicKey);
	}

	/**
	 * Derive a deterministic symmetric encryption key from the private key
	 * file contents. League accepts a plain PHP string for password-based
	 * symmetric encryption of auth-code payloads (Defuse\Crypto\Crypto::encryptWithPassword).
	 * Using the hex SHA-256 of the PEM keeps it printable and stable across
	 * calls as long as the key file does not change.
	 */
	private function deriveEncryptionKey(): string
	{
		$pem = (string)file_get_contents((string)$this->config->oauth['signingKeyPath']);

		return hash('sha256', $pem);
	}
}
