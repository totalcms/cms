/**
 * InlineElement Extension
 * Custom mark that preserves arbitrary inline HTML elements (button, kbd, etc.)
 * that aren't handled by other extensions. Stores the original tag name and
 * all attributes, rendering back to the original element.
 */

import { Mark, mergeAttributes } from '@tiptap/core';

function parseAttrs(element) {
	return {
		tagName: element.tagName.toLowerCase(),
		htmlAttrs: JSON.stringify(
			Array.from(element.attributes).reduce((attrs, attr) => {
				attrs[attr.name] = attr.value;
				return attrs;
			}, {})
		),
	};
}

const InlineElement = Mark.create({
	name: 'inlineElement',

	addAttributes() {
		return {
			tagName: {
				default: 'span',
			},
			htmlAttrs: {
				default: '{}',
			},
		};
	},

	parseHTML() {
		return [
			{ tag: 'button', getAttrs: parseAttrs },
			{ tag: 'kbd', getAttrs: parseAttrs },
			{ tag: 'abbr', getAttrs: parseAttrs },
			{ tag: 'cite', getAttrs: parseAttrs },
			{ tag: 'samp', getAttrs: parseAttrs },
			{ tag: 'var', getAttrs: parseAttrs },
			{ tag: 'small', getAttrs: parseAttrs },
			{ tag: 'mark', getAttrs: parseAttrs },
			{ tag: 'ins', getAttrs: parseAttrs },
			{ tag: 'del', getAttrs: parseAttrs },
			{ tag: 'q', getAttrs: parseAttrs },
			{ tag: 'time', getAttrs: parseAttrs },
			{ tag: 'output', getAttrs: parseAttrs },
			{ tag: 'label', getAttrs: parseAttrs },
		];
	},

	renderHTML({ mark }) {
		const tagName = mark.attrs.tagName || 'span';
		let attrs = {};

		try {
			attrs = JSON.parse(mark.attrs.htmlAttrs || '{}');
		} catch {
			attrs = {};
		}

		return [tagName, attrs, 0];
	},
});

export default InlineElement;
