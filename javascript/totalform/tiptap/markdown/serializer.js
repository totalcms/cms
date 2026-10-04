/**
 * Text escaping for the Tiptap Markdown serializer.
 *
 * The stock encoder (MarkdownManager.encodeTextForMarkdown) does two things
 * that damage content rendered by ParsedownExtra in safe mode:
 *
 * - It turns every & < > into an entity. Safe mode prints entities
 *   literally, so "Fish & chips" would show as "Fish &amp; chips".
 * - It does not escape block markers at the start of a line, so a paragraph
 *   reading "2026. A good year" is saved as a numbered list.
 */

const INLINE_SPECIALS   = /([\\`*_[\]~])/g;
const LINE_START_MARKER = /^(\s*)(#{1,6}(?=\s|$)|>|[-+](?=\s)|\d+(?=[.)](?:\s|$)))/;

/**
 * @param {string} text
 * @param {boolean} atLineStart true when the text begins a block or follows a hard break
 * @returns {string}
 */
export function escapeMarkdownText(text, atLineStart) {
	const escaped = text.replace(INLINE_SPECIALS, '\\$1');
	if (!atLineStart) return escaped;

	// A number is escaped after its digits ("2026\."), everything else before.
	return escaped.replace(LINE_START_MARKER, (match, space, marker) => (
		/^\d/.test(marker) ? `${space}${marker}\\` : `${space}\\${marker}`
	));
}

/**
 * Replace the manager's text encoder. Code (a code block parent or a code
 * mark) stays literal, as in the stock encoder.
 */
export function patchMarkdownSerializer(manager) {
	manager.encodeTextForMarkdown = function (text, node, parentNode) {
		const marks  = node.marks || [];
		const inCode = (parentNode?.type != null && this.codeTypes.has(parentNode.type))
			|| marks.some((mark) => this.codeTypes.has(typeof mark === 'string' ? mark : mark.type));
		if (inCode) return text;

		const siblings    = Array.isArray(parentNode?.content) ? parentNode.content : [];
		const index       = siblings.indexOf(node);
		const atLineStart = (index === 0 || siblings[index - 1]?.type === 'hardBreak') && marks.length === 0;

		return escapeMarkdownText(text, atLineStart);
	};
}
