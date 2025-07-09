<?php

use TotalCMS\Domain\Twig\Extension\CmsGridTokenParser;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Parser;
use Twig\Token;

beforeEach(function () {
	$this->loader      = new ArrayLoader([]);
	$this->twig        = new Environment($this->loader);
	$this->parser      = new Parser($this->twig);
	$this->tokenParser = new CmsGridTokenParser();
	$this->tokenParser->setParser($this->parser);
});

test('token parser has correct tag name', function () {
	expect($this->tokenParser->getTag())->toBe('cmsgrid');
});

test('decide block end recognizes endcmsgrid', function () {
	$token = new Token(Token::NAME_TYPE, 'endcmsgrid', 1);
	expect($this->tokenParser->decideBlockEnd($token))->toBeTrue();

	$otherToken = new Token(Token::NAME_TYPE, 'endif', 1);
	expect($this->tokenParser->decideBlockEnd($otherToken))->toBeFalse();
});

test('parser can be instantiated without errors', function () {
	expect($this->tokenParser)->toBeInstanceOf(CmsGridTokenParser::class);
	expect($this->tokenParser->getTag())->toBe('cmsgrid');
});

test('token parser extends AbstractTokenParser', function () {
	expect($this->tokenParser)->toBeInstanceOf(Twig\TokenParser\AbstractTokenParser::class);
});

// Integration test to verify the token parser works with Twig
test('cmsgrid tag can be registered with twig', function () {
	$loader = new ArrayLoader([
		'test' => '{% cmsgrid objects from "blog" with "grid" %}{{ item.title }}{% endcmsgrid %}',
	]);

	$twig = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	// This should not throw an exception during compilation
	$template = $twig->load('test');
	expect($template)->toBeInstanceOf(Twig\TemplateWrapper::class);
});

test('cmsgrid tag compiles with minimal syntax', function () {
	$loader = new ArrayLoader([
		'minimal' => '{% cmsgrid objects %}{{ item.title }}{% endcmsgrid %}',
	]);

	$twig = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$template = $twig->load('minimal');
	expect($template)->toBeInstanceOf(Twig\TemplateWrapper::class);
});

test('cmsgrid tag compiles with full syntax', function () {
	$loader = new ArrayLoader([
		'full' => '{% cmsgrid objects from "blog" with "grid compact" as "article" %}{{ item.title }}{% endcmsgrid %}',
	]);

	$twig = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$template = $twig->load('full');
	expect($template)->toBeInstanceOf(Twig\TemplateWrapper::class);
});

test('cmsgrid tag compiles with partial syntax variations', function () {
	$variations = [
		'{% cmsgrid objects with "classes" %}{{ item.title }}{% endcmsgrid %}',
		'{% cmsgrid objects as "div" %}{{ item.title }}{% endcmsgrid %}',
		'{% cmsgrid objects from "blog" %}{{ item.title }}{% endcmsgrid %}',
		'{% cmsgrid objects from "blog" with "classes" %}{{ item.title }}{% endcmsgrid %}',
		'{% cmsgrid objects with "classes" as "span" %}{{ item.title }}{% endcmsgrid %}',
	];

	foreach ($variations as $index => $template) {
		$loader = new ArrayLoader([
			"test_$index" => $template,
		]);

		$twig = new Environment($loader);
		$twig->addTokenParser(new CmsGridTokenParser());

		$compiledTemplate = $twig->load("test_$index");
		expect($compiledTemplate)->toBeInstanceOf(Twig\TemplateWrapper::class);
	}
});

test('cmsgrid syntax error handling', function () {
	$loader = new ArrayLoader([
		'invalid' => '{% cmsgrid %}{% endcmsgrid %}', // Missing objects parameter
	]);

	$twig = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	// This should throw a Twig syntax error
	expect(fn () => $twig->load('invalid'))
		->toThrow(Twig\Error\SyntaxError::class);
});

test('cmsgrid missing end tag handling', function () {
	$loader = new ArrayLoader([
		'no_end' => '{% cmsgrid objects %}{{ item.title }}', // Missing {% endcmsgrid %}
	]);

	$twig = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	// This should throw a Twig syntax error
	expect(fn () => $twig->load('no_end'))
		->toThrow(Twig\Error\SyntaxError::class);
});
