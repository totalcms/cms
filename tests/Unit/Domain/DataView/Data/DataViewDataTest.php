<?php

namespace Tests\Unit\Domain\DataView\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Data\DataViewData;

final class DataViewDataTest extends TestCase
{
	public function testCollectionIdConstant(): void
	{
		$this->assertSame('dataviews', DataViewData::COLLECTION_ID);
	}

	public function testSchemaIdConstant(): void
	{
		$this->assertSame('dataviews', DataViewData::SCHEMA_ID);
	}
}
