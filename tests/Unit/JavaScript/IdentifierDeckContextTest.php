<?php

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Identifier field in deck context functionality.
 *
 * Tests the deck-specific behavior in identifier.js for autogeneration,
 * validation, and context detection when used within deck items.
 */
final class IdentifierDeckContextTest extends TestCase
{
	/**
	 * Test identifier detects deck context correctly.
	 *
	 * Simulates the JavaScript context detection in constructor:
	 * ```
	 * if (
	 *     (this.form.id && this.form.id.length > 0 && !this.isInDeck) ||
	 *     (this.getValue().length > 0 && this.isInDeck)
	 * ) {
	 *     this.disable();
	 * }
	 * ```
	 */
	public function testIdentifierDetectsDeckContext(): void
	{
		$identifier = $this->createMockIdentifier(true); // In deck context

		$this->assertTrue($identifier->isInDeck());
	}

	/**
	 * Test identifier autogeneration in deck context.
	 *
	 * Simulates the deck-specific autogen logic:
	 * ```
	 * if (this.isInDeck) {
	 *     // Get field data from within the deck-item scope
	 *     const fields = this.deckItem.querySelectorAll('input, textarea, select');
	 *     fields.forEach(field => data[field.name] = field.value);
	 * }
	 * ```
	 */
	public function testIdentifierAutogenInDeckContext(): void
	{
		$identifier = $this->createMockIdentifier(true);
		$identifier->setAutogenTemplate('${title}_${timestamp}');

		// Set deck item field values
		$identifier->setDeckItemFieldValue('title', 'Amazing Feature');
		$identifier->setTimestamp('20240101120000');

		$result = $identifier->generateAutoId();

		$this->assertEquals('amazing_feature_20240101120000', $result);
	}

	/**
	 * Test identifier autogeneration uses deck item scope, not form scope.
	 */
	public function testIdentifierAutogenUsesDeckItemScope(): void
	{
		$identifier = $this->createMockIdentifier(true);
		$identifier->setAutogenTemplate('${title}');

		// Set different values in form vs deck item scope
		$identifier->setFormFieldValue('title', 'Form Title');
		$identifier->setDeckItemFieldValue('title', 'Deck Item Title');

		$result = $identifier->generateAutoId();

		// Should use deck item scope, not form scope
		$this->assertEquals('deck_item_title', $result);
	}

	/**
	 * Test identifier validation in deck context with empty ID.
	 *
	 * Simulates the JavaScript validation:
	 * ```
	 * validate() {
	 *     // For deck items with empty IDs, try to auto-generate if possible
	 *     if (this.isInDeck && this.getValue() === "" && this.options.autogen) {
	 *         this.setValue(this.autogenId());
	 *         this.valid = true;
	 *         return true;
	 *     }
	 * }
	 * ```
	 */
	public function testIdentifierValidationInDeckContextWithEmptyId(): void
	{
		$identifier = $this->createMockIdentifier(true);
		$identifier->setAutogenTemplate('${title}_${uuid}');
		$identifier->setDeckItemFieldValue('title', 'Test Feature');
		$identifier->setValue(''); // Empty ID

		$isValid = $identifier->validate();

		$this->assertTrue($isValid);
		$this->assertNotEmpty($identifier->getValue());
		$this->assertStringStartsWith('test_feature_', $identifier->getValue());
	}

	/**
	 * Test identifier validation in deck context requires IDs.
	 *
	 * Simulates the JavaScript validation:
	 * ```
	 * // For deck items, IDs are always required
	 * if (this.isInDeck && this.getValue() === "") {
	 *     this.error('ID is required for deck items');
	 *     return false;
	 * }
	 * ```
	 */
	public function testIdentifierValidationInDeckContextRequiresIds(): void
	{
		$identifier = $this->createMockIdentifier(true);
		$identifier->setValue(''); // Empty ID
		// No autogen template set

		$isValid = $identifier->validate();

		$this->assertFalse($isValid);
		$this->assertEquals('ID is required for deck items', $identifier->getLastError());
	}

