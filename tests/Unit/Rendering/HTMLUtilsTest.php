<?php

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

test('htmlencode encodes all characters to HTML entities', function (): void {
	$email   = 'test@example.com';
	$encoded = HTMLUtils::htmlencode($email);

	// Check that @ symbol is encoded
	expect($encoded)->toContain('&#64;');
	// Check that all characters are encoded
	expect($encoded)->not->toContain('test');
	expect($encoded)->not->toContain('example');
	expect($encoded)->not->toContain('.com');
	// Check the full encoded string
	expect($encoded)->toBe('&#116;&#101;&#115;&#116;&#64;&#101;&#120;&#97;&#109;&#112;&#108;&#101;&#46;&#99;&#111;&#109;');
});

test('mailtoLink creates obfuscated span with base64 encoded parts', function (): void {
	$email  = 'john@example.com';
	$result = HTMLUtils::mailtoLink($email);

	// Check it's a span, not an anchor
	expect($result)->toContain('<span');
	expect($result)->not->toContain('<a ');

	// Check for obfuscation class
	expect($result)->toContain('class="mailto-obfuscated"');

	// Check for base64 encoded parts
	expect($result)->toContain('data-user="am9obg=="'); // 'john' in base64
	expect($result)->toContain('data-domain="ZXhhbXBsZS5jb20="'); // 'example.com' in base64

	// Check that email is displayed as HTML entities
	expect($result)->toContain('&#106;&#111;&#104;&#110;&#64;'); // 'john@' encoded

	// Check styling
	expect($result)->toContain('cursor:pointer');
	expect($result)->toContain('text-decoration:underline');
});

test('mailtoLink handles subject parameter', function (): void {
	$email   = 'support@example.com';
	$subject = 'Help Request';
	$result  = HTMLUtils::mailtoLink($email, $subject);

	// Check for base64 encoded subject
	expect($result)->toContain('data-subject="SGVscCBSZXF1ZXN0"'); // 'Help Request' in base64

	// Check title attribute uses subject
	expect($result)->toContain('title="Help Request"');
});

test('mailtoLink handles all parameters', function (): void {
	$email   = 'info@example.com';
	$subject = 'Test Subject';
	$body    = 'Test Body';
	$cc      = 'cc@example.com';
	$bcc     = 'bcc@example.com';
	$title   = 'Custom Title';

	$result = HTMLUtils::mailtoLink($email, $subject, $body, $cc, $bcc, $title);

	// Check all data attributes
	expect($result)->toContain('data-user="aW5mbw=="'); // 'info' in base64
	expect($result)->toContain('data-domain="ZXhhbXBsZS5jb20="'); // 'example.com' in base64
	expect($result)->toContain('data-subject="VGVzdCBTdWJqZWN0"'); // 'Test Subject' in base64
	expect($result)->toContain('data-body="VGVzdCBCb2R5"'); // 'Test Body' in base64
	expect($result)->toContain('data-cc="Y2NAZXhhbXBsZS5jb20="'); // 'cc@example.com' in base64
	expect($result)->toContain('data-bcc="YmNjQGV4YW1wbGUuY29t"'); // 'bcc@example.com' in base64

	// Check custom title
	expect($result)->toContain('title="Custom Title"');
});

test('mailtoLink handles invalid email format', function (): void {
	$invalidEmail = 'notanemail';
	$result       = HTMLUtils::mailtoLink($invalidEmail);

	// Should return a span with invalid-email class
	expect($result)->toContain('<span');
	expect($result)->toContain('class="invalid-email"');
	expect($result)->toContain('&#110;&#111;&#116;&#97;&#110;&#101;&#109;&#97;&#105;&#108;'); // 'notanemail' encoded
});

test('mailtoLink trims whitespace from parameters', function (): void {
	$email   = '  test@example.com  ';
	$subject = '  Subject  ';

	$result = HTMLUtils::mailtoLink($email, $subject);

	// Check that email is trimmed
	expect($result)->toContain('data-user="dGVzdA=="'); // 'test' in base64 (trimmed)
	expect($result)->toContain('data-subject="U3ViamVjdA=="'); // 'Subject' in base64 (trimmed)
});

test('mailtoLink uses default title when not provided', function (): void {
	// With no subject, should use "Email"
	$result1 = HTMLUtils::mailtoLink('test@example.com');
	expect($result1)->toContain('title="Email"');

	// With subject, should use the subject as title
	$result2 = HTMLUtils::mailtoLink('test@example.com', 'Contact Us');
	expect($result2)->toContain('title="Contact Us"');
});

test('mailtoLink encodes special characters in subject', function (): void {
	$email   = 'test@example.com';
	$subject = 'Question & Answer';

	$result = HTMLUtils::mailtoLink($email, $subject);

	// Check that the title is double-encoded (htmlentities + htmlspecialchars)
	expect($result)->toContain('title="Question &amp;amp; Answer"');
});

// -------------------------
// options() tests
// -------------------------

