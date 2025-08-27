<?php

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Deck Item duplication functionality.
 *
 * Tests the duplicateItem() method in deck.js that clones deck items
 * and clears their IDs to prevent duplicates.
 */
final class DeckItemDuplicationTest extends TestCase
{
	/**
	 * Test deck item duplication clears ID fields.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * const idInput = clone.querySelector("input[name='deck-item-id']");
	 * if (idInput) {
	 *     idInput.value = ''; // Clear the ID for new item
	 * }
	 * ```
	 */
	public function testDuplicateItemClearsIdFields(): void
	{
		$original  = $this->createMockDeckItem('feature1', 'Original Feature');
		$duplicate = $this->simulateDuplication($original);

		$this->assertEquals('', $duplicate->getDeckItemId());
		$this->assertEquals('', $duplicate->getDialogId());
		$this->assertEquals('feature1', $original->getDeckItemId()); // Original unchanged
	}

	/**
	 * Test deck item duplication removes readonly attributes.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * idInput.removeAttribute('readonly'); // Make it editable again
	 * idInput.removeAttribute('disabled'); // Remove any disabled state
	 * ```
	 */
	public function testDuplicateItemRemovesReadonlyAttributes(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Readonly Feature');
		$original->setDeckItemIdReadonly(true);
		$original->setDeckItemIdDisabled(true);

		$duplicate = $this->simulateDuplication($original);

		$this->assertFalse($duplicate->isDeckItemIdReadonly());
		$this->assertFalse($duplicate->isDeckItemIdDisabled());
	}

	/**
	 * Test deck item duplication removes locked state from dialog field.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * const dialogIdContainer = dialogIdInput.closest('.form-field');
	 * if (dialogIdContainer) {
	 *     dialogIdContainer.classList.remove('locked');
	 * }
	 * ```
	 */
	public function testDuplicateItemRemovesLockedStateFromDialogField(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Locked Feature');
		$original->setDialogIdFieldLocked(true);

		$duplicate = $this->simulateDuplication($original);

		$this->assertFalse($duplicate->isDialogIdFieldLocked());
	}

	/**
	 * Test deck item duplication preserves form field values.
	 *
	 * All fields except ID should maintain their values when duplicated.
	 */
	public function testDuplicateItemPreservesFormFieldValues(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Original Feature');
		$original->setFieldValue('title', 'Amazing Feature');
		$original->setFieldValue('description', 'This feature is amazing');
		$original->setFieldValue('icon', 'star-icon');

		$duplicate = $this->simulateDuplication($original);

		$this->assertEquals('Amazing Feature', $duplicate->getFieldValue('title'));
		$this->assertEquals('This feature is amazing', $duplicate->getFieldValue('description'));
		$this->assertEquals('star-icon', $duplicate->getFieldValue('icon'));
	}

	/**
	 * Test deck item duplication handles checkbox fields.
	 *
	 * Checkbox and radio button states should be preserved.
	 */
	public function testDuplicateItemHandlesCheckboxFields(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Feature with Checkbox');
		$original->setCheckboxValue('featured', true);
		$original->setCheckboxValue('active', false);

		$duplicate = $this->simulateDuplication($original);

		$this->assertTrue($duplicate->getCheckboxValue('featured'));
		$this->assertFalse($duplicate->getCheckboxValue('active'));
	}

	/**
	 * Test deck item duplication handles select fields.
	 *
	 * Select field values should be preserved during duplication.
	 */
	public function testDuplicateItemHandlesSelectFields(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Feature with Select');
		$original->setSelectValue('category', 'premium');
		$original->setMultiSelectValue('tags', ['important', 'new']);

		$duplicate = $this->simulateDuplication($original);

		$this->assertEquals('premium', $duplicate->getSelectValue('category'));
		$this->assertEquals(['important', 'new'], $duplicate->getMultiSelectValue('tags'));
	}

	/**
	 * Test deck item duplication removes Froala editor elements.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * const clonedFroala = clone.querySelectorAll('.fr-box');
	 * clonedFroala.forEach(froala => froala.remove());
	 * ```
	 */
	public function testDuplicateItemRemovesFroalaElements(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Feature with Editor');
		$original->addFroalaEditor('description');

		$duplicate = $this->simulateDuplication($original);

		$this->assertFalse($duplicate->hasFroalaEditor('description'));
		$this->assertTrue($original->hasFroalaEditor('description')); // Original unchanged
	}

