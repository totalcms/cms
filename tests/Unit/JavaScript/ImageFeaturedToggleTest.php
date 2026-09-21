<?php

declare(strict_types=1);

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * The featured star persists its change with its own PATCH, then reflects
 * it in the meta dialog's checkbox. Doing that through setValue() — the
 * user-edit path — marked the checkbox unsaved, and its subfield-change
 * marked the parent image field unsaved too, so the form was dirty over a
 * change already on disk (issue #44). These pin the saved-value path at
 * the source level, the way the other JavaScript tests here do.
 */
final class ImageFeaturedToggleTest extends TestCase
{
	private function src(string $file): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../javascript/totalform/' . $file);
	}

	public function testTotalFieldHasASilentSetAsSavedPath(): void
	{
		$field = $this->src('totalfield.js');

		$this->assertStringContainsString('setSavedValue(value) {', $field);
		$this->assertMatchesRegularExpression('/setSavedValue\(value\) \{.*?this\.silent = true;.*?this\.setValue\(value\);.*?this\.silent = false;.*?this\.saved\(\);/s', $field);
		// changed() must honour the flag BEFORE it marks anything or dispatches.
		$changed = (string)preg_replace('/.*\n\s*changed\(\) \{/s', '', $field);
		$this->assertLessThan(strpos($changed, 'classList.add("unsaved")'), strpos($changed, 'if (this.silent)'));
		$this->assertLessThan(strpos($changed, 'dispatchEvent('), strpos($changed, 'if (this.silent)'));
	}

	public function testSavedMovesTheBaselineToTheCurrentValue(): void
	{
		$this->assertMatchesRegularExpression('/saved\(\) \{\s*this\.container\.classList\.remove\("unsaved"\);.*?this\.storedValue = this\.getValue\(\);/s', $this->src('totalfield.js'));
	}

	public function testTheImageStarReflectsItsPersistedValueAsSaved(): void
	{
		$preview = $this->src('image-preview.js');
		$body    = (string)preg_replace('/.*toggleFeaturedField\(\) \{/s', '', $preview);
		$body    = substr($body, 0, (int)strpos($body, "\n\t}"));

		$this->assertStringContainsString('setSavedValue(!this.isFeatured())', $body);
		$this->assertStringContainsString('this.totalfield.saved()', $body);
		$this->assertStringNotContainsString('.setValue(', $body, 'setValue() is the user-edit path and would dirty the form.');
	}

	public function testActionBarClicksDoNotMoveFocusIntoTheField(): void
	{
		// help-on-focus is CSS :focus-within on the field; a clicked <button>
		// takes focus, so every action-bar click popped the field's help label.
		foreach (['image-preview.js', 'gallery-preview.js'] as $file) {
			$this->assertMatchesRegularExpression(
				'/querySelector\("\.actionbar"\)\?\.addEventListener\("mousedown", event => event\.preventDefault\(\)\)/',
				$this->src($file),
				"{$file} must keep action-bar clicks from taking focus.",
			);
		}
	}

	public function testTheGalleryStarReflectsItsPersistedValueAsSaved(): void
	{
		$preview = $this->src('gallery-preview.js');
		$body    = (string)preg_replace('/.*setFeatured\(featured\) \{/s', '', $preview);
		$body    = substr($body, 0, (int)strpos($body, "\n\t}"));

		$this->assertStringContainsString('setSavedValue(featured)', $body);
		$this->assertStringNotContainsString('.setValue(featured)', $body);
	}
}