test('options renders simple string options', function (): void {
	$result = HTMLUtils::options(['Red', 'Green', 'Blue']);

	expect($result)
		->toContain('<option value="Red">Red</option>')
		->toContain('<option value="Green">Green</option>')
		->toContain('<option value="Blue">Blue</option>');
});

test('options renders value/label pair options', function (): void {
	$options = [
		['value' => 'r', 'label' => 'Red'],
		['value' => 'g', 'label' => 'Green'],
	];

	$result = HTMLUtils::options($options);

	expect($result)
		->toContain('<option value="r">Red</option>')
		->toContain('<option value="g">Green</option>');
});

test('options marks single string selected', function (): void {
	$result = HTMLUtils::options(['Red', 'Green', 'Blue'], 'Green');

	expect($result)
		->toContain('<option value="Green" selected>Green</option>')
		->not->toContain('<option value="Red" selected');
});

test('options marks multiple selected from array', function (): void {
	$result = HTMLUtils::options(['Red', 'Green', 'Blue'], ['Red', 'Blue']);

	expect($result)
		->toContain('<option value="Red" selected>Red</option>')
		->toContain('<option value="Blue" selected>Blue</option>')
		->not->toContain('<option value="Green" selected');
});

test('options marks selected on value/label pairs', function (): void {
	$options = [
		['value' => '1', 'label' => 'One'],
		['value' => '2', 'label' => 'Two'],
		['value' => '3', 'label' => 'Three'],
	];

	$result = HTMLUtils::options($options, '2');

	expect($result)
		->toContain('<option value="2" selected>Two</option>')
		->not->toContain('<option value="1" selected')
		->not->toContain('<option value="3" selected');
});

test('options renders optgroups when string keys map to arrays', function (): void {
	$options = [
		'Fruits'     => ['Apple', 'Banana'],
		'Vegetables' => ['Carrot', 'Pea'],
	];

	$result = HTMLUtils::options($options);

	expect($result)
		->toContain('<optgroup label="Fruits">')
		->toContain('<option value="Apple">Apple</option>')
		->toContain('<option value="Banana">Banana</option>')
		->toContain('</optgroup>')
		->toContain('<optgroup label="Vegetables">')
		->toContain('<option value="Carrot">Carrot</option>');
});

test('options renders optgroups with value/label pairs', function (): void {
	$options = [
		'Colors' => [
			['value' => 'r', 'label' => 'Red'],
			['value' => 'b', 'label' => 'Blue'],
		],
	];

	$result = HTMLUtils::options($options, 'b');

	expect($result)
		->toContain('<optgroup label="Colors">')
		->toContain('<option value="r">Red</option>')
		->toContain('<option value="b" selected>Blue</option>');
});

test('options handles mixed flat and grouped options', function (): void {
	$options = [
		'standalone',
		'Group' => ['Grouped1', 'Grouped2'],
	];

	$result = HTMLUtils::options($options);

	expect($result)
		->toContain('<option value="standalone">standalone</option>')
		->toContain('<optgroup label="Group">');
});

test('options with empty array returns empty string', function (): void {
	expect(HTMLUtils::options([]))->toBe('');
});

test('options with empty selected string selects nothing', function (): void {
	$result = HTMLUtils::options(['A', 'B'], '');

	expect($result)->not->toContain('selected');
});

test('options with empty selected array selects nothing', function (): void {
	$result = HTMLUtils::options(['A', 'B'], []);

	expect($result)->not->toContain('selected');
});

test('options escapes HTML in labels', function (): void {
	$options = [
		['value' => 'xss', 'label' => '<script>alert("XSS")</script>'],
	];

	$result = HTMLUtils::options($options);

	expect($result)
		->toContain('&lt;script&gt;')
		->not->toContain('<script>');
});

test('options uses value as label when label is missing', function (): void {
	$options = [
		['value' => 'fallback'],
	];

	$result = HTMLUtils::options($options);

	expect($result)->toContain('<option value="fallback">fallback</option>');
});

test('options uses strict comparison for selected', function (): void {
	// '0' should not match '' and vice versa
	$result = HTMLUtils::options(['0', '1', '2'], '0');

	expect($result)
		->toContain('<option value="0" selected>0</option>')
		->not->toContain('<option value="1" selected')
		->not->toContain('<option value="2" selected');
});

// -------------------------
// optgroup() tests
// -------------------------

test('optgroup wraps options in optgroup element', function (): void {
	$result = HTMLUtils::optgroup('Sizes', ['Small', 'Medium', 'Large']);

	expect($result)
		->toStartWith('<optgroup label="Sizes">')
		->toContain('<option value="Small">Small</option>')
		->toContain('<option value="Large">Large</option>')
		->toEndWith('</optgroup>');
});

test('optgroup marks selected values', function (): void {
	$result = HTMLUtils::optgroup('Sizes', ['Small', 'Medium', 'Large'], ['Medium']);

	expect($result)
		->toContain('<option value="Medium" selected>Medium</option>')
		->not->toContain('<option value="Small" selected');
});

// -------------------------
// select() tests
// -------------------------

