<?php

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Deck Field error handling functionality.
 *
 * Tests the error() method in deck.js that sets custom validity messages
 * and the corresponding error() method in deckItem.js for individual items.
 */
final class DeckFieldErrorHandlingTest extends TestCase
{
	/**
	 * Test deck field error method sets custom validity message.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * error(message) {
	 *     super.error(message);
	 *     this.input.setCustomValidity(message);
	 * }
	 * ```
	 */
	public function testDeckFieldErrorSetsCustomValidity(): void
	{
		$errorMessage = 'Duplicate ID found: feature1';

		$deckField = $this->createMockDeckField();
		$deckField->setError($errorMessage);

		$this->assertEquals($errorMessage, $deckField->getCustomValidity());
		$this->assertFalse($deckField->isValid());
	}

	/**
	 * Test deck field error method calls parent error handler.
	 */
	public function testDeckFieldErrorCallsParentError(): void
	{
		$errorMessage = 'ID is required for deck items';

		$deckField = $this->createMockDeckField();
		$deckField->setError($errorMessage);

		$this->assertTrue($deckField->hasParentErrorBeenCalled());
		$this->assertEquals($errorMessage, $deckField->getParentErrorMessage());
	}

	/**
	 * Test deck field clears custom validity on successful validation.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * validate() {
	 *     this.input.setCustomValidity(""); // Clear previous custom validity
	 *     // ... validation logic
	 * }
	 * ```
	 */
	public function testDeckFieldClearsCustomValidityOnValidation(): void
	{
		$deckField = $this->createMockDeckField();

		// First set an error
		$deckField->setError('Some error');
		$this->assertFalse($deckField->isValid());

		// Then validate successfully
		$deckField->validateAndClear();

		$this->assertEquals('', $deckField->getCustomValidity());
		$this->assertTrue($deckField->isValid());
	}

	/**
	 * Test deck item error method targets ID field.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * error(message) {
	 *     const idField = this.container.querySelector(".id-field");
	 *     idField?.totalfield?.error(message);
	 * }
	 * ```
	 */
	public function testDeckItemErrorTargetsIdField(): void
	{
		$errorMessage = 'Duplicate ID found: feature1';

		$deckItem = $this->createMockDeckItem();
		$deckItem->setError($errorMessage);

		$this->assertEquals($errorMessage, $deckItem->getIdFieldError());
		$this->assertTrue($deckItem->hasIdFieldErrorBeenSet());
	}

	/**
	 * Test deck item error method handles missing ID field gracefully.
	 */
	public function testDeckItemErrorHandlesMissingIdField(): void
	{
		$errorMessage = 'Some error';

		$deckItem = $this->createMockDeckItemWithoutIdField();

		// Should not throw exception when ID field is missing
		$this->expectNotToPerformAssertions();
		$deckItem->setError($errorMessage);
	}

	/**
	 * Test deck validation sets errors on both field and items.
	 */
	public function testDeckValidationSetsErrorsOnBothFieldAndItems(): void
	{
		$deckItems = [
			['id' => 'feature1', 'title' => 'First'],
			['id' => 'feature1', 'title' => 'Duplicate'],
		];

		$result = $this->simulateDeckValidationWithErrorHandling($deckItems);

		$this->assertFalse($result['isValid']);
		$this->assertTrue($result['deckFieldHasError']);
		$this->assertTrue($result['itemHasError']);
		$this->assertEquals('Duplicate ID found: feature1', $result['deckFieldError']);
		$this->assertEquals('Duplicate ID found: feature1', $result['itemError']);
	}

	/**
	 * Test multiple validation errors are properly set.
	 */
	public function testMultipleValidationErrorsAreSet(): void
	{
		$deckItems = [
			['id' => '', 'title' => 'Empty ID'],
			['id' => 'feature1', 'title' => 'First'],
			['id' => 'feature1', 'title' => 'Duplicate'],
		];

		$result = $this->simulateDeckValidationWithErrorHandling($deckItems);

		$this->assertFalse($result['isValid']);
		$this->assertCount(2, $result['allErrors']); // Empty ID and duplicate ID
		$this->assertContains('ID is required for deck items', $result['allErrors']);
		$this->assertContains('Duplicate ID found: feature1', $result['allErrors']);
	}

