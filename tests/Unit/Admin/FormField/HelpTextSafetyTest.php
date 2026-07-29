<?php

use TotalCMS\Domain\Admin\FormField\TextField;
use TotalCMS\Domain\Admin\TotalForm;

// Help text is intentionally HTML — core schemas use <code>, <strong>, and
// links to explain a field. The exception is raw-text tags: a schema
// documenting the `<title>` tag emitted a real <title>, and because that
// element's content model is raw text the browser swallowed the rest of the
// admin page, silently truncating the form from that field down with nothing
// in the logs. Those tags are escaped; everything else passes through.

describe('FormField help text', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 123;
	});

	test('escapes a raw <title> tag so it cannot swallow the rest of the page', function (): void {
		$field = new TextField($this->form, 'title', help: 'The <title> tag goes here.');

		expect($field->build())
			->toContain('&lt;title&gt;')
			->not->toContain('<title>');
	});

	test('leaves ordinary inline HTML in help text untouched', function (): void {
		$field = new TextField($this->form, 'route', help: 'Exposed as <code>page.data.*</code> — see <a href="docs/x">the docs</a>.');

		expect($field->build())
			->toContain('<code>page.data.*</code>')
			->toContain('<a href="docs/x">');
	});

	test('escapes every raw-text tag, opening and closing, in any case', function (): void {
		foreach (['title', 'textarea', 'script', 'style', 'iframe', 'xmp', 'noscript', 'plaintext'] as $tag) {
			$field = new TextField($this->form, 'f', help: "Open <{$tag}> and close </{$tag}> and upper <" . strtoupper($tag) . '>.');

			$html = $field->build();

			expect($html)->not->toContain("<{$tag}>");
			expect($html)->not->toContain("</{$tag}>");
			expect($html)->not->toContain('<' . strtoupper($tag) . '>');
		}
	});

	test('escapes a raw-text tag that carries attributes', function (): void {
		$field = new TextField($this->form, 'f', help: 'Bad <script src="x.js"> here.');

		expect($field->build())
			->not->toContain('<script src=')
			->toContain('&lt;script src=');
	});

	test('renders no help paragraph when help is empty', function (): void {
		$field = new TextField($this->form, 'f');

		expect($field->build())->not->toContain('class="help"');
	});
});
