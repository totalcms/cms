<?php

namespace Tests\Unit\Domain\Twig\Designer;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerPreprocessor;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerRegistry;

final class TemplateDesignerPreprocessorTest extends TestCase
{
	private TemplateDesignerRegistry $registry;
	private TemplateDesignerPreprocessor $preprocessor;

	protected function setUp(): void
	{
		$this->registry     = new TemplateDesignerRegistry();
		$this->preprocessor = new TemplateDesignerPreprocessor($this->registry);
	}

	public function testBasicBlockExtraction(): void
	{
		$source = <<<'TWIG'
{% templatedesigner for 'products/tile' on 'https://example.com/tcms/' token 'abc123' %}
<article>{{ object.title }}</article>
{% endtemplatedesigner %}
TWIG;

		$result = $this->preprocessor->preprocess($source, 'test.twig');

		// Should replace the block with a sync function call
		$this->assertStringContainsString("{{ _tcms_designer_sync('_designer_test_twig_0') }}", $result);
		$this->assertStringNotContainsString('templatedesigner', $result);

		// Verify registry data
		$block = $this->registry->get('_designer_test_twig_0');
		$this->assertNotNull($block);
		$this->assertSame('products/tile', $block['template']);
		$this->assertSame('https://example.com/tcms/', $block['domain']);
		$this->assertSame('abc123', $block['token']);
		$this->assertStringContainsString('<article>{{ object.title }}</article>', $block['content']);
	}

	public function testBlockWithoutToken(): void
	{
		$source = <<<'TWIG'
{% templatedesigner for 'layouts/hero' on 'https://example.com/' %}
<div class="hero">{{ title }}</div>
{% endtemplatedesigner %}
TWIG;

		$result = $this->preprocessor->preprocess($source, 'page.twig');

		$this->assertStringContainsString('_tcms_designer_sync', $result);

		$block = $this->registry->get('_designer_page_twig_0');
		$this->assertNotNull($block);
		$this->assertSame('layouts/hero', $block['template']);
		$this->assertSame('', $block['token']);
	}

	public function testMultipleBlocks(): void
	{
		$source = <<<'TWIG'
{% templatedesigner for 'header' on 'https://example.com/' token 'tok1' %}
<header>Header</header>
{% endtemplatedesigner %}
<main>Content</main>
{% templatedesigner for 'footer' on 'https://example.com/' token 'tok2' %}
<footer>Footer</footer>
{% endtemplatedesigner %}
TWIG;

		$result = $this->preprocessor->preprocess($source, 'layout.twig');

		$this->assertStringContainsString('_designer_layout_twig_0', $result);
		$this->assertStringContainsString('_designer_layout_twig_1', $result);
		$this->assertStringContainsString('<main>Content</main>', $result);

		$block0 = $this->registry->get('_designer_layout_twig_0');
		$block1 = $this->registry->get('_designer_layout_twig_1');

		$this->assertSame('header', $block0['template']);
		$this->assertSame('footer', $block1['template']);
		$this->assertSame('tok1', $block0['token']);
		$this->assertSame('tok2', $block1['token']);
	}

	public function testNoDesignerBlocks(): void
	{
		$source = '<h1>{{ title }}</h1>';

		$result = $this->preprocessor->preprocess($source, 'plain.twig');

		$this->assertSame($source, $result);
		$this->assertEmpty($this->registry->all());
	}

	public function testMultilineContent(): void
	{
		$source = <<<'TWIG'
{% templatedesigner for 'card' on 'https://prod.example.com/' token 'secret' %}
<div class="card">
    <h2>{{ object.title }}</h2>
    <p>{{ object.summary }}</p>
    {% if object.image %}
        <img src="{{ object.image }}" alt="{{ object.title }}">
    {% endif %}
</div>
{% endtemplatedesigner %}
TWIG;

		$this->preprocessor->preprocess($source, 'cards.twig');

		$block = $this->registry->get('_designer_cards_twig_0');
		$this->assertNotNull($block);
		$this->assertStringContainsString('<div class="card">', $block['content']);
		$this->assertStringContainsString('{% if object.image %}', $block['content']);
	}

	public function testDoubleQuotes(): void
	{
		$source = <<<'TWIG'
{% templatedesigner for "products/tile" on "https://example.com/tcms/" token "abc123" %}
<article>{{ object.title }}</article>
{% endtemplatedesigner %}
TWIG;

		$result = $this->preprocessor->preprocess($source, 'dq.twig');

		$this->assertStringContainsString('_tcms_designer_sync', $result);

		$block = $this->registry->get('_designer_dq_twig_0');
		$this->assertNotNull($block);
		$this->assertSame('products/tile', $block['template']);
		$this->assertSame('https://example.com/tcms/', $block['domain']);
		$this->assertSame('abc123', $block['token']);
	}

	public function testMixedQuotes(): void
	{
		$source = <<<'TWIG'
{% templatedesigner for "layouts/hero" on 'https://example.com/' token "tok" %}
<div>content</div>
{% endtemplatedesigner %}
TWIG;

		$this->preprocessor->preprocess($source, 'mixed.twig');

		$block = $this->registry->get('_designer_mixed_twig_0');
		$this->assertNotNull($block);
		$this->assertSame('layouts/hero', $block['template']);
	}

	public function testTemplateNameSanitization(): void
	{
		$source = <<<'TWIG'
{% templatedesigner for 'test' on 'https://example.com/' token 'tok' %}
content
{% endtemplatedesigner %}
TWIG;

		$this->preprocessor->preprocess($source, 'path/to/deep.twig');

		// Slashes and dots should be replaced with underscores in the key
		$block = $this->registry->get('_designer_path_to_deep_twig_0');
		$this->assertNotNull($block);
	}
}