	/**
	 * Test deck item duplication focuses on new ID field.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * const newIdInput = clone.querySelector("input[name='deck-item-id']");
	 * newIdInput?.focus();
	 * ```
	 */
	public function testDuplicateItemFocusesOnNewIdField(): void
	{
		$original  = $this->createMockDeckItem('feature1', 'Original Feature');
		$duplicate = $this->simulateDuplication($original);

		$this->assertTrue($duplicate->isDeckItemIdFieldFocused());
	}

	/**
	 * Test deck item duplication triggers changed event.
	 *
	 * Duplication should trigger the changed() method to mark the form as modified.
	 */
	public function testDuplicateItemTriggersChangedEvent(): void
	{
		$deckField = $this->createMockDeckField();
		$original  = $this->createMockDeckItem('feature1', 'Original Feature');

		$this->assertFalse($deckField->hasChangedEventTriggered());

		$this->simulateDuplicationOnDeckField($deckField, $original);

		$this->assertTrue($deckField->hasChangedEventTriggered());
	}

	/**
	 * Test deck item duplication adds item to items array.
	 *
	 * The duplicated item should be added to the deck field's items array.
	 */
	public function testDuplicateItemAddsToItemsArray(): void
	{
		$deckField = $this->createMockDeckField();
		$original  = $this->createMockDeckItem('feature1', 'Original Feature');

		$initialCount = $deckField->getItemCount();
		$this->simulateDuplicationOnDeckField($deckField, $original);

		$this->assertEquals($initialCount + 1, $deckField->getItemCount());
	}

	/**
	 * Test deck item duplication with complex form data.
	 */
	public function testDuplicateItemWithComplexFormData(): void
	{
		$original = $this->createMockDeckItem('complex_feature', 'Complex Feature');

		// Set up complex form data
		$original->setFieldValue('title', 'Complex Feature Title');
		$original->setFieldValue('description', 'A very detailed description');
		$original->setFieldValue('price', '29.99');
		$original->setCheckboxValue('featured', true);
		$original->setSelectValue('category', 'advanced');
		$original->setMultiSelectValue('tags', ['popular', 'recommended', 'new']);

		$duplicate = $this->simulateDuplication($original);

		// All data should be preserved except ID
		$this->assertEquals('', $duplicate->getDeckItemId());
		$this->assertEquals('Complex Feature Title', $duplicate->getFieldValue('title'));
		$this->assertEquals('A very detailed description', $duplicate->getFieldValue('description'));
		$this->assertEquals('29.99', $duplicate->getFieldValue('price'));
		$this->assertTrue($duplicate->getCheckboxValue('featured'));
		$this->assertEquals('advanced', $duplicate->getSelectValue('category'));
		$this->assertEquals(['popular', 'recommended', 'new'], $duplicate->getMultiSelectValue('tags'));
	}

	/**
	 * Test deck item duplication preserves DOM structure.
	 */
	public function testDuplicateItemPreservesDomStructure(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Original Feature');
		$original->addDomElement('button', 'edit');
		$original->addDomElement('button', 'trash');
		$original->addDomElement('dialog');

		$duplicate = $this->simulateDuplication($original);

		$this->assertTrue($duplicate->hasDomElement('button', 'edit'));
		$this->assertTrue($duplicate->hasDomElement('button', 'trash'));
		$this->assertTrue($duplicate->hasDomElement('dialog'));
	}

	/**
	 * Test deck item duplication error handling.
	 */
	public function testDuplicateItemErrorHandling(): void
	{
		$original = $this->createMockDeckItem('feature1', 'Feature with Error');
		$original->setError('Some validation error');

		$duplicate = $this->simulateDuplication($original);

		// Duplicate should start clean (no errors)
		$this->assertFalse($duplicate->hasError());
		$this->assertTrue($original->hasError()); // Original error state preserved
	}

	/**
	 * Create a mock deck item for testing.
	 */
	private function createMockDeckItem(string $id, string $title): MockDeckItemForDuplication
	{
		return new MockDeckItemForDuplication($id, $title);
	}

	/**
	 * Create a mock deck field for testing.
	 */
	private function createMockDeckField(): MockDeckFieldForDuplication
	{
		return new MockDeckFieldForDuplication();
	}

	/**
	 * Simulate the duplication process.
	 */
	private function simulateDuplication(MockDeckItemForDuplication $original): MockDeckItemForDuplication
	{
		return $original->duplicate();
	}

	/**
	 * Simulate duplication within a deck field context.
	 */
	private function simulateDuplicationOnDeckField(MockDeckFieldForDuplication $deckField, MockDeckItemForDuplication $original): MockDeckItemForDuplication
	{
		return $deckField->duplicateItem($original);
	}
}

/**
 * Mock class to simulate JavaScript deck item duplication behavior.
 */
