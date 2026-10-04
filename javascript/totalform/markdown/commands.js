/**
 * Markdown text commands.
 *
 * Each command takes the document text and the selection and returns one edit:
 * { from, to, insert, select }. `from`/`to` is the range to replace, `select`
 * the selection afterwards (offsets in the new text). Nothing here touches the
 * DOM or an editor; the surfaces in surfaces.js apply the edit.
 */

const INLINE_MARKERS = { bold: '**', italic: '*', strike: '~~', inlineCode: '`' };

const LIST_MARKER = /^(\s*)(?:[-*+]|\d+[.)]) /;
const BULLET      = /^\s*[-*+] /;
const ORDERED     = /^\s*\d+[.)] /;
const QUOTE       = /^> ?/;
const HEADING     = /^(#{1,6}) +/;

// How many `char` in a row, starting at index and walking by step.
function runLength(text, index, step, char) {
	let count = 0;
	for (let i = index; text[i] === char; i += step) count++;

	return count;
}

// `*` is also half of `**`: text between two stars on each side is bold, not
// italic. An odd run of stars on both sides means italic is on.
function starsAreItalic(before, after) {
	return before % 2 === 1 && after % 2 === 1;
}

function selectionIsWrapped(selected, marker) {
	if (selected.length < marker.length * 2 || !selected.startsWith(marker) || !selected.endsWith(marker)) return false;
	if (marker !== '*') return true;

	return starsAreItalic(runLength(selected, 0, 1, '*'), runLength(selected, selected.length - 1, -1, '*'));
}

function surroundingsAreWrapped(text, from, to, marker) {
	const size = marker.length;
	if (text.slice(from - size, from) !== marker || text.slice(to, to + size) !== marker) return false;
	if (marker !== '*') return true;

	return starsAreItalic(runLength(text, from - 1, -1, '*'), runLength(text, to, 1, '*'));
}

function toggleInline(text, from, to, marker) {
	const size     = marker.length;
	const selected = text.slice(from, to);

	if (selectionIsWrapped(selected, marker)) {
		const inner = selected.slice(size, -size);

		return { from, to, insert: inner, select: [from, from + inner.length] };
	}

	if (surroundingsAreWrapped(text, from, to, marker)) {
		return { from: from - size, to: to + size, insert: selected, select: [from - size, to - size] };
	}

	return { from, to, insert: marker + selected + marker, select: [from + size, to + size] };
}

// The whole lines a selection touches. A selection that ends at the very
// start of a line does not include that line.
function lineBounds(text, from, to) {
	// lastIndexOf clamps a negative start to 0, so at offset 0 it would find
	// a newline that is the first character and put the start after the end.
	const start = from === 0 ? 0 : text.lastIndexOf('\n', from - 1) + 1;
	const last  = to > from && text[to - 1] === '\n' ? to - 1 : to;
	const next  = text.indexOf('\n', last);

	return [start, next === -1 ? text.length : next];
}

function editLines(text, from, to, transform) {
	const [start, end] = lineBounds(text, from, to);
	const insert       = transform(text.slice(start, end).split('\n')).join('\n');
	const after        = start + insert.length;

	return { from: start, to: end, insert, select: from === to ? [after, after] : [start, after] };
}

function toggleList(lines, isOn, marker) {
	const filled = lines.filter((line) => line.trim() !== '');
	// Blank lines only: start a list on the first and keep the rest.
	if (filled.length === 0) return lines.map((line, index) => (index === 0 ? marker(0) : line));

	if (filled.every(isOn)) return lines.map((line) => line.replace(LIST_MARKER, '$1'));

	let index = 0;

	return lines.map((line) => {
		if (line.trim() === '') return line;
		const indent = line.match(/^\s*/)[0];
		const rest   = line.replace(LIST_MARKER, '$1').slice(indent.length);

		return indent + marker(index++) + rest;
	});
}

function toggleQuote(lines) {
	if (lines.every((line) => QUOTE.test(line))) return lines.map((line) => line.replace(QUOTE, ''));

	return lines.map((line) => (line === '' ? '>' : '> ' + line));
}

function setHeading(lines, level) {
	return lines.map((line) => {
		if (line.trim() === '') return line;
		const current = line.match(HEADING)?.[1].length ?? 0;
		const rest    = line.replace(HEADING, '');

		return level === 0 || level === current ? rest : '#'.repeat(level) + ' ' + rest;
	});
}

function toggleCodeBlock(text, from, to) {
	const [start, end] = lineBounds(text, from, to);
	const lines        = text.slice(start, end).split('\n');

	if (lines.length >= 2 && lines[0].startsWith('```') && lines[lines.length - 1].trim() === '```') {
		const inner = lines.slice(1, -1).join('\n');

		return { from: start, to: end, insert: inner, select: [start, start + inner.length] };
	}

	const insert = '```\n' + lines.join('\n') + '\n```';
	if (from === to && lines.join('') === '') {
		return { from: start, to: end, insert, select: [start + 4, start + 4] };
	}

	return { from: start, to: end, insert, select: [start, start + insert.length] };
}

// A block that goes on its own lines after the line the cursor is on.
function insertBlock(text, to, block, selectFrom, selectLength) {
	const [start, end] = lineBounds(text, to, to);
	const lead         = text.slice(start, end).trim() === '' ? '' : '\n\n';
	const insert       = lead + block + '\n\n';
	const anchor       = selectFrom === null ? end + insert.length : end + lead.length + selectFrom;

	return { from: end, to: end, insert, select: [anchor, anchor + selectLength] };
}

function insertLink(text, from, to) {
	const label  = text.slice(from, to) || 'text';
	const insert = `[${label}](url)`;

	if (from === to) return { from, to, insert, select: [from + 1, from + 1 + label.length] };

	const url = from + label.length + 3;

	return { from, to, insert, select: [url, url + 3] };
}

function insertImage(text, from, to) {
	const alt    = text.slice(from, to) || 'alt';
	const insert = `![${alt}](url)`;
	const url    = from + alt.length + 4;

	return { from, to, insert, select: [url, url + 3] };
}

/**
 * @param {string} name
 * @param {string} text  the whole document
 * @param {number} from  selection start
 * @param {number} to    selection end
 * @param {{level?: number, text?: string}} [args]
 * @returns {{from: number, to: number, insert: string, select: [number, number]}|null}
 */
export function markdownCommand(name, text, from, to, args = {}) {
	if (name in INLINE_MARKERS) return toggleInline(text, from, to, INLINE_MARKERS[name]);

	switch (name) {
		case 'heading':
			return editLines(text, from, to, (lines) => setHeading(lines, args.level ?? 0));
		case 'bulletList':
			return editLines(text, from, to, (lines) => toggleList(lines, (line) => BULLET.test(line), () => '- '));
		case 'orderedList':
			return editLines(text, from, to, (lines) => toggleList(lines, (line) => ORDERED.test(line), (i) => `${i + 1}. `));
		case 'blockquote':
			return editLines(text, from, to, toggleQuote);
		case 'codeBlock':
			return toggleCodeBlock(text, from, to);
		case 'horizontalRule':
			return insertBlock(text, to, '---', null, 0);
		case 'table':
			return insertBlock(text, to, '| Column 1 | Column 2 |\n| --- | --- |\n| Cell | Cell |', 2, 8);
		case 'link':
			return insertLink(text, from, to);
		case 'image':
			return insertImage(text, from, to);
		case 'insert': {
			const inserted = args.text ?? '';

			return { from, to, insert: inserted, select: [from + inserted.length, from + inserted.length] };
		}
		default:
			return null;
	}
}