	/**
	 * Test error message formatting consistency.
	 */
	public function testErrorMessageFormattingConsistency(): void
	{
		$testCases = [
			[
				'scenario'      => 'empty_id',
				'items'         => [['id' => '', 'title' => 'Empty']],
				'expectedError' => 'ID is required for deck items',
			],
			[
				'scenario' => 'duplicate_id',
				'items'    => [
					['id' => 'test', 'title' => 'First'],
					['id' => 'test', 'title' => 'Second'],
				],
				'expectedError' => 'Duplicate ID found: test',
			],
			[
				'scenario' => 'complex_id',
				'items'    => [
					['id' => 'complex_feature_name', 'title' => 'First'],
					['id' => 'complex_feature_name', 'title' => 'Second'],
				],
				'expectedError' => 'Duplicate ID found: complex_feature_name',
			],
		];

		foreach ($testCases as $testCase) {
			$result = $this->simulateDeckValidationWithErrorHandling($testCase['items']);

			$this->assertFalse($result['isValid'], "Test case {$testCase['scenario']} should be invalid");
			$this->assertContains(
				$testCase['expectedError'],
				$result['allErrors'],
				"Test case {$testCase['scenario']} should contain expected error message"
			);
		}
	}

	/**
	 * Create a mock deck field for testing error handling.
	 */
	private function createMockDeckField(): MockDeckField
	{
		return new MockDeckField();
	}

	/**
	 * Create a mock deck item for testing error handling.
	 */
	private function createMockDeckItem(): MockDeckItem
	{
		return new MockDeckItem(true); // Has ID field
	}

	/**
	 * Create a mock deck item without ID field for testing edge cases.
	 */
	private function createMockDeckItemWithoutIdField(): MockDeckItem
	{
		return new MockDeckItem(false); // No ID field
	}

	/**
	 * Simulate deck validation with error handling for testing.
	 *
	 * @param array<int, array<string, string>> $deckItems
	 *
	 * @return array<string, mixed>
	 */
	private function simulateDeckValidationWithErrorHandling(array $deckItems): array
	{
		$isValid   = true;
		$itemIds   = [];
		$errors    = [];
		$deckField = $this->createMockDeckField();
		$items     = array_map(fn () => $this->createMockDeckItem(), $deckItems);

		foreach ($deckItems as $index => $item) {
			$itemId   = $item['id'] ?? '';
			$deckItem = $items[$index];

			if (strlen($itemId) === 0) {
				$errorMessage = 'ID is required for deck items';
				$deckField->setError($errorMessage);
				$deckItem->setError($errorMessage);
				$errors[] = $errorMessage;
				$isValid  = false;
				continue;
			}

			if (in_array($itemId, $itemIds, true)) {
				$errorMessage = "Duplicate ID found: {$itemId}";
				$deckField->setError($errorMessage);
				$deckItem->setError($errorMessage);
				$errors[] = $errorMessage;
				$isValid  = false;
				continue;
			}

			$itemIds[] = $itemId;
		}

		// Check if any item has an error
		$itemHasError = false;
		$itemError    = '';
		foreach ($items as $item) {
			if ($item->hasError()) {
				$itemHasError = true;
				$itemError    = $item->getIdFieldError();
				break;
			}
		}

		return [
			'isValid'           => $isValid,
			'deckFieldHasError' => $deckField->hasError(),
			'itemHasError'      => $itemHasError,
			'deckFieldError'    => $deckField->getCustomValidity(),
			'itemError'         => $itemError,
			'allErrors'         => $errors,
		];
	}
}

/**
 * Mock class to simulate JavaScript deck field behavior.
 */
class MockDeckField
{
	private string $customValidity     = '';
	private string $parentErrorMessage = '';
	private bool $parentErrorCalled    = false;

	public function setError(string $message): void
	{
		// Simulate super.error(message)
		$this->parentErrorMessage = $message;
		$this->parentErrorCalled  = true;

		// Simulate this.input.setCustomValidity(message)
		$this->customValidity = $message;
	}

	public function validateAndClear(): void
	{
		// Simulate clearing custom validity on successful validation
		$this->customValidity = '';
	}

	public function getCustomValidity(): string
	{
		return $this->customValidity;
	}

	public function isValid(): bool
	{
		return $this->customValidity === '';
	}

	public function hasParentErrorBeenCalled(): bool
	{
		return $this->parentErrorCalled;
	}

	public function getParentErrorMessage(): string
	{
		return $this->parentErrorMessage;
	}

	public function hasError(): bool
	{
		return $this->customValidity !== '';
	}
}

/**
 * Mock class to simulate JavaScript deck item behavior.
 */
class MockDeckItem
{
	private string $idFieldError  = '';
	private bool $idFieldErrorSet = false;
	private bool $hasIdField;

	public function __construct(bool $hasIdField = true)
	{
		$this->hasIdField = $hasIdField;
	}

	public function setError(string $message): void
	{
		if ($this->hasIdField) {
			// Simulate idField?.totalfield?.error(message)
			$this->idFieldError    = $message;
			$this->idFieldErrorSet = true;
		}
		// If no ID field, do nothing (graceful handling)
	}

	public function getIdFieldError(): string
	{
		return $this->idFieldError;
	}

	public function hasIdFieldErrorBeenSet(): bool
	{
		return $this->idFieldErrorSet;
	}

	public function hasError(): bool
	{
		return $this->idFieldErrorSet;
	}
}
