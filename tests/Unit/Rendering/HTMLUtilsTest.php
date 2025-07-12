<?php

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

test('htmlencode encodes all characters to HTML entities', function () {
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

test('mailtoLink creates obfuscated span with base64 encoded parts', function () {
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

test('mailtoLink handles subject parameter', function () {
	$email   = 'support@example.com';
	$subject = 'Help Request';
	$result  = HTMLUtils::mailtoLink($email, $subject);

	// Check for base64 encoded subject
	expect($result)->toContain('data-subject="SGVscCBSZXF1ZXN0"'); // 'Help Request' in base64

	// Check title attribute uses subject
	expect($result)->toContain('title="Help Request"');
});

test('mailtoLink handles all parameters', function () {
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

test('mailtoLink handles invalid email format', function () {
	$invalidEmail = 'notanemail';
	$result       = HTMLUtils::mailtoLink($invalidEmail);

	// Should return a span with invalid-email class
	expect($result)->toContain('<span');
	expect($result)->toContain('class="invalid-email"');
	expect($result)->toContain('&#110;&#111;&#116;&#97;&#110;&#101;&#109;&#97;&#105;&#108;'); // 'notanemail' encoded
});

test('mailtoLink trims whitespace from parameters', function () {
	$email   = '  test@example.com  ';
	$subject = '  Subject  ';

	$result = HTMLUtils::mailtoLink($email, $subject);

	// Check that email is trimmed
	expect($result)->toContain('data-user="dGVzdA=="'); // 'test' in base64 (trimmed)
	expect($result)->toContain('data-subject="U3ViamVjdA=="'); // 'Subject' in base64 (trimmed)
});

test('mailtoLink uses default title when not provided', function () {
	// With no subject, should use "Email"
	$result1 = HTMLUtils::mailtoLink('test@example.com');
	expect($result1)->toContain('title="Email"');

	// With subject, should use the subject as title
	$result2 = HTMLUtils::mailtoLink('test@example.com', 'Contact Us');
	expect($result2)->toContain('title="Contact Us"');
});

test('mailtoLink encodes special characters in subject', function () {
	$email   = 'test@example.com';
	$subject = 'Question & Answer';

	$result = HTMLUtils::mailtoLink($email, $subject);

	// Check that the title is double-encoded (htmlentities + htmlspecialchars)
	expect($result)->toContain('title="Question &amp;amp; Answer"');
});
