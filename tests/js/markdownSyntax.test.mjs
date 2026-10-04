import { findLossySyntax } from '../../javascript/totalform/tiptap/markdown/lossy.js';
import { escapeMarkdownText } from '../../javascript/totalform/tiptap/markdown/serializer.js';

describe('findLossySyntax', () => {
	test('plain markdown is not lossy', () => {
		expect(findLossySyntax('# Title\n\n- one\n- two\n\n[link](https://a.co) and `code`')).toEqual([]);
	});

	test('names each kind of syntax the visual editor cannot keep', () => {
		expect(findLossySyntax('Text <span class="x">raw</span>')).toEqual(['html']);
		expect(findLossySyntax('<!-- note -->\n\nBody')).toEqual(['comment']);
		expect(findLossySyntax('Claim.[^1]\n\n[^1]: Note.')).toEqual(['footnote']);
		expect(findLossySyntax('HTML\n\n*[HTML]: HyperText Markup Language')).toEqual(['abbreviation']);
	});

	test('reports several kinds in a fixed order', () => {
		expect(findLossySyntax('Claim.[^1]\n\n<div>x</div>\n\n[^1]: Note.')).toEqual(['html', 'footnote']);
	});

	test('ignores HTML and footnote-like text inside code', () => {
		expect(findLossySyntax('```html\n<div onclick="x()">Hi</div>\n```')).toEqual([]);
		expect(findLossySyntax('~~~\n<!-- kept -->\n~~~')).toEqual([]);
		expect(findLossySyntax('Use `<br>` or `[^1]` literally')).toEqual([]);
		expect(findLossySyntax('```\nunclosed <div>')).toEqual([]);
	});

	test('autolinks and comparisons are not HTML', () => {
		expect(findLossySyntax('<https://totalcms.co> and <joe@example.com>')).toEqual([]);
		expect(findLossySyntax('a < b > c')).toEqual([]);
	});

	test('a linked image is lossy: the visual editor drops the link', () => {
		expect(findLossySyntax('[![Build](badge.png)](https://ci.example.com)')).toEqual(['linkedImage']);
		expect(findLossySyntax('![Plain](a.png) and [text](https://a.co)')).toEqual([]);
	});

	test('processing instructions and doctypes count as HTML', () => {
		expect(findLossySyntax('<?php echo 1; ?>')).toEqual(['html']);
		expect(findLossySyntax('<!DOCTYPE html>')).toEqual(['html']);
	});

	test('empty and missing values are not lossy', () => {
		expect(findLossySyntax('')).toEqual([]);
		expect(findLossySyntax(null)).toEqual([]);
	});
});

describe('escapeMarkdownText', () => {
	test('escapes inline markdown characters', () => {
		expect(escapeMarkdownText('a *b* _c_ [d] `e` ~f~ \\g', false)).toBe('a \\*b\\* \\_c\\_ \\[d\\] \\`e\\` \\~f\\~ \\\\g');
	});

	test('leaves ampersands and angle brackets alone', () => {
		expect(escapeMarkdownText('Fish & chips, a < b > c, &copy;', true)).toBe('Fish & chips, a < b > c, &copy;');
	});

	test('escapes block markers only at the start of a line', () => {
		expect(escapeMarkdownText('2026. A good year', true)).toBe('2026\\. A good year');
		expect(escapeMarkdownText('1) first', true)).toBe('1\\) first');
		expect(escapeMarkdownText('# not a heading', true)).toBe('\\# not a heading');
		expect(escapeMarkdownText('> not a quote', true)).toBe('\\> not a quote');
		expect(escapeMarkdownText('- not a list', true)).toBe('\\- not a list');
		expect(escapeMarkdownText('+ not a list', true)).toBe('\\+ not a list');
		expect(escapeMarkdownText('2026. A good year', false)).toBe('2026. A good year');
	});

	test('does not escape text that only looks like a marker', () => {
		expect(escapeMarkdownText('#hashtag', true)).toBe('#hashtag');
		expect(escapeMarkdownText('-5 degrees', true)).toBe('-5 degrees');
		expect(escapeMarkdownText('3.14 is pi', true)).toBe('3.14 is pi');
	});
});
