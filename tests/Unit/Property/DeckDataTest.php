<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DeckData;

#[CoversClass(DeckData::class)]
final class DeckDataTest extends TestCase
{
	public function testCreatesValidDeckWithSimpleObjects(): void
	{
		$deck = [
			['name' => 'Card 1', 'value' => 'value1', 'category' => 'type1'],
			['name' => 'Card 2', 'value' => 'value2', 'category' => 'type2'],
			['name' => 'Card 3', 'value' => 'value3', 'category' => 'type1'],
		];

		$data = new DeckData($deck);
		$this->assertSame($deck, $data->deck);
		$this->assertSame($deck, $data->transform());
	}

	public function testCreatesEmptyDeck(): void
	{
		$data = new DeckData();
		$this->assertSame([], $data->deck);
		$this->assertSame([], $data->transform());
	}

	public function testAcceptsSimpleScalarValues(): void
	{
		$deck = [
			['id' => 1, 'name' => 'Item 1', 'active' => true],
			['id' => 2, 'name' => 'Item 2', 'active' => false],
			['id' => 3, 'name' => 'Item 3', 'price' => 29.99],
		];

		$data = new DeckData($deck);
		$this->assertSame($deck, $data->deck);
	}

	public function testRejectsNonListArrays(): void
	{
		$invalidDeck = [
			'card1' => ['name' => 'Card 1'],
			'card2' => ['name' => 'Card 2'],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a set of simple objects');
		new DeckData($invalidDeck);
	}

	public function testRejectsNonArrayItems(): void
	{
		$invalidDeck = [
			['name' => 'Card 1'],
			'invalid_string',
			['name' => 'Card 2'],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a set of simple objects');
		new DeckData($invalidDeck);
	}

	public function testRejectsNestedArrays(): void
	{
		$invalidDeck = [
			['name' => 'Card 1', 'nested' => ['invalid' => 'structure']],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a set of simple objects');
		new DeckData($invalidDeck);
	}

	public function testRejectsNestedObjects(): void
	{
		$invalidDeck = [
			['name' => 'Card 1', 'object' => (object)['invalid' => 'structure']],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a set of simple objects');
		new DeckData($invalidDeck);
	}

	public function testHandlesDangerousScalarValues(): void
	{
		$dangerousDeck = [
			['name' => '<script>alert("xss")</script>', 'type' => 'malicious'],
			['name' => '"; DROP TABLE cards; --', 'type' => 'sql_injection'],
			['name' => 'javascript:void(0)', 'type' => 'protocol_attack'],
			['name' => '${system("ls")}', 'type' => 'command_injection'],
		];

		// DeckData should accept but not sanitize - sanitization happens at display time
		$data = new DeckData($dangerousDeck);
		$this->assertSame($dangerousDeck, $data->deck);
		$this->assertStringContainsString('<script>', $data->deck[0]['name']);
		$this->assertStringContainsString('DROP TABLE', $data->deck[1]['name']);
	}

	public function testHandlesLargeDecks(): void
	{
		$largeDeck = [];
		for ($i = 0; $i < 1000; $i++) {
			$largeDeck[] = [
				'id'       => $i,
				'name'     => "Item {$i}",
				'category' => 'category_' . ($i % 10),
				'active'   => ($i % 2 === 0),
			];
		}

		$data = new DeckData($largeDeck);
		$this->assertCount(1000, $data->deck);
		$this->assertSame($largeDeck, $data->transform());
	}

	public function testHandlesVariousScalarTypes(): void
	{
		$deck = [
			[
				'string'        => 'text',
				'integer'       => 42,
				'float'         => 3.14,
				'boolean_true'  => true,
				'boolean_false' => false,
			],
		];

		$data = new DeckData($deck);
		$this->assertSame($deck, $data->deck);
	}

	public function testRejectsNullValues(): void
	{
		$deckWithNull = [
			[
				'string'     => 'text',
				'null_value' => null, // null is not scalar
			],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a set of simple objects');
		new DeckData($deckWithNull);
	}

	public function testHandlesUnicodeContent(): void
	{
		$unicodeDeck = [
			['name' => 'Unicode: 世界', 'emoji' => '🌍🚀💫'],
			['name' => 'Français: café', 'accents' => 'àáâãäåæç'],
			['name' => 'Русский: мир', 'cyrillic' => 'абвгдеё'],
		];

		$data = new DeckData($unicodeDeck);
		$this->assertSame($unicodeDeck, $data->deck);
		$this->assertStringContainsString('世界', $data->deck[0]['name']);
		$this->assertStringContainsString('🌍', $data->deck[0]['emoji']);
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['validation' => 'strict', 'maxItems' => 100];
		$deck     = [['name' => 'Test']];

		$data = new DeckData($deck, $settings);
		$this->assertSame($settings, $data->settings);
	}

	public function testUsesEmptyArrayAsDefaultSettings(): void
	{
		$data = new DeckData();
		$this->assertSame([], $data->settings);
	}

	public function testHandlesEmptyObjects(): void
	{
		$deck = [
			[],
			['name' => 'Non-empty'],
			[],
		];

		$data = new DeckData($deck);
		$this->assertSame($deck, $data->deck);
		$this->assertCount(3, $data->deck);
	}

	public function testValidationPerformance(): void
	{
		// Test that validation doesn't cause performance issues
		$largeDeck = array_fill(0, 100, ['name' => 'Test', 'id' => 1]);

		$start = microtime(true);
		$data  = new DeckData($largeDeck);
		$time  = microtime(true) - $start;

		$this->assertLessThan(0.1, $time); // Should complete in under 100ms
		$this->assertCount(100, $data->deck);
	}

	public function testPathTraversalInDeckValues(): void
	{
		$pathTraversalDeck = [
			['path' => '../../../etc/passwd', 'type' => 'traversal'],
			['path' => '..\\..\\..\\windows\\system32\\hosts', 'type' => 'windows_traversal'],
			['path' => '/etc/shadow', 'type' => 'absolute_path'],
		];

		$data = new DeckData($pathTraversalDeck);
		// DeckData stores values as-is, path validation should happen elsewhere
		$this->assertSame($pathTraversalDeck, $data->deck);
		$this->assertStringContainsString('../../../etc/passwd', $data->deck[0]['path']);
	}
}
