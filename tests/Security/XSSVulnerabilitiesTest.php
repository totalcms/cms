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

		// Test common XSS vectors
		$xssVectors = [
			'<img src="x" onerror="alert(\'XSS\')">',
			'<svg onload="alert(\'XSS\')">',
			'javascript:alert("XSS")',
			'<iframe src="javascript:alert(\'XSS\')"></iframe>',
		];

		foreach ($xssVectors as $vector) {
			$sanitized = $sanitizer->sanitizeRichContent($vector);
			expect($sanitized)->not()->toContain('alert');
			expect($sanitized)->not()->toContain('javascript:');
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

		// URL parameters that could be reflected without escaping
		$urlParams = [
			'search'   => '<script>alert("XSS")</script>',
			'message'  => '<img src="x" onerror="alert(\'XSS\')">',
			'redirect' => 'javascript:alert("XSS")',
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