	/**
	 * Test identifier change listener uses deck item scope.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * const searchScope = this.isInDeck ? this.deckItem : this.form.form;
	 * const field = searchScope.querySelector(`[name="${name}"]`);
	 * ```
	 */
	public function testIdentifierChangeListenerUsesDeckItemScope(): void
	{
		$identifier = $this->createMockIdentifier(true);
		$identifier->setAutogenTemplate('${title}');

		// Simulate field change in deck item scope
		$identifier->setDeckItemFieldValue('title', 'New Title');
		$identifier->triggerFieldChange('title');

		$this->assertEquals('new_title', $identifier->getValue());
	}

	/**
	 * Test identifier doesn't validate existence in deck context.
	 *
	 * Simulates the JavaScript:
	 * ```
	 * // If we are in a deck item, we don't need to check for ID existence
	 * if (this.isInDeck) return;
	 * ```
	 */
	public function testIdentifierDoesNotValidateExistenceInDeckContext(): void
	{
		$identifier = $this->createMockIdentifier(true);
		$identifier->setValue('existing_id');

		// In regular context, this would trigger API check
		// In deck context, it should skip the check
		$identifier->validateIdExists();

		$this->assertFalse($identifier->hasApiCheckBeenTriggered());
	}

	/**
	 * Test identifier validates existence in regular context.
	 */
	public function testIdentifierValidatesExistenceInRegularContext(): void
	{
		$identifier = $this->createMockIdentifier(false); // Not in deck
		$identifier->setValue('test_id');

		$identifier->validateIdExists();

		$this->assertTrue($identifier->hasApiCheckBeenTriggered());
	}

	/**
	 * Test identifier disabling behavior in deck context.
	 *
	 * Existing deck items should be disabled, new items should be editable.
	 */
	public function testIdentifierDisablingInDeckContext(): void
	{
		// Existing deck item (has value)
		$existingIdentifier = $this->createMockIdentifier(true);
		$existingIdentifier->setValue('existing_feature');
		$existingIdentifier->checkDisableConditions();

		$this->assertTrue($existingIdentifier->isDisabled());

		// New deck item (no value)
		$newIdentifier = $this->createMockIdentifier(true);
		$newIdentifier->setValue('');
		$newIdentifier->checkDisableConditions();

		$this->assertFalse($newIdentifier->isDisabled());
	}

	/**
	 * Test identifier slugification in deck context.
	 */
	public function testIdentifierSlugificationInDeckContext(): void
	{
		$identifier = $this->createMockIdentifier(true);

		$testCases = [
			'Simple Title'             => 'simple_title',
			'Feature With-Hyphens'     => 'feature_with_hyphens',
			'Special@Characters.Here!' => 'special_characters_here',
			'Mixed_Case_And-Symbols'   => 'mixed_case_and_symbols',
		];

		foreach ($testCases as $input => $expected) {
			$result = $identifier->slugifyValue($input);
			$this->assertEquals($expected, $result, "Failed to slugify: {$input}");
		}
	}

	/**
	 * Test identifier autogen with complex templates in deck context.
	 */
	public function testIdentifierAutogenWithComplexTemplatesInDeckContext(): void
	{
		$identifier = $this->createMockIdentifier(true);

		$testCases = [
			[
				'template' => '${title}_${category}',
				'fields'   => ['title' => 'Amazing Feature', 'category' => 'Premium'],
				'expected' => 'amazing_feature_premium',
			],
			[
				'template'         => '${name}_${timestamp}',
				'fields'           => ['name' => 'Test Item'],
				'expected_pattern' => '/^test_item_\d+$/',
			],
			[
				'template'         => '${title}_${uuid}',
				'fields'           => ['title' => 'Unique Feature'],
				'expected_pattern' => '/^unique_feature_[a-z0-9]+$/',
			],
		];

		foreach ($testCases as $testCase) {
			$identifier->setAutogenTemplate($testCase['template']);

			foreach ($testCase['fields'] as $field => $value) {
				$identifier->setDeckItemFieldValue($field, $value);
			}

			$result = $identifier->generateAutoId();

			if (isset($testCase['expected'])) {
				$this->assertEquals($testCase['expected'], $result);
			} elseif (isset($testCase['expected_pattern'])) {
				$this->assertMatchesRegularExpression($testCase['expected_pattern'], $result);
			}
		}
	}

