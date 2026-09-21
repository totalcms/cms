<?php

declare(strict_types=1);

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * The playground's HTML output was stuck at 200px tall. The CodeMirror 6
 * upgrade moved the output editor's sizing into CSS (height:auto clamped to
 * 200–500px) but left the older script sizing in place, and that script
 * measured the line height before CodeMirror had laid anything out — so it
 * pinned the container to an undersized inline height the CSS could never
 * grow past. These pin both halves of the fix at the source-text level, the
 * way the other JavaScript tests here do.
 */
final class PlaygroundOutputSizingTest extends TestCase
{
	private function playground(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../resources/templates/admin/playground.twig');
	}

	private function bundle(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../javascript/codemirror-bundle.js');
	}

	private function scss(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../css/admin-dashboard/twig-playground.scss');
	}

	public function testPlaygroundDoesNotSizeTheOutputContainerFromScript(): void
	{
		$source = $this->playground();

		$this->assertStringNotContainsString('outputContainer.style.height', $source);
		$this->assertStringNotContainsString('outputContainer.style.maxHeight', $source);
		$this->assertStringNotContainsString('defaultTextHeight()', $source, 'Output height is owned by twig-playground.scss, not measured in script.');
	}

	public function testTheCssClampThatOwnsTheOutputHeightIsStillThere(): void
	{
		$scss = $this->scss();

		$this->assertMatchesRegularExpression('/\.html-output-editor \.cm-editor \{[^}]*height:\s*auto/s', $scss);
		$this->assertMatchesRegularExpression('/\.html-output-editor \.cm-editor \{[^}]*min-height:\s*200px/s', $scss);
		$this->assertMatchesRegularExpression('/\.html-output-editor \.cm-editor \{[^}]*max-height:\s*500px/s', $scss);
	}

	public function testShimLineHeightIsMeasuredSynchronouslyBeforeFallingBackToTheOracle(): void
	{
		$bundle = $this->bundle();

		$method = (string)preg_replace('/.*defaultTextHeight\(\) \{/s', '', $bundle);
		$method = substr($method, 0, (int)strpos($method, "\n\t}"));

		$this->assertStringContainsString('getComputedStyle(this.view.contentDOM).lineHeight', $method);
		$this->assertStringContainsString('this.view.defaultLineHeight', $method);
		// Computed style is consulted first; the lazily-measured oracle is the fallback.
		$this->assertLessThan(
			strpos($method, 'this.view.defaultLineHeight'),
			strpos($method, 'getComputedStyle'),
		);
	}
}
