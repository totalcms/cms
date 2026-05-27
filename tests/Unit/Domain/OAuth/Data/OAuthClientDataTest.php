<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;

final class OAuthClientDataTest extends TestCase
{
	public function testConstructsFromArray(): void
	{
		$client = OAuthClientData::fromArray([
			'id'            => 'c-123',
			'name'          => 'ActivePieces',
			'secret_hash'   => '$2y$12$...hash...',
			'redirect_uris' => ['https://cloud.activepieces.com/cb'],
			'scopes'        => ['cms:read', 'cms:write'],
			'is_dynamic'    => false,
			'is_confidential' => true,
			'created_at'    => '2026-05-24T10:00:00Z',
			'created_by'    => 'admin@example.com',
		]);

		$this->assertSame('c-123', $client->id);
		$this->assertSame('ActivePieces', $client->name);
		$this->assertSame(['https://cloud.activepieces.com/cb'], $client->redirectUris);
		$this->assertTrue($client->isConfidential);
		$this->assertFalse($client->isDynamic);
	}

	public function testToArrayRoundTrip(): void
	{
		$payload = [
			'id'              => 'c-123',
			'name'            => 'Test',
			'secret_hash'     => 'hash',
			'redirect_uris'   => ['https://x.test/cb'],
			'scopes'          => ['cms:read'],
			'is_dynamic'      => true,
			'is_confidential' => false,
			'created_at'      => '2026-05-24T10:00:00Z',
			'created_by'      => 'admin',
			'icon_path'       => null,
		];

		$client = OAuthClientData::fromArray($payload);
		$this->assertSame($payload, $client->toArray());
	}
}
