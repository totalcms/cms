<?php

namespace Tests\Unit\Domain\Template\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Template\Data\DesignerMetadata;

final class DesignerMetadataTest extends TestCase
{
	public function testDefaultValues(): void
	{
		$meta = new DesignerMetadata();

		$this->assertFalse($meta->designerEnabled);
		$this->assertSame('', $meta->designerToken);
	}

	public function testToArray(): void
	{
		$meta = new DesignerMetadata();
		$meta->designerEnabled = true;
		$meta->designerToken = 'abc123';

		$result = $meta->toArray();

		$this->assertSame([
			'designerEnabled' => true,
			'designerToken'   => 'abc123',
		], $result);
	}

	public function testFromArray(): void
	{
		$meta = DesignerMetadata::fromArray([
			'designerEnabled' => true,
			'designerToken'   => 'token-xyz',
		]);

		$this->assertTrue($meta->designerEnabled);
		$this->assertSame('token-xyz', $meta->designerToken);
	}

	public function testFromArrayWithDefaults(): void
	{
		$meta = DesignerMetadata::fromArray([]);

		$this->assertFalse($meta->designerEnabled);
		$this->assertSame('', $meta->designerToken);
	}

	public function testFromArrayCastsTypes(): void
	{
		$meta = DesignerMetadata::fromArray([
			'designerEnabled' => 1,
			'designerToken'   => 12345,
		]);

		$this->assertTrue($meta->designerEnabled);
		$this->assertSame('12345', $meta->designerToken);
	}

	public function testRoundTrip(): void
	{
		$original = new DesignerMetadata();
		$original->designerEnabled = true;
		$original->designerToken = 'round-trip-token';

		$restored = DesignerMetadata::fromArray($original->toArray());

		$this->assertSame($original->designerEnabled, $restored->designerEnabled);
		$this->assertSame($original->designerToken, $restored->designerToken);
	}
}
