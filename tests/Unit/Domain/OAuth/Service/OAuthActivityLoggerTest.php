<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;

final class OAuthActivityLoggerTest extends TestCase
{
	public function testClientCreatedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth client created',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'client.created'
					&& $ctx['client_id'] === 'c-1'
					&& $ctx['name'] === 'TestApp'
					&& $ctx['is_dynamic'] === false
					&& $ctx['created_by'] === 'admin@example.com'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->clientCreated('c-1', 'TestApp', false, 'admin@example.com');
	}

	public function testClientDeletedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth client deleted',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'client.deleted'
					&& $ctx['client_id'] === 'c-2'
					&& $ctx['deleted_by'] === 'admin@example.com'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->clientDeleted('c-2', 'admin@example.com');
	}

	public function testConsentGrantedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth consent granted',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'consent.granted'
					&& $ctx['client_id'] === 'c-3'
					&& $ctx['user_id'] === 'u-1'
					&& $ctx['scopes'] === ['cms:read', 'mcp:tools']
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->consentGranted('c-3', 'u-1', ['cms:read', 'mcp:tools']);
	}

	public function testConsentDeniedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth consent denied',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'consent.denied'
					&& $ctx['client_id'] === 'c-4'
					&& $ctx['user_id'] === 'u-2'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->consentDenied('c-4', 'u-2');
	}

	public function testTokenIssuedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth token issued',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'token.issued'
					&& $ctx['client_id'] === 'c-5'
					&& $ctx['user_id'] === 'u-3'
					&& $ctx['scopes'] === ['cms:write']
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->tokenIssued('c-5', 'u-3', ['cms:write']);
	}

	public function testTokenRefreshedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth token refreshed',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'token.refreshed'
					&& $ctx['client_id'] === 'c-6'
					&& $ctx['grant_id'] === 'g-1'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->tokenRefreshed('c-6', 'g-1');
	}

	public function testTokenRevokedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth token revoked',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'token.revoked'
					&& $ctx['client_id'] === 'c-7'
					&& $ctx['token_type'] === 'access_token'
					&& $ctx['token_id'] === 't-1'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->tokenRevoked('c-7', 'access_token', 't-1');
	}

	public function testRefreshReplayLogsAtWarningWithTruncatedHash(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'OAuth refresh token replay — chain revoked',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'security.refresh_replay'
					&& $ctx['client_id'] === 'c-8'
					&& $ctx['grant_id'] === 'g-2'
					&& $ctx['token_hash'] === 'abcdefgh…'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		// Full hash is longer than 8 chars; only first 8 + ellipsis should appear
		$sut->refreshReplayDetected('c-8', 'g-2', 'abcdefghijklmnop');
	}

	public function testScopeRejectedLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth scope-rejected request',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'scope.rejected'
					&& $ctx['client_id'] === 'c-9'
					&& $ctx['operation'] === 'object.write'
					&& $ctx['token_scopes'] === ['cms:read']
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->scopeRejected('c-9', 'object.write', ['cms:read']);
	}

	public function testDynamicRegistrationLogsAtInfoWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with(
				'OAuth dynamic client registered',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'client.dynamic_registered'
					&& $ctx['client_id'] === 'c-10'
					&& $ctx['client_name'] === 'DynamicApp'
					&& $ctx['remote_addr'] === '203.0.113.42'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->dynamicRegistration('c-10', 'DynamicApp', '203.0.113.42');
	}

	public function testRateLimitHitLogsAtWarningWithCorrectContext(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'OAuth rate limit hit',
				$this->callback(static fn (array $ctx): bool =>
					$ctx['type'] === 'security.rate_limit'
					&& $ctx['endpoint'] === '/oauth/token'
					&& $ctx['remote_addr'] === '198.51.100.7'
				),
			);

		$sut = new OAuthActivityLogger($logger);
		$sut->rateLimitHit('/oauth/token', '198.51.100.7');
	}
}
