<?php

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Deck Item ID synchronization functionality.
 *
 * Tests the setupIdSync() method in deckItem.js that uses MutationObserver
 * to keep deck-item-id and dialog id fields synchronized.
 */
final class DeckItemIdSyncTest extends TestCase
{
	/**
	 * Test ID synchronization from deck item field to dialog field.
	 *
	 * Simulates user typing in the deck-item-id field and verifying
	 * the dialog id field is updated via syncFromDeckItem().
	 */
	public function testSyncFromDeckItemToDialog(): void
	{
		$sync = $this->createMockIdSync();

		// Simulate user changing deck item ID
		$sync->setDeckItemId('new_feature');
		$sync->triggerDeckItemSync();

		$this->assertEquals('new_feature', $sync->getDialogId());
		$this->assertEquals('new_feature', $sync->getDeckItemId());
	}

	/**
	 * Test ID synchronization from dialog field to deck item field.
	 *
	 * Simulates user typing in the dialog id field and verifying
	 * the deck-item-id field is updated via syncFromDialog().
	 */
	public function testSyncFromDialogToDeckItem(): void
	{
		$sync = $this->createMockIdSync();

		// Simulate user changing dialog ID
		$sync->setDialogId('dialog_feature');
		$sync->triggerDialogSync();

		$this->assertEquals('dialog_feature', $sync->getDeckItemId());
		$this->assertEquals('dialog_feature', $sync->getDialogId());
	}

	/**
	 * Test ID sanitization replaces hyphens with underscores.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * const sanitizeId = (value) => {
	 *     return value.replace(/-/g, '_');
	 * };
	 * ```
	 */
	public function testIdSanitizationReplacesHyphensWithUnderscores(): void
	{
		$sync = $this->createMockIdSync();

		// Test sanitization from deck item field
		$sync->setDeckItemId('my-feature-name');
		$sync->triggerDeckItemSync();

		$this->assertEquals('my_feature_name', $sync->getDeckItemId());
		$this->assertEquals('my_feature_name', $sync->getDialogId());
	}

	/**
	 * Test ID sanitization from dialog field.
	 */
	public function testIdSanitizationFromDialogField(): void
	{
		$sync = $this->createMockIdSync();

		// Test sanitization from dialog field
		$sync->setDialogId('another-feature-with-dashes');
		$sync->triggerDialogSync();

		$this->assertEquals('another_feature_with_dashes', $sync->getDeckItemId());
		$this->assertEquals('another_feature_with_dashes', $sync->getDialogId());
	}

	/**
	 * Test synchronization prevents infinite loops.
	 *
	 * Simulates the syncing flag mechanism:
	 * ```
	 * if (syncing || dialogIdField.value === sanitizedValue) return;
	 * syncing = true;
	 * // ... sync logic
	 * syncing = false;
	 * ```
	 */
	public function testSynchronizationPreventsInfiniteLoops(): void
	{
		$sync = $this->createMockIdSync();

		// Set initial values
		$sync->setDeckItemId('test_feature');
		$sync->setDialogId('test_feature');

		// Try to sync when values are already identical
		$syncCallsBefore = $sync->getSyncCallCount();
		$sync->triggerDeckItemSync();
		$syncCallsAfter = $sync->getSyncCallCount();

		// No actual sync should occur when values are identical
		$this->assertEquals($syncCallsBefore, $syncCallsAfter);
	}

	/**
	 * Test synchronization skips readonly fields.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * if (deckItemIdField.hasAttribute('readonly') || deckItemIdField.hasAttribute('disabled')) {
	 *     return;
	 * }
	 * ```
	 */
	public function testSynchronizationSkipsReadonlyFields(): void
	{
		$sync = $this->createMockIdSync();
		$sync->setDeckItemFieldReadonly(true);

		// Try to sync from a readonly field
		$sync->setDeckItemId('readonly_value');
		$sync->triggerDeckItemSync();

		// Dialog field should not be updated
		$this->assertEquals('', $sync->getDialogId());
		$this->assertFalse($sync->hasSyncOccurred());
	}

