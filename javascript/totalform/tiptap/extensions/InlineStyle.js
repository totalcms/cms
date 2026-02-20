/**
 * InlineStyle Extension
 * Custom mark that applies inline CSS styles to selected text.
 * Wraps text in a <span style="..."> element.
 */

import { Mark, mergeAttributes } from '@tiptap/core';

const InlineStyle = Mark.create({
	name: 'inlineStyle',

	addOptions() {
		return {
			HTMLAttributes: {},
		};
	},

	addAttributes() {
		return {
			style: {
				default: null,
				parseHTML: (element) => element.getAttribute('style'),
				renderHTML: (attributes) => {
					if (!attributes.style) return {};
					return { style: attributes.style };
				},
			},
		};
	},

	parseHTML() {
		return [
			{
				tag: 'span[style]',
				getAttrs: (element) => {
					const style = element.getAttribute('style');
					if (!style) return false;
					return { style };
				},
			},
		];
	},

	renderHTML({ HTMLAttributes }) {
		return ['span', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes), 0];
	},

	addCommands() {
		return {
			setInlineStyle: (style) => ({ commands }) => {
				return commands.setMark(this.name, { style });
			},
			unsetInlineStyle: () => ({ commands }) => {
				return commands.unsetMark(this.name);
			},
			toggleInlineStyle: (style) => ({ commands }) => {
				return commands.toggleMark(this.name, { style });
			},
		};
	},
});

export default InlineStyle;
