<?php

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Deck Field JavaScript validation functionality.
 *
 * These tests simulate the JavaScript validation logic that was implemented
 * in deck.js for empty IDs and duplicate ID detection.
 */
final class DeckFieldValidationTest extends TestCase
{
	/**
	 * Test deck field validation with empty IDs.
	 * Simulates the JavaScript validation logic: `if (itemId.length == 0)`.
	 */
	public function testValidateRejectsEmptyIds(): void
	{
		$deckItems = [
			['id' => '', 'title' => 'Empty ID item'],
			['id' => 'valid_item', 'title' => 'Valid item'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertFalse($result['isValid']);
		$this->assertContains('ID is required for deck items', $result['errors']);
	}

	/**
	 * Test deck field validation with whitespace-only IDs.
	 */
	public function testValidateRejectsWhitespaceIds(): void
	{
		$deckItems = [
			['id' => '   ', 'title' => 'Whitespace ID item'],
			['id' => 'valid_item', 'title' => 'Valid item'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertFalse($result['isValid']);
		$this->assertContains('ID is required for deck items', $result['errors']);
	}

	/**
	 * Test deck field validation with duplicate IDs.
	 * Simulates the JavaScript logic: `itemIds.includes(itemId)`.
	 */
	public function testValidateRejectsDuplicateIds(): void
	{
		$deckItems = [
			['id' => 'feature1', 'title' => 'First feature'],
			['id' => 'feature2', 'title' => 'Second feature'],
			['id' => 'feature1', 'title' => 'Duplicate feature'], // Duplicate ID
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertFalse($result['isValid']);
		$this->assertContains('Duplicate ID found: feature1', $result['errors']);
	}

	/**
	 * Test deck field validation with multiple duplicate IDs.
	 */
	public function testValidateRejectsMultipleDuplicateIds(): void
	{
		$deckItems = [
			['id' => 'feature1', 'title' => 'First feature'],
			['id' => 'feature2', 'title' => 'Second feature'],
			['id' => 'feature1', 'title' => 'Duplicate feature 1'],
			['id' => 'feature3', 'title' => 'Third feature'],
			['id' => 'feature2', 'title' => 'Duplicate feature 2'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertFalse($result['isValid']);
		$this->assertContains('Duplicate ID found: feature1', $result['errors']);
		$this->assertContains('Duplicate ID found: feature2', $result['errors']);
	}

	/**
	 * Test deck field validation with valid unique IDs.
	 */
	public function testValidateAcceptsValidUniqueIds(): void
	{
		$deckItems = [
			['id' => 'feature1', 'title' => 'First feature'],
			['id' => 'feature2', 'title' => 'Second feature'],
			['id' => 'feature3', 'title' => 'Third feature'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertTrue($result['isValid']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test deck field validation with empty deck.
	 */
	public function testValidateAcceptsEmptyDeck(): void
	{
		$deckItems = [];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertTrue($result['isValid']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test deck field validation with single valid item.
	 */
	public function testValidateAcceptsSingleValidItem(): void
	{
		$deckItems = [
			['id' => 'single_item', 'title' => 'Only item'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertTrue($result['isValid']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test deck field validation error priorities (empty ID vs duplicate).
	 */
	public function testValidateHandlesEmptyIdBeforeDuplicates(): void
	{
		$deckItems = [
			['id' => 'feature1', 'title' => 'First feature'],
			['id' => '', 'title' => 'Empty ID item'],
			['id' => 'feature1', 'title' => 'Duplicate feature'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertFalse($result['isValid']);
		$this->assertContains('ID is required for deck items', $result['errors']);
		// Should also catch the duplicate but empty ID is processed first
		$this->assertContains('Duplicate ID found: feature1', $result['errors']);
	}

	/**
	 * Test deck field validation with various ID formats.
	 */
	public function testValidateAcceptsValidIdFormats(): void
	{
		$deckItems = [
			['id' => 'simple', 'title' => 'Simple ID'],
			['id' => 'with_underscore', 'title' => 'Underscore ID'],
			['id' => 'CamelCase', 'title' => 'Camel case ID'],
			['id' => 'feature123', 'title' => 'Alphanumeric ID'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertTrue($result['isValid']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test deck field validation with case-sensitive duplicate detection.
	 */
	public function testValidateHandlesCaseSensitiveDuplicates(): void
	{
		$deckItems = [
			['id' => 'feature1', 'title' => 'Lowercase feature'],
			['id' => 'Feature1', 'title' => 'Capitalized feature'],
			['id' => 'FEATURE1', 'title' => 'Uppercase feature'],
		];

		$result = $this->simulateDeckValidation($deckItems);

		// These should be considered different IDs (case-sensitive)
		$this->assertTrue($result['isValid']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test deck field validation error message format.
	 */
	public function testValidateErrorMessageFormat(): void
	{
		$deckItems = [
			['id' => 'test_feature', 'title' => 'First'],
			['id' => 'test_feature', 'title' => 'Second'], // Duplicate
		];

		$result = $this->simulateDeckValidation($deckItems);

		$this->assertFalse($result['isValid']);
		$expectedError = 'Duplicate ID found: test_feature';
		$this->assertContains($expectedError, $result['errors']);
	}

	/**
	 * Simulate the JavaScript deck validation logic in PHP for testing.
	 *
	 * This mimics the validation logic from deck.js:
	 * - Check for empty IDs (itemId.length == 0)
	 * - Check for duplicates using itemIds.includes(itemId)
	 * - Collect errors and return validation result
	 *
	 * @param array<int, array<string, string>> $deckItems
	 *
	 * @return array<string, mixed>
	 */
	private function simulateDeckValidation(array $deckItems): array
	{
		$isValid = true;
		$itemIds = [];
		$errors  = [];

		foreach ($deckItems as $item) {
			$itemId = $item['id'] ?? '';

			// Simulate JavaScript: if (itemId.length == 0)
			if (strlen(trim($itemId)) === 0) {
				$errors[] = 'ID is required for deck items';
				$isValid  = false;
				continue; // Skip duplicate checking for empty IDs
			}

			// Simulate JavaScript: if (itemIds.includes(itemId))
			if (in_array($itemId, $itemIds, true)) {
				$errors[] = "Duplicate ID found: {$itemId}";
				$isValid  = false;
				continue; // Skip adding to itemIds array
			}

			$itemIds[] = $itemId;
		}

		return [
			'isValid' => $isValid,
			'errors'  => $errors,
		];
	}
}
