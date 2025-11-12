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

	public function testGenerateIdWithCurrentYear(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('${currentyear}-${oid-000}', $objectData, 5);

		// Should match format: YYYY-XXX (e.g., 2025-006)
		$this->assertMatchesRegularExpression('/^\d{4}-\d{3}$/', $result);

		// Year should be current year
		$expectedYear = date('Y');
		$this->assertStringStartsWith($expectedYear . '-', $result);
	}

	public function testGenerateIdWithCurrentYear2(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('${currentyear2}-${oid-000}', $objectData, 5);

		// Should match format: YY-XXX (e.g., 25-006)
		$this->assertMatchesRegularExpression('/^\d{2}-\d{3}$/', $result);

		// Year should be current 2-digit year
		$expectedYear = date('y');
		$this->assertStringStartsWith($expectedYear . '-', $result);
	}

	public function testGenerateIdWithCurrentMonth(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('${currentmonth}-${oid-000}', $objectData, 5);

		// Should match format: MM-XXX (e.g., 11-006)
		$this->assertMatchesRegularExpression('/^\d{2}-\d{3}$/', $result);

		// Month should be current month (01-12)
		$expectedMonth = date('m');
		$this->assertStringStartsWith($expectedMonth . '-', $result);
	}

	public function testGenerateIdWithCurrentDay(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('${currentday}-${oid-000}', $objectData, 5);

		// Should match format: DD-XXX (e.g., 07-006)
		$this->assertMatchesRegularExpression('/^\d{2}-\d{3}$/', $result);

		// Day should be current day (01-31)
		$expectedDay = date('d');
		$this->assertStringStartsWith($expectedDay . '-', $result);
	}

	public function testGenerateIdWithAllDateComponents(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('${currentyear}-${currentmonth}-${currentday}-${oid-000}', $objectData, 5);

		// Should match format: YYYY-MM-DD-XXX (e.g., 2025-11-07-006)
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-\d{3}$/', $result);

		// Should start with current date
		$expectedDate = date('Y-m-d');
		$this->assertStringStartsWith($expectedDate . '-', $result);
	}

	public function testGenerateIdWithCompactDateFormat(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('${currentyear2}${currentmonth}${currentday}-${oid-000}', $objectData, 42);

		// Should match format: YYMMDD-XXX (e.g., 251107-043)
		$this->assertMatchesRegularExpression('/^\d{6}-\d{3}$/', $result);

		// Should start with compact date format
		$expectedDate = date('ymd');
		$this->assertStringStartsWith($expectedDate . '-', $result);

		// Should end with padded OID
		$this->assertStringEndsWith('-043', $result);
	}

	public function testGenerateIdWithMembershipCardFormat(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('26-${currentyear2}-${oid-000000}', $objectData, 1);

		// Should match format: 26-YY-XXXXXX (e.g., 26-25-000002)
		$this->assertMatchesRegularExpression('/^26-\d{2}-\d{6}$/', $result);

		// Should contain current 2-digit year
		$expectedYear = date('y');
		$this->assertStringContainsString('-' . $expectedYear . '-', $result);

		// Should end with padded OID
		$this->assertStringEndsWith('-000002', $result);
	}

	public function testGenerateIdWithInvoiceFormat(): void
	{
		$objectData = [];
		$result     = AutogenIdService::generateIdWithOidCount('INV-${currentyear}${currentmonth}-${oid-0000}', $objectData, 5);

		// Should match format: inv-YYYYMM-XXXX (e.g., inv-202511-0006)
		// Note: INV is slugified to lowercase
		$this->assertMatchesRegularExpression('/^inv-\d{6}-\d{4}$/', $result);

		// Should contain current year and month
		$expectedYearMonth = date('Ym');
		$this->assertStringContainsString('inv-' . $expectedYearMonth . '-', $result);
	}
}
