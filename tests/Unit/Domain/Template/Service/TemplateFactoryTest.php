<?php

namespace Tests\Unit\Domain\Template\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Service\TemplateFactory;

final class TemplateFactoryTest extends TestCase
{
	public function testGenerateTemplateCreatesTemplateData(): void
	{
		$result = TemplateFactory::generateTemplate('test-template', '<h1>Hello</h1>');

		$this->assertInstanceOf(TemplateData::class, $result);
	}

	public function testGenerateTemplateSetsId(): void
	{
		$result = TemplateFactory::generateTemplate('my-template-id', '<p>Content</p>');

		$this->assertSame('my-template-id', $result->id);
	}

	public function testGenerateTemplateSetsContents(): void
	{
		$templateContent = '<div class="wrapper">{{ content }}</div>';
		$result          = TemplateFactory::generateTemplate('wrapper', $templateContent);

		$this->assertSame($templateContent, $result->contents);
	}

	public function testGenerateTemplateWithEmptyId(): void
	{
		$result = TemplateFactory::generateTemplate('', '<p>Test</p>');

		$this->assertSame('', $result->id);
	}

	public function testGenerateTemplateWithEmptyContents(): void
	{
		$result = TemplateFactory::generateTemplate('empty-template', '');

		$this->assertSame('', $result->contents);
	}

	public function testGenerateTemplateWithComplexTwigTemplate(): void
	{
		$complexTemplate = <<<TWIG
{% extends "base.twig" %}
{% block content %}
  <h1>{{ title }}</h1>
  {% for item in items %}
    <div>{{ item.name }}: {{ item.value }}</div>
  {% endfor %}
{% endblock %}
TWIG;

		$result = TemplateFactory::generateTemplate('complex', $complexTemplate);

		$this->assertSame($complexTemplate, $result->contents);
	}

	public function testGenerateTemplateWithSpecialCharacters(): void
	{
		$templateWithSpecials = '<p>Price: €100 &amp; £50 — "Special" \'chars\'</p>';
		$result               = TemplateFactory::generateTemplate('special', $templateWithSpecials);

		$this->assertSame($templateWithSpecials, $result->contents);
	}

	public function testGenerateTemplateWithUnicodeCharacters(): void
	{
		$unicodeTemplate = '<p>日本語テスト 中文測試 한국어 테스트</p>';
		$result          = TemplateFactory::generateTemplate('unicode', $unicodeTemplate);

		$this->assertSame($unicodeTemplate, $result->contents);
	}
}
