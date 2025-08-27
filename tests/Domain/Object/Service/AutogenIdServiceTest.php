<?php

namespace Tests\Domain\Object\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Property\Data\SlugData;

class AutogenIdServiceTest extends TestCase
{
	public function testSlugification(): void
	{
		$result = SlugData::slugify('My Awesome Post!!! @Home');
		$this->assertEquals('my-awesome-post-at-home', $result);
	}

	public function testSlugificationWithSpecialCharacters(): void
	{
		$result = SlugData::slugify('Test@Email.com');
		$this->assertEquals('test-at-email-com', $result);
	}

	public function testSlugificationWithSpaces(): void
	{
		$result = SlugData::slugify('Multiple   Spaces   Here');
		$this->assertEquals('multiple-spaces-here', $result);
	}

	public function testGenerateUuid(): void
	{
		$uuid = AutogenIdService::generateUuid();

		// Should match UUID v4 pattern: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
		$this->assertEquals(36, strlen($uuid));
	}

	public function testGenerateUid(): void
	{
		$uid = AutogenIdService::generateUid();

		// Should be 7-character alphanumeric string
		$this->assertMatchesRegularExpression('/^[a-z0-9]{7}$/', $uid);
		$this->assertEquals(7, strlen($uid));
	}

	public function testGenerateIdWithOidCount(): void
	{
		$objectData = ['title' => 'Test Post', 'author' => 'John'];
		$result     = AutogenIdService::generateIdWithOidCount('${title}-${author}-${oid-000}', $objectData, 42);

		$this->assertEquals('test-post-john-043', $result);
	}

	public function testGenerateIdWithOidCountUuidAndUid(): void
	{
		$objectData = ['title' => 'Article'];
		$result     = AutogenIdService::generateIdWithOidCount('${title}-${uuid}', $objectData, 5);

		// Should contain title and UUID
		$this->assertStringStartsWith('article-', $result);
		$this->assertMatchesRegularExpression('/article-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', $result);

		$result2 = AutogenIdService::generateIdWithOidCount('${title}-${uid}', $objectData, 5);

		// Should contain title and UID
		$this->assertStringStartsWith('article-', $result2);
		$this->assertMatchesRegularExpression('/article-[a-z0-9]{7}/', $result2);
	}
}
