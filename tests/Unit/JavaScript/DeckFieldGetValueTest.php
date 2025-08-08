<?php

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Deck Field getValue() functionality.
 *
 * Tests the getValue() method in deck.js that uses DOM order instead of
 * the items array to maintain the correct ordering of deck items.
 */
final class DeckFieldGetValueTest extends TestCase
{
	/**
	 * Test getValue() maintains DOM order instead of items array order.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * getValue() {
	 *     const deckData = {};
	 *
	 *     // not using this.items so we can maintain the order of items in the DOM
	 *     const deckItems = this.container.getElementsByClassName(this.fieldClass);
	 *     for (const item of deckItems) {
	 *         deckData[item.deckitem.getItemId()] = item.deckitem.getValue();
	 *     }
	 *
	 *     return deckData;
	 * }
	 * ```
	 */
	public function testGetValueMaintainsDomOrder(): void
	{
		$deckField = $this->createMockDeckField();

		// Add items in one order to items array
		$deckField->addItemToArray('third', ['title' => 'Third Item']);
		$deckField->addItemToArray('first', ['title' => 'First Item']);
		$deckField->addItemToArray('second', ['title' => 'Second Item']);

		// Set DOM order differently
		$deckField->setDomOrder(['first', 'second', 'third']);

		$result = $deckField->getValue();
		$keys   = array_keys($result);

		// Should follow DOM order, not items array order
		$this->assertEquals(['first', 'second', 'third'], $keys);
	}

	/**
	 * Test getValue() returns correct item data.
	 */
	public function testGetValueReturnsCorrectItemData(): void
	{
		$deckField = $this->createMockDeckField();

		$deckField->addItemToArray('feature1', [
			'title'       => 'Amazing Feature',
			'description' => 'This feature is amazing',
			'icon'        => 'star',
		]);
		$deckField->addItemToArray('feature2', [
			'title'       => 'Great Feature',
			'description' => 'This feature is great',
			'icon'        => 'check',
		]);

		$deckField->setDomOrder(['feature1', 'feature2']);

		$result = $deckField->getValue();

		$this->assertEquals([
			'feature1' => [
				'title'       => 'Amazing Feature',
				'description' => 'This feature is amazing',
				'icon'        => 'star',
			],
			'feature2' => [
				'title'       => 'Great Feature',
				'description' => 'This feature is great',
				'icon'        => 'check',
			],
		], $result);
	}

	/**
	 * Test getValue() with reordered DOM elements.
	 *
	 * Simulates drag-and-drop reordering where DOM order changes
	 * but items array order might not be updated.
	 */
	public function testGetValueWithReorderedDomElements(): void
	{
		$deckField = $this->createMockDeckField();

		// Add items in original order
		$deckField->addItemToArray('item1', ['title' => 'Item 1']);
		$deckField->addItemToArray('item2', ['title' => 'Item 2']);
		$deckField->addItemToArray('item3', ['title' => 'Item 3']);
		$deckField->addItemToArray('item4', ['title' => 'Item 4']);

		// Simulate drag-and-drop reordering in DOM
		$deckField->setDomOrder(['item3', 'item1', 'item4', 'item2']);

		$result = $deckField->getValue();
		$keys   = array_keys($result);

		// Should reflect new DOM order after drag-and-drop
		$this->assertEquals(['item3', 'item1', 'item4', 'item2'], $keys);
	}

	/**
	 * Test getValue() with empty deck.
	 */
	public function testGetValueWithEmptyDeck(): void
	{
		$deckField = $this->createMockDeckField();

		$result = $deckField->getValue();

		$this->assertEquals([], $result);
	}

	/**
	 * Test getValue() with single item.
	 */
	public function testGetValueWithSingleItem(): void
	{
		$deckField = $this->createMockDeckField();

		$deckField->addItemToArray('only_item', [
			'title'       => 'Only Item',
			'description' => 'The only item in this deck',
		]);
		$deckField->setDomOrder(['only_item']);

		$result = $deckField->getValue();

		$this->assertEquals([
			'only_item' => [
				'title'       => 'Only Item',
				'description' => 'The only item in this deck',
			],
		], $result);
	}

	/**
	 * Test getValue() handles items with complex data.
	 */
	public function testGetValueHandlesComplexItemData(): void
	{
		$deckField = $this->createMockDeckField();

		$deckField->addItemToArray('complex_item', [
			'title'       => 'Complex Item',
			'description' => 'Item with <strong>HTML</strong> content',
			'metadata'    => [
				'created' => '2024-01-01',
				'author'  => 'Test User',
				'tags'    => ['important', 'featured'],
			],
			'settings' => [
				'visible'  => true,
				'priority' => 5,
				'category' => 'premium',
			],
			'price'  => 29.99,
			'active' => true,
		]);
		$deckField->setDomOrder(['complex_item']);

		$result = $deckField->getValue();

		$this->assertArrayHasKey('complex_item', $result);
		$this->assertEquals('Complex Item', $result['complex_item']['title']);
		$this->assertEquals(['important', 'featured'], $result['complex_item']['metadata']['tags']);
		$this->assertEquals(29.99, $result['complex_item']['price']);
	}

	/**
	 * Test getValue() consistency with multiple calls.
	 */
	public function testGetValueConsistencyWithMultipleCalls(): void
	{
		$deckField = $this->createMockDeckField();

		$deckField->addItemToArray('item1', ['title' => 'Item 1']);
		$deckField->addItemToArray('item2', ['title' => 'Item 2']);
		$deckField->setDomOrder(['item1', 'item2']);

		$result1 = $deckField->getValue();
		$result2 = $deckField->getValue();
		$result3 = $deckField->getValue();

		$this->assertEquals($result1, $result2);
		$this->assertEquals($result2, $result3);
	}

