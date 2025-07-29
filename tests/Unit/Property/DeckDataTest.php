<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DeckData;

#[CoversClass(DeckData::class)]
final class DeckDataTest extends TestCase
{
	public function testCreatesValidDeckWithNamedObjects(): void
	{
		$deck = [
			'feature1' => ['id' => 'feature1', 'title' => 'Fast Performance', 'icon' => 'speed', 'description' => 'Lightning fast'],
			'feature2' => ['id' => 'feature2', 'title' => 'Secure', 'icon' => 'lock', 'description' => 'Industry standard'],
			'feature3' => ['id' => 'feature3', 'title' => 'Scalable', 'icon' => 'scale', 'description' => 'Grows with you'],
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

	public function testAcceptsVariousScalarValues(): void
	{
		$deck = [
			'item1' => ['id' => 'item1', 'sequence' => 1, 'name' => 'Item 1', 'active' => true],
			'item2' => ['id' => 'item2', 'sequence' => 2, 'name' => 'Item 2', 'active' => false],
			'item3' => ['id' => 'item3', 'sequence' => 3, 'name' => 'Item 3', 'price' => 29.99],
		];

		$data = new DeckData($deck);
		$this->assertSame($deck, $data->deck);
	}

	public function testRejectsListArrays(): void
	{
		$invalidDeck = [
			['name' => 'Card 1'],
			['name' => 'Card 2'],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a dictionary of named objects');
		new DeckData($invalidDeck);
	}

	public function testRejectsInvalidNames(): void
	{
		$invalidDeck = [
			'123invalid' => ['name' => 'Invalid Name'],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a dictionary of named objects');
		new DeckData($invalidDeck);
	}

	public function testRejectsNonArrayItems(): void
	{
		$invalidDeck = [
			'valid'   => ['name' => 'Valid'],
			'invalid' => 'not_an_array',
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a dictionary of named objects');
		new DeckData($invalidDeck);
	}

	public function testAcceptsNestedArrays(): void
	{
		$validDeck = [
			'item' => ['name' => 'Item', 'nested' => ['valid' => 'structure']],
		];

		$data = new DeckData($validDeck);
		$this->assertSame($validDeck, $data->deck);
	}

	public function testAcceptsNestedObjects(): void
	{
		$validDeck = [
			'item' => ['name' => 'Item', 'object' => (object)['valid' => 'structure']],
		];

		$data = new DeckData($validDeck);
		$this->assertSame($validDeck, $data->deck);
	}

	public function testAcceptsNullValues(): void
	{
		$deckWithNull = [
			'item' => [
				'string'     => 'text',
				'null_value' => null,
			],
		];

		$data = new DeckData($deckWithNull);
		$this->assertSame($deckWithNull, $data->deck);
	}

	public function testHandlesDangerousScalarValues(): void
	{
		$dangerousDeck = [
			'malicious' => ['name' => '<script>alert("xss")</script>', 'type' => 'malicious'],
			'injection' => ['name' => '"; DROP TABLE cards; --', 'type' => 'sql_injection'],
			'protocol'  => ['name' => 'javascript:void(0)', 'type' => 'protocol_attack'],
			'command'   => ['name' => '${system("ls")}', 'type' => 'command_injection'],
		];

		$data = new DeckData($dangerousDeck);
		$this->assertSame($dangerousDeck, $data->deck);
		$this->assertStringContainsString('<script>', $data->deck['malicious']['name']);
		$this->assertStringContainsString('DROP TABLE', $data->deck['injection']['name']);
	}

	public function testHandlesLargeDecks(): void
	{
		$largeDeck = [];
		for ($i = 0; $i < 1000; $i++) {
			$largeDeck["item{$i}"] = [
				'id'       => "item{$i}",
				'sequence' => $i,
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
			'mixed' => [
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

	public function testHandlesUnicodeContent(): void
	{
		$unicodeDeck = [
			'unicode' => ['name' => 'Unicode: 世界', 'emoji' => '🌍🚀💫'],
			'french'  => ['name' => 'Français: café', 'accents' => 'àáâãäåæç'],
			'russian' => ['name' => 'Русский: мир', 'cyrillic' => 'абвгдеё'],
		];

		$data = new DeckData($unicodeDeck);
		$this->assertSame($unicodeDeck, $data->deck);
		$this->assertStringContainsString('世界', $data->deck['unicode']['name']);
		$this->assertStringContainsString('🌍', $data->deck['unicode']['emoji']);
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['validation' => 'strict', 'maxItems' => 100];
		$deck     = ['test' => ['name' => 'Test']];

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
			'empty1'   => [],
			'nonempty' => ['name' => 'Non-empty'],
			'empty2'   => [],
		];

		$data = new DeckData($deck);
		$this->assertSame($deck, $data->deck);
		$this->assertCount(3, $data->deck);
	}

	public function testValidationPerformance(): void
	{
		$largeDeck = [];
		for ($i = 0; $i < 100; $i++) {
			$largeDeck["item{$i}"] = ['id' => "item{$i}", 'name' => 'Test', 'sequence' => $i];
		}

		$start = microtime(true);
		$data  = new DeckData($largeDeck);
		$time  = microtime(true) - $start;

		$this->assertLessThan(0.1, $time);
		$this->assertCount(100, $data->deck);
	}

	public function testPathTraversalInDeckValues(): void
	{
		$pathTraversalDeck = [
			'traversal' => ['path' => '../../../etc/passwd', 'type' => 'traversal'],
			'windows'   => ['path' => '..\\..\\..\\windows\\system32\\hosts', 'type' => 'windows_traversal'],
			'absolute'  => ['path' => '/etc/shadow', 'type' => 'absolute_path'],
		];

		$data = new DeckData($pathTraversalDeck);
		$this->assertSame($pathTraversalDeck, $data->deck);
		$this->assertStringContainsString('../../../etc/passwd', $data->deck['traversal']['path']);
	}

	public function testGetItem(): void
	{
		$deck = [
			'feature1' => ['title' => 'Fast', 'icon' => 'speed'],
			'feature2' => ['title' => 'Secure', 'icon' => 'lock'],
		];

		$data = new DeckData($deck);
		$this->assertSame(['title' => 'Fast', 'icon' => 'speed'], $data->getItem('feature1'));
		$this->assertSame(['title' => 'Secure', 'icon' => 'lock'], $data->getItem('feature2'));
		$this->assertNull($data->getItem('nonexistent'));
	}

	public function testSetItem(): void
	{
		$data = new DeckData();
		$item = ['title' => 'New Feature', 'icon' => 'new'];

		$data->setItem('newFeature', $item);
		$this->assertSame($item, $data->getItem('newFeature'));
		$this->assertTrue($data->hasItem('newFeature'));
	}

	public function testSetItemRejectsInvalidName(): void
	{
		$data = new DeckData();
		$item = ['title' => 'Feature'];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck item name must be a valid identifier');
		$data->setItem('123invalid', $item);
	}

	public function testSetItemAcceptsNonScalarValues(): void
	{
		$data = new DeckData();
		$item = ['title' => 'Feature', 'nested' => ['valid' => 'structure']];

		$data->setItem('feature', $item);
		$this->assertSame($item, $data->getItem('feature'));
	}

	public function testRemoveItem(): void
	{
		$deck = [
			'feature1' => ['title' => 'Fast'],
			'feature2' => ['title' => 'Secure'],
		];

		$data = new DeckData($deck);
		$this->assertTrue($data->hasItem('feature1'));

		$data->removeItem('feature1');
		$this->assertFalse($data->hasItem('feature1'));
		$this->assertTrue($data->hasItem('feature2'));
	}

	public function testGetItemNames(): void
	{
		$deck = [
			'feature1' => ['title' => 'Fast'],
			'feature2' => ['title' => 'Secure'],
			'feature3' => ['title' => 'Scalable'],
		];

		$data  = new DeckData($deck);
		$names = $data->getItemNames();

		$this->assertSame(['feature1', 'feature2', 'feature3'], $names);
	}

	public function testHasItem(): void
	{
		$deck = ['feature1' => ['title' => 'Fast']];
		$data = new DeckData($deck);

		$this->assertTrue($data->hasItem('feature1'));
		$this->assertFalse($data->hasItem('nonexistent'));
	}

	public function testCount(): void
	{
		$deck = [
			'feature1' => ['title' => 'Fast'],
			'feature2' => ['title' => 'Secure'],
			'feature3' => ['title' => 'Scalable'],
		];

		$data = new DeckData($deck);
		$this->assertSame(3, $data->count());

		$data->removeItem('feature2');
		$this->assertSame(2, $data->count());
	}

	public function testValidNamePatterns(): void
	{
		$validNames = [
			'feature1'         => ['title' => 'Feature 1'],
			'feature_2'        => ['title' => 'Feature 2'],
			'feature3'         => ['title' => 'Feature 3'],
			'FeatureCamelCase' => ['title' => 'Feature Camel'],
			'f'                => ['title' => 'Single Letter'],
		];

		$data = new DeckData($validNames);
		$this->assertCount(5, $data->deck);
	}

	public function testInvalidNamePatterns(): void
	{
		$invalidNames = [
			'123feature' => ['title' => 'Starts with number'],
		];

		$this->expectException(\InvalidArgumentException::class);
		new DeckData($invalidNames);
	}

	public function testRejectsDashesInNames(): void
	{
		$invalidNames = [
			'feature-with-dash' => ['title' => 'Contains dashes'],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a dictionary of named objects');
		new DeckData($invalidNames);
	}

	public function testRejectsInconsistentIds(): void
	{
		$inconsistentDeck = [
			'feature1' => ['id' => 'feature2', 'title' => 'Mismatched ID'], // ID doesn't match key
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Deck must be a dictionary of named objects');
		new DeckData($inconsistentDeck);
	}

	public function testAcceptsItemsWithMatchingIds(): void
	{
		$consistentDeck = [
			'feature1' => ['id' => 'feature1', 'title' => 'Matching ID'],
			'feature2' => ['id' => 'feature2', 'title' => 'Another Matching ID'],
		];

		$data = new DeckData($consistentDeck);
		$this->assertSame($consistentDeck, $data->deck);
	}

	public function testAcceptsItemsWithoutIds(): void
	{
		$deckWithoutIds = [
			'feature1' => ['title' => 'No ID field'],
			'feature2' => ['title' => 'Another without ID'],
		];

		$data = new DeckData($deckWithoutIds);
		$this->assertSame($deckWithoutIds, $data->deck);
	}

	public function testSetItemRejectsInconsistentId(): void
	{
		$data = new DeckData();
		$item = ['id' => 'wrong_id', 'title' => 'Feature'];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("Deck item 'id' field ('wrong_id') must match the dictionary key ('correct_id')");
		$data->setItem('correct_id', $item);
	}

	public function testSetItemAcceptsMatchingId(): void
	{
		$data = new DeckData();
		$item = ['id' => 'feature1', 'title' => 'Feature'];

		$data->setItem('feature1', $item);
		$this->assertSame($item, $data->getItem('feature1'));
	}

	public function testSetItemAcceptsItemWithoutId(): void
	{
		$data = new DeckData();
		$item = ['title' => 'Feature without ID'];

		$data->setItem('feature1', $item);
		$this->assertSame($item, $data->getItem('feature1'));
	}
}