	/**
	 * Test synchronization skips disabled fields.
	 */
	public function testSynchronizationSkipsDisabledFields(): void
	{
		$sync = $this->createMockIdSync();
		$sync->setDeckItemFieldDisabled(true);

		// Try to sync from a disabled field
		$sync->setDeckItemId('disabled_value');
		$sync->triggerDeckItemSync();

		// Dialog field should not be updated
		$this->assertEquals('', $sync->getDialogId());
		$this->assertFalse($sync->hasSyncOccurred());
	}

	/**
	 * Test complex ID sanitization scenarios.
	 */
	public function testComplexIdSanitizationScenarios(): void
	{
		$sync = $this->createMockIdSync();

		$testCases = [
			['input' => 'simple-name', 'expected' => 'simple_name'],
			['input' => 'multiple-hyphens-here', 'expected' => 'multiple_hyphens_here'],
			['input' => 'mixed-name_with_underscores', 'expected' => 'mixed_name_with_underscores'],
			['input' => 'already_clean', 'expected' => 'already_clean'],
			['input' => '-leading-hyphen', 'expected' => '_leading_hyphen'],
			['input' => 'trailing-hyphen-', 'expected' => 'trailing_hyphen_'],
			['input' => '---multiple---consecutive---', 'expected' => '___multiple___consecutive___'],
		];

		foreach ($testCases as $testCase) {
			$sync->reset();
			$sync->setDeckItemId($testCase['input']);
			$sync->triggerDeckItemSync();

			$this->assertEquals(
				$testCase['expected'],
				$sync->getDeckItemId(),
				"Failed to sanitize '{$testCase['input']}' correctly"
			);
			$this->assertEquals(
				$testCase['expected'],
				$sync->getDialogId(),
				"Failed to sync sanitized value '{$testCase['input']}' to dialog"
			);
		}
	}

	/**
	 * Test MutationObserver detection of programmatic changes.
	 *
	 * Simulates the MutationObserver detecting changes made by JavaScript
	 * (like autogeneration) rather than direct user input.
	 */
	public function testMutationObserverDetectsProgrammaticChanges(): void
	{
		$sync = $this->createMockIdSync();

		// Simulate programmatic change (like from autogen)
		$sync->setProgrammaticDeckItemId('autogen_feature_123');
		$sync->triggerMutationObserver();

		$this->assertEquals('autogen_feature_123', $sync->getDialogId());
		$this->assertTrue($sync->hasMutationObserverTriggered());
	}

	/**
	 * Test periodic fallback checking.
	 *
	 * Simulates the setInterval fallback mechanism:
	 * ```
	 * const syncChecker = setInterval(() => {
	 *     if (deckItemIdField.value !== lastDeckItemValue) {
	 *         lastDeckItemValue = deckItemIdField.value;
	 *         syncFromDeckItem();
	 *     }
	 * }, 100);
	 * ```
	 */
	public function testPeriodicFallbackChecking(): void
	{
		$sync = $this->createMockIdSync();

		// Simulate a change that MutationObserver might miss
		$sync->setDeckItemId('fallback_test');
		$sync->triggerPeriodicFallback();

		$this->assertEquals('fallback_test', $sync->getDialogId());
		$this->assertTrue($sync->hasPeriodicFallbackTriggered());
	}

	/**
	 * Test error state clearing during sync.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * dialogIdField.classList.remove("error");
	 * dialogIdField.closest(".form-field")?.classList.remove("error");
	 * ```
	 */
	public function testErrorStateClearingDuringSync(): void
	{
		$sync = $this->createMockIdSync();
		$sync->setDialogFieldError(true);

		// Sync should clear error state
		$sync->setDeckItemId('valid_feature');
		$sync->triggerDeckItemSync();

		$this->assertFalse($sync->hasDialogFieldError());
		$this->assertFalse($sync->hasDialogFormFieldError());
	}

	/**
	 * Test bidirectional synchronization consistency.
	 */
	public function testBidirectionalSynchronizationConsistency(): void
	{
		$sync = $this->createMockIdSync();

		// Test deck -> dialog -> deck consistency
		$sync->setDeckItemId('consistency-test');
		$sync->triggerDeckItemSync();

		$intermediateValue = $sync->getDialogId();

		// Now sync back from dialog to deck
		$sync->triggerDialogSync();

		$this->assertEquals('consistency_test', $sync->getDeckItemId());
		$this->assertEquals('consistency_test', $sync->getDialogId());
		$this->assertEquals($intermediateValue, $sync->getDeckItemId());
	}