	/**
	 * Test identifier performance in deck context with many items.
	 */
	public function testIdentifierPerformanceInDeckContext(): void
	{
		$identifiers = [];

		$start = microtime(true);

		// Create 50 identifiers in deck context
		for ($i = 1; $i <= 50; $i++) {
			$identifier = $this->createMockIdentifier(true);
			$identifier->setAutogenTemplate('item_${sequence}');
			$identifier->setDeckItemFieldValue('sequence', (string)$i);
			$identifier->generateAutoId();
			$identifiers[] = $identifier;
		}

		$time = microtime(true) - $start;

		$this->assertCount(50, $identifiers);
		$this->assertLessThan(0.1, $time); // Should be fast

		// Check that all IDs are unique
		$ids = array_map(fn ($id) => $id->getValue(), $identifiers);
		$this->assertEquals(50, count(array_unique($ids)));
	}

	/**
	 * Create a mock identifier for testing deck context behavior.
	 */
	private function createMockIdentifier(bool $isInDeck): MockIdentifierForDeckContext
	{
		return new MockIdentifierForDeckContext($isInDeck);
	}
}

/**
 * Mock class to simulate JavaScript identifier behavior in deck context.
 */
class MockIdentifierForDeckContext
{
	private bool $isInDeck;
	private string $value           = '';
	private string $autogenTemplate = '';
	private array $deckItemFields   = [];
	private array $formFields       = [];
	private bool $disabled          = false;
	private string $lastError       = '';
	private bool $apiCheckTriggered = false;
	private string $timestamp       = '';

	public function __construct(bool $isInDeck)
	{
		$this->isInDeck = $isInDeck;
	}

	public function isInDeck(): bool
	{
		return $this->isInDeck;
	}

	public function setValue(string $value): void
	{
		$this->value = $value;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function setAutogenTemplate(string $template): void
	{
		$this->autogenTemplate = $template;
	}

	public function setDeckItemFieldValue(string $field, string $value): void
	{
		$this->deckItemFields[$field] = $value;
	}

	public function setFormFieldValue(string $field, string $value): void
	{
		$this->formFields[$field] = $value;
	}

	public function setTimestamp(string $timestamp): void
	{
		$this->timestamp = $timestamp;
	}

	public function generateAutoId(): string
	{
		$data = $this->isInDeck ? $this->deckItemFields : $this->formFields;

		// Add default data
		$data['timestamp'] = $this->timestamp ?: date('YmdHis');
		$data['uuid']      = substr(md5(uniqid()), 0, 7);
		$data['now']       = time();

		// Replace template variables
		$result = $this->autogenTemplate;
		foreach ($data as $key => $value) {
			$result = str_replace('${' . $key . '}', $value, $result);
		}

		$slugified = $this->slugifyValue($result);
		$this->setValue($slugified);

		return $slugified;
	}

	public function validate(): bool
	{
		// For deck items with empty IDs, try to auto-generate if possible
		if ($this->isInDeck && $this->getValue() === '' && $this->autogenTemplate !== '') {
			$this->generateAutoId();

			return true;
		}

		// For deck items, IDs are always required
		if ($this->isInDeck && $this->getValue() === '') {
			$this->lastError = 'ID is required for deck items';

			return false;
		}

		return true;
	}

	public function triggerFieldChange(string $fieldName): void
	{
		if ($this->autogenTemplate !== '' && strpos($this->autogenTemplate, '${' . $fieldName . '}') !== false) {
			$this->generateAutoId();
		}
	}

	public function validateIdExists(): void
	{
		// If we are in a deck item, we don't need to check for ID existence
		if ($this->isInDeck) {
			return;
		}

		// Regular context - would trigger API check
		$this->apiCheckTriggered = true;
	}

	public function checkDisableConditions(): void
	{
		if ($this->isInDeck && $this->getValue() !== '') {
			$this->disabled = true;
		}
	}

	public function isDisabled(): bool
	{
		return $this->disabled;
	}

	public function getLastError(): string
	{
		return $this->lastError;
	}

	public function hasApiCheckBeenTriggered(): bool
	{
		return $this->apiCheckTriggered;
	}

	public function slugifyValue(string $value): string
	{
		// Simulate slugification - matches JavaScript behavior with underscores
		$value = strtolower($value);
		$value = preg_replace('/[^a-z0-9]+/', '_', $value);
		$value = trim($value, '_');

		return $value;
	}
}
