<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

test('mailto filter creates obfuscated email link', function () {
	$email  = 'test@example.com';
	$result = TotalCMSTwigFilters::mailto($email);

	// Check it returns a string
	expect($result)->toBeString();

	// Check for obfuscation markers
	expect($result)->toContain('mailto-obfuscated');
	expect($result)->toContain('data-user=');
	expect($result)->toContain('data-domain=');

	// Check email is not in plain text
	expect($result)->not->toContain('test@example.com');
	expect($result)->not->toContain('mailto:test@example.com');
});

test('mailto filter handles parameters', function () {
	$email   = 'contact@example.com';
	$subject = 'Inquiry';
	$body    = 'I have a question...';
	$title   = 'Contact Us';

	$result = TotalCMSTwigFilters::mailto($email, $subject, $body, $title);

	// Check for encoded parameters
	expect($result)->toContain('data-subject=');
	expect($result)->toContain('data-body=');
	expect($result)->toContain('title="Contact Us"');
});

test('htmlencode filter encodes email addresses', function () {
	$email  = 'admin@site.com';
	$result = TotalCMSTwigFilters::htmlencode($email);

	// Check that @ is encoded
	expect($result)->toContain('&#64;');

	// Check that the original email is not present
	expect($result)->not->toContain('admin@site.com');
	expect($result)->not->toContain('@');

	// Verify all characters are encoded
	expect($result)->toBe('&#97;&#100;&#109;&#105;&#110;&#64;&#115;&#105;&#116;&#101;&#46;&#99;&#111;&#109;');
});
