<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueUserRepository;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;

final class LeagueUserRepositoryTest extends TestCase
{
	public function testGetUserEntityByUserCredentialsAlwaysReturnsNull(): void
	{
		$adapter = new LeagueUserRepository();

		$clientData = new OAuthClientData(
			id:             'c-1',
			name:           'Test',
			secretHash:     'hash',
			redirectUris:   ['https://x.test/cb'],
			scopes:         ['cms:read'],
			isDynamic:      false,
			isConfidential: true,
			createdAt:      '2026-05-24T00:00:00Z',
			createdBy:      'admin',
		);
		$clientEntity = new LeagueClientEntity($clientData);

		$result = $adapter->getUserEntityByUserCredentials(
			'admin@example.test',
			'password123',
			'password',
			$clientEntity,
		);

		$this->assertNull($result);
	}
}