test('select builds complete select element', function (): void {
	$result = HTMLUtils::select(['A', 'B', 'C'], '', '', ['name' => 'letter']);

	expect($result)
		->toStartWith('<select name="letter">')
		->toContain('<option value="A">A</option>')
		->toEndWith('</select>');
});

test('select with placeholder adds disabled placeholder option', function (): void {
	$result = HTMLUtils::select(['A', 'B'], '', 'Choose one...', ['name' => 'test']);

	// value="" renders as boolean attr since buildHTMLAttributes treats '' as boolean
	expect($result)
		->toContain('<option value disabled selected>Choose one...</option>')
		->toContain('<option value="A">A</option>');
});

test('select placeholder is not selected when value is set', function (): void {
	$result = HTMLUtils::select(['A', 'B'], 'A', 'Choose one...');

	// Placeholder should NOT be selected
	expect($result)->toContain('<option value disabled>Choose one...</option>');
	expect($result)->not->toContain('<option value disabled selected');
	// Actual value should be selected
	expect($result)->toContain('<option value="A" selected>A</option>');
});

test('select placeholder not selected when array selected is non-empty', function (): void {
	$result = HTMLUtils::select(['A', 'B'], ['A'], 'Choose...');

	expect($result)->toContain('<option value disabled>Choose...</option>');
	expect($result)->not->toContain('<option value disabled selected');
	expect($result)->toContain('<option value="A" selected>A</option>');
});

test('select with attributes passes them through', function (): void {
	$result = HTMLUtils::select(['X'], '', '', [
		'id'       => 'my-select',
		'class'    => 'form-control',
		'required' => '',
	]);

	expect($result)
		->toContain('id="my-select"')
		->toContain('class="form-control"')
		->toContain('required');
});

test('select with optgroups', function (): void {
	$options = [
		'Primary'   => ['Red', 'Blue', 'Yellow'],
		'Secondary' => ['Green', 'Orange', 'Purple'],
	];

	$result = HTMLUtils::select($options, 'Blue', 'Pick a color', ['name' => 'color']);

	expect($result)
		->toContain('<select name="color">')
		->toContain('Pick a color')
		->toContain('<optgroup label="Primary">')
		->toContain('<option value="Blue" selected>Blue</option>')
		->toContain('<optgroup label="Secondary">');
});

test('select escapes placeholder HTML', function (): void {
	$result = HTMLUtils::select(['A'], '', '<b>Choose</b>');

	expect($result)
		->toContain('&lt;b&gt;Choose&lt;/b&gt;')
		->not->toContain('<b>Choose</b>');
});

test('select with no placeholder and no options renders empty select', function (): void {
	$result = HTMLUtils::select([]);

	expect($result)->toBe('<select></select>');
});

// -------------------------
// datalist() tests
// -------------------------

test('datalist renders with id and options', function (): void {
	$result = HTMLUtils::datalist('my-list', ['Cat', 'Dog', 'Fish']);

	expect($result)
		->toContain('<datalist id="my-list">')
		->toContain('<option value="Cat">Cat</option>')
		->toContain('<option value="Dog">Dog</option>')
		->toContain('</datalist>');
});

test('datalist with value/label pairs', function (): void {
	$result = HTMLUtils::datalist('sizes', [
		['value' => 's', 'label' => 'Small'],
		['value' => 'l', 'label' => 'Large'],
	]);

	expect($result)
		->toContain('<datalist id="sizes">')
		->toContain('<option value="s">Small</option>')
		->toContain('<option value="l">Large</option>');
});

// -------------------------
// element() and buildHTMLAttributes() tests
// -------------------------

test('element builds tag with content and attributes', function (): void {
	$result = HTMLUtils::element('div', 'Hello', ['class' => 'box', 'id' => 'main']);

	expect($result)->toBe('<div class="box" id="main">Hello</div>');
});

test('element with no attributes', function (): void {
	expect(HTMLUtils::element('p', 'Text'))->toBe('<p>Text</p>');
});

test('buildHTMLAttributes skips null values', function (): void {
	$result = HTMLUtils::buildHTMLAttributes(['class' => 'test', 'id' => null]);

	expect($result)
		->toContain('class="test"')
		->not->toContain('id');
});

test('buildHTMLAttributes skips false values', function (): void {
	$result = HTMLUtils::buildHTMLAttributes(['disabled' => false]);

	expect($result)->toBe('');
});

test('buildHTMLAttributes renders boolean true as attribute without value', function (): void {
	$result = HTMLUtils::buildHTMLAttributes(['disabled' => true]);

	expect($result)->toBe(' disabled');
});

test('buildHTMLAttributes renders empty string as boolean attribute', function (): void {
	$result = HTMLUtils::buildHTMLAttributes(['selected' => '']);

	expect($result)->toBe(' selected');
});

test('buildHTMLAttributes escapes special characters in values', function (): void {
	$result = HTMLUtils::buildHTMLAttributes(['title' => 'Say "hello" & goodbye']);

	expect($result)->toBe(' title="Say &quot;hello&quot; &amp; goodbye"');
});