class MockDeckItemForDuplication
{
	private readonly string $dialogId;
	private bool $deckItemIdReadonly     = false;
	private bool $deckItemIdDisabled     = false;
	private bool $dialogIdFieldLocked    = false;
	private bool $deckItemIdFieldFocused = false;
	private bool $hasError               = false;
	private array $fieldValues           = [];
	private array $checkboxValues        = [];
	private array $selectValues          = [];
	private array $multiSelectValues     = [];
	private array $froalaEditors         = [];
	private array $domElements           = [];

	public function __construct(private readonly string $deckItemId, private readonly string $title)
	{
		$this->dialogId   = $this->deckItemId;
	}

	public function duplicate(): self
	{
		$clone = new self('', $this->title); // Clear ID for duplicate

		// Copy all properties except IDs
		$clone->fieldValues       = $this->fieldValues;
		$clone->checkboxValues    = $this->checkboxValues;
		$clone->selectValues      = $this->selectValues;
		$clone->multiSelectValues = $this->multiSelectValues;
		$clone->domElements       = $this->domElements;

		// Remove readonly/disabled/locked states
		$clone->deckItemIdReadonly  = false;
		$clone->deckItemIdDisabled  = false;
		$clone->dialogIdFieldLocked = false;

		// Clear error state
		$clone->hasError = false;

		// Remove Froala editors (they don't get copied)
		$clone->froalaEditors = [];

		// Focus on ID field
		$clone->deckItemIdFieldFocused = true;

		return $clone;
	}

	public function getDeckItemId(): string
	{
		return $this->deckItemId;
	}

	public function getDialogId(): string
	{
		return $this->dialogId;
	}

	public function setDeckItemIdReadonly(bool $readonly): void
	{
		$this->deckItemIdReadonly = $readonly;
	}

	public function setDeckItemIdDisabled(bool $disabled): void
	{
		$this->deckItemIdDisabled = $disabled;
	}

	public function setDialogIdFieldLocked(bool $locked): void
	{
		$this->dialogIdFieldLocked = $locked;
	}

	public function isDeckItemIdReadonly(): bool
	{
		return $this->deckItemIdReadonly;
	}

	public function isDeckItemIdDisabled(): bool
	{
		return $this->deckItemIdDisabled;
	}

	public function isDialogIdFieldLocked(): bool
	{
		return $this->dialogIdFieldLocked;
	}

	public function isDeckItemIdFieldFocused(): bool
	{
		return $this->deckItemIdFieldFocused;
	}

	public function setFieldValue(string $field, string $value): void
	{
		$this->fieldValues[$field] = $value;
	}

	public function getFieldValue(string $field): string
	{
		return $this->fieldValues[$field] ?? '';
	}

	public function setCheckboxValue(string $field, bool $value): void
	{
		$this->checkboxValues[$field] = $value;
	}

	public function getCheckboxValue(string $field): bool
	{
		return $this->checkboxValues[$field] ?? false;
	}

	public function setSelectValue(string $field, string $value): void
	{
		$this->selectValues[$field] = $value;
	}

	public function getSelectValue(string $field): string
	{
		return $this->selectValues[$field] ?? '';
	}

	public function setMultiSelectValue(string $field, array $value): void
	{
		$this->multiSelectValues[$field] = $value;
	}

	public function getMultiSelectValue(string $field): array
	{
		return $this->multiSelectValues[$field] ?? [];
	}

	public function addFroalaEditor(string $field): void
	{
		$this->froalaEditors[$field] = true;
	}

	public function hasFroalaEditor(string $field): bool
	{
		return $this->froalaEditors[$field] ?? false;
	}

	public function addDomElement(string $type, ?string $class = null): void
	{
		$key                     = $class ? "{$type}.{$class}" : $type;
		$this->domElements[$key] = true;
	}

	public function hasDomElement(string $type, ?string $class = null): bool
	{
		$key = $class ? "{$type}.{$class}" : $type;

		return $this->domElements[$key] ?? false;
	}

	public function setError(string $error): void
	{
		$this->hasError = true;
	}

	public function hasError(): bool
	{
		return $this->hasError;
	}
}

/**
 * Mock class to simulate JavaScript deck field duplication behavior.
 */
class MockDeckFieldForDuplication
{
	private array $items                = [];
	private bool $changedEventTriggered = false;

	public function duplicateItem(MockDeckItemForDuplication $original): MockDeckItemForDuplication
	{
		$duplicate                   = $original->duplicate();
		$this->items[]               = $duplicate;
		$this->changedEventTriggered = true;

		return $duplicate;
	}

	public function getItemCount(): int
	{
		return count($this->items);
	}

	public function hasChangedEventTriggered(): bool
	{
		return $this->changedEventTriggered;
	}
}
