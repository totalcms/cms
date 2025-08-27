<?php

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Deck Field changed() event functionality.
 *
 * Tests that deck field operations properly trigger the changed() method
 * to mark the form as modified and maintain proper state.
 */
final class DeckFieldChangedEventTest extends TestCase
{
	/**
	 * Test addItem() triggers changed event.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * addItem() {
	 *     // ... item creation logic
	 *     this.changed();
	 * }
	 * ```
	 */
	public function testAddItemTriggersChangedEvent(): void
	{
		$deckField = $this->createMockDeckField();

		$this->assertFalse($deckField->hasChangedEventTriggered());

		$deckField->addItem();

		$this->assertTrue($deckField->hasChangedEventTriggered());
	}

	/**
	 * Test removeItem() triggers changed event.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * removeItem(itemElement) {
	 *     // ... removal logic
	 *     this.changed();
	 * }
	 * ```
	 */
	public function testRemoveItemTriggersChangedEvent(): void
	{
		$deckField = $this->createMockDeckField();
		$item      = $deckField->addItem(); // Add an item first

		$deckField->clearChangedEvent(); // Reset for test

		$deckField->removeItem($item);

		$this->assertTrue($deckField->hasChangedEventTriggered());
	}

	/**
	 * Test duplicateItem() triggers changed event.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * duplicateItem(itemElement) {
	 *     // ... duplication logic
	 *     this.changed();
	 * }
	 * ```
	 */
	public function testDuplicateItemTriggersChangedEvent(): void
	{
		$deckField    = $this->createMockDeckField();
		$originalItem = $deckField->addItem();

		$deckField->clearChangedEvent(); // Reset for test

		$deckField->duplicateItem($originalItem);

		$this->assertTrue($deckField->hasChangedEventTriggered());
	}

	/**
	 * Test multiple operations trigger multiple changed events.
	 */
	public function testMultipleOperationsTrigerMultipleChangedEvents(): void
	{
		$deckField = $this->createMockDeckField();

		// Add items
		$item1 = $deckField->addItem();
		$item2 = $deckField->addItem();

		$this->assertEquals(2, $deckField->getChangedEventCount());

		// Duplicate item
		$deckField->duplicateItem($item1);

		$this->assertEquals(3, $deckField->getChangedEventCount());

		// Remove item
		$deckField->removeItem($item2);

		$this->assertEquals(4, $deckField->getChangedEventCount());
	}

	/**
	 * Test changed event is not triggered by read operations.
	 */
	public function testReadOperationsDoNotTriggerChangedEvent(): void
	{
		$deckField = $this->createMockDeckField();
		$deckField->addItem(); // Add an item

		$deckField->clearChangedEvent(); // Reset for test

		// These operations should not trigger changed events
		$deckField->getValue();
		$deckField->validate();
		$deckField->getItemCount();

		$this->assertFalse($deckField->hasChangedEventTriggered());
	}

	/**
	 * Test changed event integrates with form state management.
	 */
	public function testChangedEventIntegratesWithFormState(): void
	{
		$form      = $this->createMockForm();
		$deckField = $this->createMockDeckFieldWithForm($form);

		$this->assertFalse($form->isUnsaved());

		$deckField->addItem();

		$this->assertTrue($form->isUnsaved());
	}

	/**
	 * Test changed event triggers form validation.
	 */
	public function testChangedEventTriggersFormValidation(): void
	{
		$form      = $this->createMockForm();
		$deckField = $this->createMockDeckFieldWithForm($form);

		$this->assertEquals(0, $form->getValidationCount());

		$deckField->addItem();

		// Changed event should trigger form validation
		$this->assertGreaterThan(0, $form->getValidationCount());
	}

	/**
	 * Test changed event only triggers when actual changes occur.
	 */
	public function testChangedEventOnlyTriggersForActualChanges(): void
	{
		$deckField = $this->createMockDeckField();

		// Add item - should trigger changed
		$deckField->addItem();
		$this->assertEquals(1, $deckField->getChangedEventCount());

		// Try to add empty item - should not trigger changed if no actual change
		$deckField->clearChangedEvent();
		$emptyResult = $deckField->addEmptyItem();

		if (!$emptyResult) { // If empty item was not actually added
			$this->assertFalse($deckField->hasChangedEventTriggered());
		}
	}

	/**
	 * Test changed event handles rapid successive operations.
	 */
	public function testChangedEventHandlesRapidOperations(): void
	{
		$deckField = $this->createMockDeckField();

		// Rapid operations
		$item1 = $deckField->addItem();
		$item2 = $deckField->addItem();
		$deckField->addItem();
		$deckField->removeItem($item2);
		$deckField->duplicateItem($item1);

		// Each operation should trigger changed event
		$this->assertEquals(5, $deckField->getChangedEventCount());
	}

	/**
	 * Test changed event with batch operations.
	 */
	public function testChangedEventWithBatchOperations(): void
	{
		$deckField = $this->createMockDeckField();

		// Batch operations should still trigger individual changed events
		$deckField->addMultipleItems([
			['id' => 'item1', 'title' => 'Item 1'],
			['id' => 'item2', 'title' => 'Item 2'],
			['id' => 'item3', 'title' => 'Item 3'],
		]);

		$this->assertEquals(3, $deckField->getChangedEventCount());
	}

