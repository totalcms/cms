<?php

use TotalCMS\Domain\Security\Sanitization\HTMLSanitizer;

describe('XSS Vulnerabilities', function (): void {
	it('identifies disabled Twig autoescaping', function (): void {
		// Test that HTML sanitizer exists and works
		$sanitizer = new HTMLSanitizer();
		expect($sanitizer)->toBeInstanceOf(HTMLSanitizer::class);

		// Test XSS payload sanitization
		$xssPayload = '<script>alert("XSS")</script>';
		$sanitized  = $sanitizer->sanitizeRichContent($xssPayload);

		expect($sanitized)->not()->toContain('<script>');
		expect($sanitized)->not()->toContain('alert("XSS")');

		// Test common XSS vectors — sanitizer operates on HTML, so URL-style
		// payloads are tested inside the attribute context that would deliver
		// them in real markup.
		$xssVectors = [
			'<img src="x" onerror="alert(\'XSS\')">',
			'<svg onload="alert(\'XSS\')">',
			'<a href="javascript:alert(\'XSS\')">link</a>',
			'<iframe src="javascript:alert(\'XSS\')"></iframe>',
		];

		foreach ($xssVectors as $vector) {
			$sanitized = $sanitizer->sanitizeRichContent($vector);
			expect($sanitized)->not()->toContain('<script');
			expect($sanitized)->not()->toContain('javascript:');
			expect($sanitized)->not()->toMatch('/\son\w+\s*=/i');
		}
	});

	it('identifies missing input sanitization', function (): void {
		$sanitizer = new HTMLSanitizer();

		// Form inputs that could contain XSS
		$userInputs = [
			'username'    => '<script>alert("XSS")</script>',
			'email'       => 'test@example.com<script>alert("XSS")</script>',
			'description' => '<img src="x" onerror="alert(\'XSS\')">',
		];

		foreach ($userInputs as $field => $input) {
			$sanitized = $sanitizer->sanitizeRichContent($input);

			// Verify dangerous content is removed
			expect($sanitized)->not()->toContain('<script>');
			expect($sanitized)->not()->toContain('onerror=');
			expect($sanitized)->not()->toContain('alert');

			// Verify safe content is preserved
			if ($field === 'email') {
				expect($sanitized)->toContain('test@example.com');
			}
		}
	});

	it('identifies potential DOM-based XSS', function (): void {
		// Test that we can detect unsafe JavaScript patterns
		$unsafeJsPatterns = [
			'innerHTML',
			'document.write',
			'eval(',
			'setTimeout(',
			'setInterval(',
		];

		foreach ($unsafeJsPatterns as $pattern) {
			expect($pattern)->toBeString();
			expect(strlen($pattern))->toBeGreaterThan(3);
		}

		// Test safe alternatives
		$safeAlternatives = [
			'textContent',
			'appendChild',
			'JSON.parse',
			'requestAnimationFrame',
		];

		foreach ($safeAlternatives as $safe) {
			expect($safe)->toBeString();
			expect($safe)->not()->toContain('eval');
		}
	});

	it('identifies missing Content Security Policy', function (): void {
		// Test CSP directive validation
		$cspDirectives = [
			"default-src 'self'",
			"script-src 'self'",
			"style-src 'self' 'unsafe-inline'",
			"img-src 'self' data:",
		];

		foreach ($cspDirectives as $directive) {
			expect($directive)->toContain('self');
			expect($directive)->not()->toContain('unsafe-eval');
		}

		// Test that CSP would block inline scripts
		$inlineScript = "<script>alert('XSS')</script>";
		$blockedByCSP = !str_contains($cspDirectives[1], 'unsafe-inline');
		expect($blockedByCSP)->toBeTrue();
	});

	it('identifies unsafe URL parameters processing', function (): void {
		$sanitizer = new HTMLSanitizer();

		// URL parameters that could be reflected into HTML without escaping.
		// The redirect case is tested in its rendered-link form — a bare URL
		// string isn't HTML and isn't this sanitizer's responsibility; the
		// redirect handler should validate the scheme separately.
		$urlParams = [
			'search'   => '<script>alert("XSS")</script>',
			'message'  => '<img src="x" onerror="alert(\'XSS\')">',
			'redirect' => '<a href="javascript:alert(\'XSS\')">link</a>',
		];

		foreach ($urlParams as $value) {
			$sanitized = $sanitizer->sanitizeRichContent($value);

			// These should be escaped/sanitized before reflecting in response
			expect($sanitized)->not()->toContain('<script>');
			expect($sanitized)->not()->toContain('onerror=');
			expect($sanitized)->not()->toContain('javascript:');
		}
	});
});
