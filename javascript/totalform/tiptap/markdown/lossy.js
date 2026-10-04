/**
 * Markdown syntax the visual editor cannot keep.
 *
 * Tiptap parses Markdown into its own document model and writes it back out.
 * Anything with no node in that model is dropped or escaped on the way:
 * raw HTML and comments lose their tags, footnotes and abbreviations (which
 * ParsedownExtra renders on the site) come back as literal text, and an
 * image inside a link loses the link.
 * Content using any of these is edited in source mode instead.
 */

const CHECKS = [
	// A tag, a processing instruction (<?php) or a declaration (<!DOCTYPE).
	['html',         /<\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*)?\/?>|<\?|<![a-zA-Z]/],
	['comment',      /<!--/],
	['footnote',     /\[\^[^\]\s]+\]/],
	['abbreviation', /^\*\[[^\]]+\]:/m],
	['linkedImage',  /\[!\[[^\]]*\]\([^)]*\)\]\(/],
];

/**
 * Remove fenced code blocks and inline code, where this syntax is literal.
 * Indented code is left in: four leading spaces are also how a list item
 * continues, and missing real HTML there would lose it.
 */
function withoutCode(markdown) {
	return markdown
		.replace(/^(`{3,}|~{3,})[^\n]*\n[\s\S]*?^\1[ \t]*$/gm, '')
		.replace(/^(`{3,}|~{3,})[^\n]*\n[\s\S]*$/m, '')
		.replace(/`[^`\n]+`/g, '');
}

/**
 * @param {string|null|undefined} markdown
 * @returns {string[]} any of 'html', 'comment', 'footnote', 'abbreviation', 'linkedImage'
 */
export function findLossySyntax(markdown) {
	const text = withoutCode(String(markdown ?? ''));

	return CHECKS.filter(([, pattern]) => pattern.test(text)).map(([name]) => name);
}
