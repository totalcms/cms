<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;

final class OAuthGrantDataTest extends TestCase
{
	public function testRoundTripsThroughArray(): void
	{
		$payload = [
			'id'                 => 'g-1',
			'client_id'          => 'c-1',
			'user_id'            => 'admin@example.com',
			'scopes'             => ['cms:read', 'cms:write'],
			'refresh_token_hash' => 'hash',
			'issued_at'          => '2026-05-24T10:00:00Z',
			'expires_at'         => '2026-06-23T10:00:00Z',
		];

		$grant = OAuthGrantData::fromArray($payload);
		$this->assertSame($payload, $grant->toArray());
	}
}
