<?php

namespace Tests\Unit\Domain\OAuth\Data;

use Tests\TestCase;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;

final class OAuthUserRefTest extends TestCase
{
	public function testParsesCompositeSub(): void
	{
		$ref = OAuthUserRef::parse('staff:jane-doe', 'auth');
		$this->assertSame('staff', $ref->collection);
		$this->assertSame('jane-doe', $ref->userId);
	}

	public function testBareLegacyIdUsesDefaultCollection(): void
	{
		$ref = OAuthUserRef::parse('admin-user-test-com', 'auth');
		$this->assertSame('auth', $ref->collection);
		$this->assertSame('admin-user-test-com', $ref->userId);
	}

	public function testSplitsOnFirstColonOnly(): void
	{
		$ref = OAuthUserRef::parse('auth:weird:id', 'auth');
		$this->assertSame('auth', $ref->collection);
		$this->assertSame('weird:id', $ref->userId);
	}

	public function testNonSlugPrefixTreatedAsBareId(): void
	{
		// Prefix with uppercase / invalid chars is not a collection slug.
		$ref = OAuthUserRef::parse('Not A Slug:x', 'auth');
		$this->assertSame('auth', $ref->collection);
		$this->assertSame('Not A Slug:x', $ref->userId);
	}

	public function testCanonicalStringRoundTrips(): void
	{
		$this->assertSame('staff:jane', (string)OAuthUserRef::parse(OAuthUserRef::compose('staff', 'jane'), 'auth'));
	}

	public function testEmptySubYieldsEmptyUserId(): void
	{
		$ref = OAuthUserRef::parse('', 'auth');
		$this->assertSame('', $ref->userId);   // callers treat empty userId as no identity
	}
}