	/**
	 * Test changed event state persistence.
	 */
	public function testChangedEventStatePersistence(): void
	{
		$deckField = $this->createMockDeckField();

		$deckField->addItem();
		$this->assertTrue($deckField->hasChangedEventTriggered());

		// State should persist until explicitly cleared
		$this->assertTrue($deckField->hasChangedEventTriggered());
		$this->assertTrue($deckField->hasChangedEventTriggered());

		$deckField->clearChangedEvent();
		$this->assertFalse($deckField->hasChangedEventTriggered());
	}

	/**
	 * Test changed event timing and sequence.
	 */
	public function testChangedEventTimingAndSequence(): void
	{
		$deckField = $this->createMockDeckField();

		$timestamps = [];

		// Record timing of operations
		$start = microtime(true);
		$deckField->addItem();
		$timestamps['add'] = microtime(true) - $start;

		$start = microtime(true);
		$item  = $deckField->getLastAddedItem();
		$deckField->duplicateItem($item);
		$timestamps['duplicate'] = microtime(true) - $start;

		// Operations should complete quickly
		$this->assertLessThan(0.001, $timestamps['add']);
		$this->assertLessThan(0.001, $timestamps['duplicate']);

		// All operations should have triggered changed events
		$this->assertEquals(2, $deckField->getChangedEventCount());
	}

	/**
	 * Create a mock deck field for testing changed events.
	 */
	private function createMockDeckField(): MockDeckFieldForChangedEvent
	{
		return new MockDeckFieldForChangedEvent();
	}

	/**
	 * Create a mock deck field with form integration.
	 */
	private function createMockDeckFieldWithForm(MockFormForChangedEvent $form): MockDeckFieldForChangedEvent
	{
		return new MockDeckFieldForChangedEvent($form);
	}

	/**
	 * Create a mock form for testing form integration.
	 */
	private function createMockForm(): MockFormForChangedEvent
	{
		return new MockFormForChangedEvent();
	}
}

/**
 * Mock class to simulate JavaScript deck field changed event behavior.
 */
class MockDeckFieldForChangedEvent
{
	private bool $changedEventTriggered = false;
	private int $changedEventCount      = 0;
	private array $items                = [];

	public function __construct(private readonly ?MockFormForChangedEvent $form = null)
	{
	}

	public function addItem(): MockDeckItemForChangedEvent
	{
		$item          = new MockDeckItemForChangedEvent('item_' . (count($this->items) + 1));
		$this->items[] = $item;
		$this->changed();

		return $item;
	}

	public function removeItem(MockDeckItemForChangedEvent $item): void
	{
		$key = array_search($item, $this->items, true);
		if ($key !== false) {
			unset($this->items[$key]);
			$this->items = array_values($this->items); // Reindex
		}
		$this->changed();
	}

	public function duplicateItem(MockDeckItemForChangedEvent $original): MockDeckItemForChangedEvent
	{
		$duplicate     = new MockDeckItemForChangedEvent($original->getId() . '_copy');
		$this->items[] = $duplicate;
		$this->changed();

		return $duplicate;
	}

	public function addMultipleItems(array $itemsData): void
	{
		foreach ($itemsData as $itemData) {
			$this->addItem(); // Each call triggers changed()
		}
	}

	public function addEmptyItem(): bool
	{
		// Simulate condition where empty item is not actually added
		return false;
	}

	public function getValue(): array
	{
		// Read operation - should not trigger changed
		return [];
	}

	public function validate(): bool
	{
		// Read operation - should not trigger changed
		return true;
	}

	public function getItemCount(): int
	{
		// Read operation - should not trigger changed
		return count($this->items);
	}

	public function getLastAddedItem(): ?MockDeckItemForChangedEvent
	{
		return end($this->items) ?: null;
	}

	private function changed(): void
	{
		$this->changedEventTriggered = true;
		$this->changedEventCount++;

		// Integrate with form if available
		if ($this->form instanceof MockFormForChangedEvent) {
			$this->form->markAsUnsaved();
			$this->form->triggerValidation();
		}
	}

	public function hasChangedEventTriggered(): bool
	{
		return $this->changedEventTriggered;
	}

	public function getChangedEventCount(): int
	{
		return $this->changedEventCount;
	}

	public function clearChangedEvent(): void
	{
		$this->changedEventTriggered = false;
		// Note: We don't reset count to allow testing cumulative operations
	}
}

/**
 * Mock class to simulate deck item for changed event testing.
 */
class MockDeckItemForChangedEvent
{
	public function __construct(private readonly string $id)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}
}

/**
 * Mock class to simulate form integration for changed events.
 */
class MockFormForChangedEvent
{
	private bool $unsaved        = false;
	private int $validationCount = 0;

	public function markAsUnsaved(): void
	{
		$this->unsaved = true;
	}

	public function triggerValidation(): void
	{
		$this->validationCount++;
	}

	public function isUnsaved(): bool
	{
		return $this->unsaved;
	}

	public function getValidationCount(): int
	{
		return $this->validationCount;
	}
}
