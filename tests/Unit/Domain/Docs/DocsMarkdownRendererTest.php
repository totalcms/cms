<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Docs;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Docs\Service\DocsMarkdownRenderer;

final class DocsMarkdownRendererTest extends TestCase
{
	public function testFrontMatterBodyAndTocComeBackTogether(): void
	{
		$page = (new DocsMarkdownRenderer())->render("---\ntitle: Hello\n---\n\n## First Step\n\ntext\n\n### Deeper\n\n## First Step\n");

		$this->assertSame('Hello', $page['data']['title']);
		$this->assertStringContainsString('<h2 id="first-step">First Step</h2>', $page['content']);
		$this->assertStringContainsString('<h3 id="deeper">Deeper</h3>', $page['content']);
		$this->assertStringContainsString('<h2 id="first-step-2">First Step</h2>', $page['content'], 'duplicates are numbered');
		$this->assertSame(
			[['level' => 2, 'id' => 'first-step', 'text' => 'First Step'], ['level' => 3, 'id' => 'deeper', 'text' => 'Deeper'], ['level' => 2, 'id' => 'first-step-2', 'text' => 'First Step']],
			$page['toc'],
		);
	}

	public function testAnExplicitHeadingIdIsKept(): void
	{
		$page = (new DocsMarkdownRenderer())->render("## Custom {#my-id}\n");

		$this->assertStringContainsString('<h2 id="my-id">Custom</h2>', $page['content']);
		$this->assertSame('my-id', $page['toc'][0]['id']);
	}

	public function testEscapedPipesInsideTableCellsAreUnescapedNowhereElse(): void
	{
		$page = (new DocsMarkdownRenderer())->render("| a | b |\n|---|---|\n| `x \\| y` | z |\n\nOutside `a \\| b`\n");

		$this->assertStringContainsString('<code>x | y</code>', $page['content']);
		$this->assertStringContainsString('a \\| b', $page['content']);
	}

	public function testTheTitleIsTheFirstH1ThenTheFrontMatter(): void
	{
		$renderer = new DocsMarkdownRenderer();

		$this->assertSame('Heading', $renderer->render("---\ntitle: Meta\n---\n\n# Heading\n\n## Sub\n")['title']);
		$this->assertSame('Meta', $renderer->render("---\ntitle: Meta\n---\n\n## Sub\n")['title']);
		$this->assertSame('', $renderer->render("## Sub\n")['title']);
	}

	public function testSearchTextFlattensTheRenderedPage(): void
	{
		// The search index wants what the reader sees — no front matter, no
		// tags, code kept, entities decoded, whitespace collapsed.
		$page = (new DocsMarkdownRenderer())->render("---\ntitle: Meta\n---\n\n# Title\n\nUse `cms.login()` &amp; <b>go</b>.\n\n```twig\n{{ x }}\n```\n");

		$this->assertSame('Title Use cms.login() & go. {{ x }}', DocsMarkdownRenderer::searchText($page['content']));
	}
}