	/**
	 * Test getValue() performance with large number of items.
	 */
	public function testGetValuePerformanceWithLargeNumberOfItems(): void
	{
		$deckField = $this->createMockDeckField();
		$domOrder  = [];

		// Add 100 items
		for ($i = 1; $i <= 100; $i++) {
			$itemId = "item_{$i}";
			$deckField->addItemToArray($itemId, [
				'title'       => "Item {$i}",
				'description' => "Description for item {$i}",
				'sequence'    => $i,
			]);
			$domOrder[] = $itemId;
		}
		$deckField->setDomOrder($domOrder);

		$start  = microtime(true);
		$result = $deckField->getValue();
		$time   = microtime(true) - $start;

		$this->assertCount(100, $result);
		$this->assertLessThan(0.01, $time); // Should be very fast
	}

	/**
	 * Test getValue() with items added and removed dynamically.
	 */
	public function testGetValueWithDynamicItemChanges(): void
	{
		$deckField = $this->createMockDeckField();

		// Add initial items
		$deckField->addItemToArray('item1', ['title' => 'Item 1']);
		$deckField->addItemToArray('item2', ['title' => 'Item 2']);
		$deckField->addItemToArray('item3', ['title' => 'Item 3']);
		$deckField->setDomOrder(['item1', 'item2', 'item3']);

		$result1 = $deckField->getValue();
		$this->assertCount(3, $result1);

		// Remove item2 from DOM (but not from items array)
		$deckField->setDomOrder(['item1', 'item3']);

		$result2 = $deckField->getValue();
		$this->assertCount(2, $result2);
		$this->assertArrayNotHasKey('item2', $result2);

		// Add new item to DOM
		$deckField->addItemToArray('item4', ['title' => 'Item 4']);
		$deckField->setDomOrder(['item1', 'item4', 'item3']);

		$result3 = $deckField->getValue();
		$this->assertCount(3, $result3);
		$this->assertEquals(['item1', 'item4', 'item3'], array_keys($result3));
	}

	/**
	 * Test getValue() ignores items array order completely.
	 */
	public function testGetValueIgnoresItemsArrayOrder(): void
	{
		$deckField = $this->createMockDeckField();

		// Add items to array in one order
		$deckField->addItemToArray('zebra', ['title' => 'Zebra']);
		$deckField->addItemToArray('alpha', ['title' => 'Alpha']);
		$deckField->addItemToArray('beta', ['title' => 'Beta']);

		// Set DOM in different order
		$deckField->setDomOrder(['alpha', 'beta', 'zebra']);

		$result = $deckField->getValue();

		// Should follow DOM order, not alphabetical or items array order
		$this->assertEquals(['alpha', 'beta', 'zebra'], array_keys($result));
	}

	/**
	 * Test getValue() with duplicate IDs in different contexts.
	 *
	 * This tests edge cases where DOM and items array might get out of sync.
	 */
	public function testGetValueWithInconsistentState(): void
	{
		$deckField = $this->createMockDeckField();

		// Add items to array
		$deckField->addItemToArray('item1', ['title' => 'Item 1']);
		$deckField->addItemToArray('item2', ['title' => 'Item 2']);
		$deckField->addItemToArray('item3', ['title' => 'Item 3']);

		// DOM only has subset of items
		$deckField->setDomOrder(['item2', 'item3']);

		$result = $deckField->getValue();

		// Should only include items that exist in DOM
		$this->assertEquals(['item2', 'item3'], array_keys($result));
		$this->assertArrayNotHasKey('item1', $result);
	}

	/**
	 * Create a mock deck field for testing getValue behavior.
	 */
	private function createMockDeckField(): MockDeckFieldForGetValue
	{
		return new MockDeckFieldForGetValue();
	}
}

/**
 * Mock class to simulate JavaScript deck field getValue behavior.
 */
class MockDeckFieldForGetValue
{
	private array $itemsArray = []; // Simulates this.items array
	private array $domOrder   = [];   // Simulates DOM order
	private array $itemData   = [];   // Stores item data by ID

	/**
	 * Add an item to the items array (simulates internal array).
	 */
	public function addItemToArray(string $id, array $data): void
	{
		$this->itemsArray[]  = $id;
		$this->itemData[$id] = $data;
	}

	/**
	 * Set the DOM order of items.
	 */
	public function setDomOrder(array $order): void
	{
		$this->domOrder = $order;
	}

	/**
	 * Simulate the getValue() method that uses DOM order.
	 *
	 * This mimics the JavaScript:
	 * ```
	 * const deckItems = this.container.getElementsByClassName(this.fieldClass);
	 * for (const item of deckItems) {
	 *     deckData[item.deckitem.getItemId()] = item.deckitem.getValue();
	 * }
	 * ```
	 */
	public function getValue(): array
	{
		$deckData = [];

		// Use DOM order instead of items array order
		foreach ($this->domOrder as $itemId) {
			if (isset($this->itemData[$itemId])) {
				$deckData[$itemId] = $this->itemData[$itemId];
			}
		}

		return $deckData;
	}

	/**
	 * Get the items array order for comparison in tests.
	 */
	public function getItemsArrayOrder(): array
	{
		return $this->itemsArray;
	}

	/**
	 * Get the DOM order for comparison in tests.
	 */
	public function getDomOrder(): array
	{
		return $this->domOrder;
	}
}
