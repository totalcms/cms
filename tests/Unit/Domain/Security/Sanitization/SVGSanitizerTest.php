<?php

use TotalCMS\Domain\Security\Sanitization\SVGSanitizer;

describe('SVGSanitizer', function (): void {
	// -------------------------
	// Basic Sanitization
	// -------------------------

	test('SVGSanitizer → sanitize returns empty string for empty input', function (): void {
		expect(SVGSanitizer::sanitize(''))->toBe('');
	});

	test('SVGSanitizer → sanitize handles valid SVG content', function (): void {
		$validSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';
		$result   = SVGSanitizer::sanitize($validSvg);

		expect($result)->toBeString();
		expect($result)->not->toBe('');
		expect($result)->toContain('<svg');
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → sanitize removes dangerous script elements', function (): void {
		$dangerousSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><circle cx="12" cy="12" r="10"/></svg>';
		$result       = SVGSanitizer::sanitize($dangerousSvg);

		expect($result)->not->toContain('<script>');
		expect($result)->not->toContain('alert');
		expect($result)->toContain('<circle'); // Safe content preserved
	});

	test('SVGSanitizer → sanitize removes dangerous event handlers', function (): void {
		$dangerousSvg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" onclick="alert(\'xss\')"/></svg>';
		$result       = SVGSanitizer::sanitize($dangerousSvg);

		expect($result)->not->toContain('onclick');
		expect($result)->not->toContain('alert');
		expect($result)->toContain('<circle'); // Element preserved, just attributes removed
	});

	test('SVGSanitizer → sanitize removes foreign elements', function (): void {
		$mixedSvg = '<svg xmlns="http://www.w3.org/2000/svg"><div>Not SVG</div><circle cx="12" cy="12" r="10"/></svg>';
		$result   = SVGSanitizer::sanitize($mixedSvg);

		expect($result)->not->toContain('<div>');
		expect($result)->not->toContain('Not SVG');
		expect($result)->toContain('<circle'); // Valid SVG elements preserved
	});

	test('SVGSanitizer → sanitize handles malformed SVG gracefully', function (): void {
		$malformedSvg = '<svg><circle cx="12" cy="12" r="10"</svg>'; // Missing closing >
		$result       = SVGSanitizer::sanitize($malformedSvg);

		// Should not crash and return some result (empty or fixed)
		expect($result)->toBeString();
	});

	test('SVGSanitizer → sanitize returns empty string for completely invalid content', function (): void {
		$invalidContent = 'This is not SVG at all';
		$result         = SVGSanitizer::sanitize($invalidContent);

		expect($result)->toBe('');
	});

	// -------------------------
	// SVG Validation
	// -------------------------

	test('SVGSanitizer → isValidSvg returns false for empty string', function (): void {
		expect(SVGSanitizer::isValidSvg(''))->toBe(false);
	});

	test('SVGSanitizer → isValidSvg validates correct SVG structure', function (): void {
		$validSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';

		expect(SVGSanitizer::isValidSvg($validSvg))->toBe(true);
	});

	test('SVGSanitizer → isValidSvg validates minimal SVG', function (): void {
		$minimalSvg = '<svg></svg>';

		expect(SVGSanitizer::isValidSvg($minimalSvg))->toBe(true);
	});

	test('SVGSanitizer → isValidSvg rejects non-SVG XML', function (): void {
		$xmlNotSvg = '<root><item>content</item></root>';

		expect(SVGSanitizer::isValidSvg($xmlNotSvg))->toBe(false);
	});

	test('SVGSanitizer → isValidSvg rejects malformed XML', function (): void {
		$malformedXml = '<svg><circle cx="12" cy="12" r="10"</svg>'; // Missing closing >

		expect(SVGSanitizer::isValidSvg($malformedXml))->toBe(false);
	});

	test('SVGSanitizer → isValidSvg rejects plain text', function (): void {
		$plainText = 'This is just plain text';

		expect(SVGSanitizer::isValidSvg($plainText))->toBe(false);
	});

	test('SVGSanitizer → isValidSvg handles complex valid SVG', function (): void {
		$complexSvg = '
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
				<defs>
					<linearGradient id="grad1">
						<stop offset="0%" stop-color="red"/>
						<stop offset="100%" stop-color="blue"/>
					</linearGradient>
				</defs>
				<rect x="10" y="10" width="80" height="80" fill="url(#grad1)"/>
				<circle cx="50" cy="50" r="20" fill="white"/>
				<text x="50" y="55" text-anchor="middle">Test</text>
			</svg>
		';

		expect(SVGSanitizer::isValidSvg($complexSvg))->toBe(true);
	});

	test('SVGSanitizer → isValidSvg restores libxml error settings', function (): void {
		// Set a custom error handler state
		$originalState = libxml_use_internal_errors(false);

		// Call isValidSvg which should restore the original state
		SVGSanitizer::isValidSvg('<svg></svg>');

		// Check that the original state was restored
		$currentState = libxml_use_internal_errors($originalState);
		expect($currentState)->toBe(false);
	});

	// -------------------------
	// Sanitize and Validate Combined
	// -------------------------

	test('SVGSanitizer → sanitizeAndValidate returns empty string for empty input', function (): void {
		expect(SVGSanitizer::sanitizeAndValidate(''))->toBe('');
	});

	test('SVGSanitizer → sanitizeAndValidate handles valid SVG', function (): void {
		$validSvg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10"/></svg>';
		$result   = SVGSanitizer::sanitizeAndValidate($validSvg);

		expect($result)->toBeString();
		expect($result)->not->toBe('');
		expect($result)->toContain('<svg');
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → sanitizeAndValidate sanitizes and validates', function (): void {
		$svgWithDangerousContent = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><circle cx="12" cy="12" r="10"/></svg>';
		$result                  = SVGSanitizer::sanitizeAndValidate($svgWithDangerousContent);

		expect($result)->not->toContain('<script>');
		expect($result)->not->toContain('alert');
		expect($result)->toContain('<circle');
		// Should still be valid SVG after sanitization
		expect(SVGSanitizer::isValidSvg($result))->toBe(true);
	});

	test('SVGSanitizer → sanitizeAndValidate throws exception for invalid SVG after sanitization', function (): void {
		// Content that might become invalid after sanitization
		$problematicSvg = '<not-svg>This will be cleaned to invalid content</not-svg>';

		expect(fn (): string => SVGSanitizer::sanitizeAndValidate($problematicSvg))
			->toThrow(InvalidArgumentException::class, 'Invalid SVG content after sanitization');
	});

	// -------------------------
	// Security Tests
	// -------------------------

	test('SVGSanitizer → handles external resource references', function (): void {
		$svgWithExternalRef = '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://evil.com/malicious.jpg"/></svg>';
		$result             = SVGSanitizer::sanitize($svgWithExternalRef);

		// The sanitizer might keep the element but processes it - verify it doesn't crash
		expect($result)->toBeString();
		expect($result)->toContain('<svg');
		// The specific behavior may vary based on sanitizer version, but it should not crash
	});

	test('SVGSanitizer → removes javascript URLs', function (): void {
		$svgWithJsUrl = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(\'xss\')"><circle cx="12" cy="12" r="10"/></a></svg>';
		$result       = SVGSanitizer::sanitize($svgWithJsUrl);

		expect($result)->not->toContain('javascript:');
		expect($result)->not->toContain('alert');
	});

	test('SVGSanitizer → removes data URLs with scripts', function (): void {
		$svgWithDataUrl = '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/svg+xml;base64,PHNjcmlwdD5hbGVydCgneHNzJyk8L3NjcmlwdD4="/></svg>';
		$result         = SVGSanitizer::sanitize($svgWithDataUrl);

		// Should not contain the dangerous data URL
		expect($result)->not->toContain('PHNjcmlwdD5hbGVydCgneHNzJyk8L3NjcmlwdD4');
	});

	test('SVGSanitizer → preserves safe SVG elements and attributes', function (): void {
		$safeSvg = '
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
				<rect x="10" y="10" width="30" height="30" fill="red" stroke="blue" stroke-width="2"/>
				<circle cx="70" cy="30" r="15" fill="green" opacity="0.8"/>
				<ellipse cx="30" cy="70" rx="20" ry="10" fill="yellow"/>
				<line x1="60" y1="60" x2="90" y2="90" stroke="purple" stroke-width="3"/>
				<polyline points="10,90 30,80 50,90" fill="none" stroke="orange"/>
				<polygon points="70,60 90,60 80,80" fill="pink"/>
				<path d="M 10 10 L 20 20 L 10 30 Z" fill="cyan"/>
				<text x="50" y="95" font-family="Arial" font-size="12" fill="black">Test</text>
			</svg>
		';
		$result = SVGSanitizer::sanitize($safeSvg);

		// All safe elements should be preserved
		expect($result)->toContain('<rect');
		expect($result)->toContain('<circle');
		expect($result)->toContain('<ellipse');
		expect($result)->toContain('<line');
		expect($result)->toContain('<polyline');
		expect($result)->toContain('<polygon');
		expect($result)->toContain('<path');
		expect($result)->toContain('<text');

		// Safe attributes should be preserved
		expect($result)->toContain('viewBox');
		expect($result)->toContain('fill=');
		expect($result)->toContain('stroke=');
		expect($result)->toContain('x=');
		expect($result)->toContain('y=');
	});

	// -------------------------
	// Edge Cases and Error Handling
	// -------------------------

	test('SVGSanitizer → handles whitespace-only input', function (): void {
		$whitespace = "   \t\n   ";

		expect(SVGSanitizer::sanitize($whitespace))->toBe('');
		expect(SVGSanitizer::isValidSvg($whitespace))->toBe(false);
	});

	test('SVGSanitizer → handles very large SVG content', function (): void {
		// Create a large SVG with many elements
		$largeSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000">';
		for ($i = 0; $i < 1000; $i++) {
			$x = $i % 100 * 10;
			$y = floor($i / 100) * 10;
			$largeSvg .= "<circle cx=\"$x\" cy=\"$y\" r=\"2\" fill=\"red\"/>";
		}
		$largeSvg .= '</svg>';

		$result = SVGSanitizer::sanitize($largeSvg);

		expect($result)->toBeString();
		expect($result)->toContain('<svg');
		expect($result)->toContain('<circle');
		expect(SVGSanitizer::isValidSvg($result))->toBe(true);
	});

	test('SVGSanitizer → handles SVG with CDATA sections', function (): void {
		$svgWithCData = '
			<svg xmlns="http://www.w3.org/2000/svg">
				<text><![CDATA[Some text content]]></text>
				<circle cx="12" cy="12" r="10"/>
			</svg>
		';

		$result = SVGSanitizer::sanitize($svgWithCData);

		expect($result)->toBeString();
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → handles SVG with comments', function (): void {
		$svgWithComments = '
			<svg xmlns="http://www.w3.org/2000/svg">
				<!-- This is a comment -->
				<circle cx="12" cy="12" r="10"/>
				<!-- Another comment -->
			</svg>
		';

		$result = SVGSanitizer::sanitize($svgWithComments);

		expect($result)->toBeString();
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → singleton sanitizer instance is reused', function (): void {
		// This test verifies the singleton pattern works by calling sanitize multiple times
		// The sanitizer should be created once and reused
		$svg = '<svg><circle cx="12" cy="12" r="10"/></svg>';

		$result1 = SVGSanitizer::sanitize($svg);
		$result2 = SVGSanitizer::sanitize($svg);

		expect($result1)->toBe($result2);
		expect($result1)->toContain('<circle');
	});

	// -------------------------
	// Remove Empty Groups
	// -------------------------

	test('SVGSanitizer → removeEmptyGroups removes empty g elements', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><g></g><circle cx="12" cy="12" r="10"/></svg>';
		$result = SVGSanitizer::removeEmptyGroups($svg);

		expect($result)->not->toContain('<g');
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → removeEmptyGroups removes empty g with attributes', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><g id="layer1" transform="translate(0,0)"></g><circle cx="12" cy="12" r="10"/></svg>';
		$result = SVGSanitizer::removeEmptyGroups($svg);

		expect($result)->not->toContain('<g');
		expect($result)->not->toContain('layer1');
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → removeEmptyGroups handles nested empty groups', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><g><g></g></g><circle cx="12" cy="12" r="10"/></svg>';
		$result = SVGSanitizer::removeEmptyGroups($svg);

		expect($result)->not->toContain('<g');
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → removeEmptyGroups preserves groups with content', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><g><circle cx="12" cy="12" r="10"/></g></svg>';
		$result = SVGSanitizer::removeEmptyGroups($svg);

		expect($result)->toContain('<g>');
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → removeEmptyGroups removes empty groups with whitespace only', function (): void {
		$svg    = "<svg xmlns=\"http://www.w3.org/2000/svg\"><g>   \n\t  </g><circle cx=\"12\" cy=\"12\" r=\"10\"/></svg>";
		$result = SVGSanitizer::removeEmptyGroups($svg);

		expect($result)->not->toContain('<g');
		expect($result)->toContain('<circle');
	});

	test('SVGSanitizer → removeEmptyGroups returns unchanged SVG with no empty groups', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><g><rect x="0" y="0" width="10" height="10"/></g></svg>';
		$result = SVGSanitizer::removeEmptyGroups($svg);

		expect($result)->toBe($svg);
	});

	// -------------------------
	// Uniquify IDs
	// -------------------------

	test('SVGSanitizer → uniquifyIds prefixes underscore IDs', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="_clip1"><rect x="0" y="0" width="10" height="10"/></clipPath></defs></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		expect($result)->not->toContain('id="_clip1"');
		expect($result)->toMatch('/id="tcms-[a-f0-9]+-_clip1"/');
	});

	test('SVGSanitizer → uniquifyIds leaves non-underscore IDs unchanged', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="myGradient"><stop offset="0%" stop-color="red"/></linearGradient></defs></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		expect($result)->toContain('id="myGradient"');
	});

	test('SVGSanitizer → uniquifyIds updates url() references', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="_clip1"><rect x="0" y="0" width="10" height="10"/></clipPath></defs><g clip-path="url(#_clip1)"><circle cx="5" cy="5" r="3"/></g></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		// Extract the prefix used
		preg_match('/id="(tcms-[a-f0-9]+-_clip1)"/', $result, $matches);
		$newId = $matches[1];

		expect($result)->toContain('url(#' . $newId . ')');
		expect($result)->not->toContain('url(#_clip1)');
	});

	test('SVGSanitizer → uniquifyIds updates href references', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="_Linear2"><stop offset="0%" stop-color="red"/></linearGradient></defs><rect fill="url(#_Linear2)" x="0" y="0" width="10" height="10"/></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		preg_match('/id="(tcms-[a-f0-9]+-_Linear2)"/', $result, $matches);
		$newId = $matches[1];

		expect($result)->toContain('url(#' . $newId . ')');
		expect($result)->not->toContain('url(#_Linear2)');
	});

	test('SVGSanitizer → uniquifyIds handles multiple IDs', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="_clip1"><rect x="0" y="0" width="10" height="10"/></clipPath><linearGradient id="_Linear2"><stop offset="0%" stop-color="red"/></linearGradient></defs><g clip-path="url(#_clip1)"><rect fill="url(#_Linear2)" x="0" y="0" width="10" height="10"/></g></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		expect($result)->not->toContain('id="_clip1"');
		expect($result)->not->toContain('id="_Linear2"');
		expect($result)->not->toContain('url(#_clip1)');
		expect($result)->not->toContain('url(#_Linear2)');
		expect($result)->toMatch('/id="tcms-[a-f0-9]+-_clip1"/');
		expect($result)->toMatch('/id="tcms-[a-f0-9]+-_Linear2"/');
	});

	test('SVGSanitizer → uniquifyIds returns unchanged SVG with no underscore IDs', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><circle id="myCircle" cx="12" cy="12" r="10"/></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		expect($result)->toBe($svg);
	});

	test('SVGSanitizer → uniquifyIds handles duplicate ID attributes', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><clipPath id="_clip1"><rect x="0" y="0" width="10" height="10"/></clipPath><clipPath id="_clip1"><rect x="0" y="0" width="20" height="20"/></clipPath></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		// Both instances should be renamed to the same new ID
		expect($result)->not->toContain('id="_clip1"');
		preg_match_all('/id="(tcms-[a-f0-9]+-_clip1)"/', $result, $matches);
		expect($matches[1])->toHaveCount(2);
		expect($matches[1][0])->toBe($matches[1][1]);
	});

	test('SVGSanitizer → uniquifyIds avoids partial ID replacement', function (): void {
		// _clip1 should not partially match _clip10
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="_clip10"><rect x="0" y="0" width="10" height="10"/></clipPath><clipPath id="_clip1"><rect x="0" y="0" width="5" height="5"/></clipPath></defs><g clip-path="url(#_clip10)"><g clip-path="url(#_clip1)"><circle cx="5" cy="5" r="3"/></g></g></svg>';
		$result = SVGSanitizer::uniquifyIds($svg);

		// Both should be uniquified independently
		expect($result)->toMatch('/id="tcms-[a-f0-9]+-_clip10"/');
		expect($result)->toMatch('/id="tcms-[a-f0-9]+-_clip1"/');
		expect($result)->toMatch('/url\(#tcms-[a-f0-9]+-_clip10\)/');
		expect($result)->toMatch('/url\(#tcms-[a-f0-9]+-_clip1\)/');
	});

	// -------------------------
	// sanitizeAndValidate Integration (with new methods)
	// -------------------------

	test('SVGSanitizer → sanitizeAndValidate removes empty groups and uniquifies IDs', function (): void {
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg"><g></g><defs><clipPath id="_clip1"><rect x="0" y="0" width="10" height="10"/></clipPath></defs><rect clip-path="url(#_clip1)" x="0" y="0" width="10" height="10"/></svg>';
		$result = SVGSanitizer::sanitizeAndValidate($svg);

		// Empty group removed
		expect($result)->not->toMatch('/<g[^>]*>\s*<\/g>/');
		// ID uniquified
		expect($result)->not->toContain('id="_clip1"');
		expect($result)->toMatch('/id="tcms-[a-f0-9]+-_clip1"/');
	});
});
