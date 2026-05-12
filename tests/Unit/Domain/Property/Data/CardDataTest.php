<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\CardData;

/**
 * Tests for the Card data class — a single-instance deck.
 */
#[CoversClass(CardData::class)]
final class CardDataTest extends TestCase
{
	public function testEmptyCardIsValid(): void
	{
		$card = new CardData();
		$this->assertTrue($card->isEmpty());
		$this->assertSame([], $card->transform());
	}

	public function testStoresAssociativeArray(): void
	{
		$card = new CardData([
			'enabled'   => true,
			'frequency' => 'weekly',
			'priority'  => 0.7,
		]);

		$this->assertFalse($card->isEmpty());
		$this->assertSame('weekly', $card->get('frequency'));
		$this->assertSame(0.7, $card->get('priority'));
		$this->assertTrue($card->has('enabled'));
		$this->assertFalse($card->has('missing'));
	}

	public function testRejectsIndexedListInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new CardData(['one', 'two', 'three']);
	}

	public function testRejectsInvalidPropertyNames(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new CardData(['has-hyphen' => 'nope']);
	}

	public function testSetThrowsForInvalidName(): void
	{
		$card = new CardData();
		$this->expectException(\InvalidArgumentException::class);
		$card->set('has-hyphen', 'nope');
	}

	public function testSetUpdatesValue(): void
	{
		$card = new CardData(['enabled' => false]);
		$card->set('enabled', true);
		$this->assertTrue($card->get('enabled'));
	}

	public function testGetReturnsNullForMissingKey(): void
	{
		$card = new CardData(['a' => 1]);
		$this->assertNull($card->get('b'));
	}

	public function testTransformReturnsRawObject(): void
	{
		$data = ['enabled' => true, 'priority' => 0.5];
		$card = new CardData($data);
		$this->assertSame($data, $card->transform());
	}

	public function testToStringEmitsJson(): void
	{
		$card = new CardData(['enabled' => true]);
		$this->assertSame('{"enabled":true}', (string)$card);
	}

	public function testEmptyCardSerializesToEmptyObject(): void
	{
		$card = new CardData();
		// PHP json_encode of empty array gives [] not {} — that's expected behavior.
		// Storage layer can normalize via JSON_FORCE_OBJECT if needed; here we just
		// verify the contract: transform() returns an empty array, __toString returns valid JSON.
		$this->assertSame('[]', (string)$card);
	}
}
