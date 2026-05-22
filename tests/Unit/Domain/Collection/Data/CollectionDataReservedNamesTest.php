<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;

final class CollectionDataReservedNamesTest extends TestCase
{
	public function testCoreReservedNamesArePresent(): void
	{
		// Spot-check the foundational reserved names — a global delete from
		// this constant would silently let customers create collections
		// named `extensions` / `templates` / `schemas` that collide with
		// admin sub-URLs and the schema storage path.
		$this->assertContains('extensions', CollectionData::RESERVED_NAMES);
		$this->assertContains('templates',  CollectionData::RESERVED_NAMES);
		$this->assertContains('schemas',    CollectionData::RESERVED_NAMES);
	}

	public function testViewAndViewsAreReservedForMcpUriNamespace(): void
	{
		// Phase 2 Chunk F reserves `view` and `views` so the MCP DataView
		// URI namespace (tcms://view/{id}) can't be shadowed by a customer-
		// created collection. Without this reservation a collection literally
		// named `view` would expose objects at tcms://view/{objectId} that
		// the SDK's resource router can't disambiguate from per-view URIs
		// registered by DataViewResourceRegistrar.
		$this->assertContains('view',  CollectionData::RESERVED_NAMES);
		$this->assertContains('views', CollectionData::RESERVED_NAMES);
	}
}