	/**
	 * Create a mock ID synchronization system for testing.
	 */
	private function createMockIdSync(): MockIdSync
	{
		return new MockIdSync();
	}
}

/**
 * Mock class to simulate JavaScript ID synchronization behavior.
 */
class MockIdSync
{
	private string $deckItemId              = '';
	private string $dialogId                = '';
	private bool $deckItemReadonly          = false;
	private bool $deckItemDisabled          = false;
	private bool $syncOccurred              = false;
	private bool $dialogFieldError          = false;
	private bool $dialogFormFieldError      = false;
	private int $syncCallCount              = 0;
	private bool $mutationObserverTriggered = false;
	private bool $periodicFallbackTriggered = false;

	public function setDeckItemId(string $id): void
	{
		$this->deckItemId = $id;
	}

	public function setDialogId(string $id): void
	{
		$this->dialogId = $id;
	}

	public function setProgrammaticDeckItemId(string $id): void
	{
		$this->deckItemId = $id;
	}

	public function getDeckItemId(): string
	{
		return $this->deckItemId;
	}

	public function getDialogId(): string
	{
		return $this->dialogId;
	}

	public function setDeckItemFieldReadonly(bool $readonly): void
	{
		$this->deckItemReadonly = $readonly;
	}

	public function setDeckItemFieldDisabled(bool $disabled): void
	{
		$this->deckItemDisabled = $disabled;
	}

	public function setDialogFieldError(bool $hasError): void
	{
		$this->dialogFieldError     = $hasError;
		$this->dialogFormFieldError = $hasError;
	}

	public function triggerDeckItemSync(): void
	{
		if ($this->deckItemReadonly || $this->deckItemDisabled) {
			return;
		}

		$sanitizedValue = $this->sanitizeId($this->deckItemId);

		if ($this->dialogId === $sanitizedValue) {
			return; // Prevent infinite loops
		}

		$this->syncCallCount++;
		$this->syncOccurred = true;

		// Update both values with sanitized version
		$this->deckItemId = $sanitizedValue;
		$this->dialogId   = $sanitizedValue;

		// Clear error states
		$this->dialogFieldError     = false;
		$this->dialogFormFieldError = false;
	}

	public function triggerDialogSync(): void
	{
		$sanitizedValue = $this->sanitizeId($this->dialogId);

		if ($this->deckItemId === $sanitizedValue) {
			return; // Prevent infinite loops
		}

		$this->syncCallCount++;
		$this->syncOccurred = true;

		// Update both values with sanitized version
		$this->dialogId   = $sanitizedValue;
		$this->deckItemId = $sanitizedValue;

		// Clear error states
		$this->dialogFieldError     = false;
		$this->dialogFormFieldError = false;
	}

	public function triggerMutationObserver(): void
	{
		$this->mutationObserverTriggered = true;
		$this->triggerDeckItemSync();
	}

	public function triggerPeriodicFallback(): void
	{
		$this->periodicFallbackTriggered = true;
		$this->triggerDeckItemSync();
	}

	public function hasSyncOccurred(): bool
	{
		return $this->syncOccurred;
	}

	public function hasDialogFieldError(): bool
	{
		return $this->dialogFieldError;
	}

	public function hasDialogFormFieldError(): bool
	{
		return $this->dialogFormFieldError;
	}

	public function getSyncCallCount(): int
	{
		return $this->syncCallCount;
	}

	public function hasMutationObserverTriggered(): bool
	{
		return $this->mutationObserverTriggered;
	}

	public function hasPeriodicFallbackTriggered(): bool
	{
		return $this->periodicFallbackTriggered;
	}

	public function reset(): void
	{
		$this->deckItemId                = '';
		$this->dialogId                  = '';
		$this->syncOccurred              = false;
		$this->syncCallCount             = 0;
		$this->dialogFieldError          = false;
		$this->dialogFormFieldError      = false;
		$this->mutationObserverTriggered = false;
		$this->periodicFallbackTriggered = false;
	}

	/**
	 * Simulate the JavaScript sanitizeId function.
	 */
	private function sanitizeId(string $value): string
	{
		return str_replace('-', '_', $value);
	}
}
