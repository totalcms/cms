/**
 * InlineClass Extension
 * Custom mark that applies CSS classes to selected text.
 * Used for font family, font size, and other class-based styling.
 */

import { Mark, mergeAttributes } from '@tiptap/core';

const InlineClass = Mark.create({
	name: 'inlineClass',

	addOptions() {
		return {
			HTMLAttributes: {},
		};
	},

	addAttributes() {
		return {
			class: {
				default: null,
				parseHTML: (element) => element.getAttribute('class'),
				renderHTML: (attributes) => {
					if (!attributes.class) return {};
					return { class: attributes.class };
				},
			},
		};
	},

	parseHTML() {
		return [
			{
				tag: 'span[class]',
				getAttrs: (element) => {
					const className = element.getAttribute('class');
					// Only match spans with classes (not ProseMirror internal spans)
					if (!className || className.startsWith('ProseMirror')) return false;
					return { class: className };
				},
			},
		];
	},

	renderHTML({ HTMLAttributes }) {
		return ['span', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes), 0];
	},

	addCommands() {
		return {
			setInlineClass: (className) => ({ commands }) => {
				return commands.setMark(this.name, { class: className });
			},
			unsetInlineClass: () => ({ commands }) => {
				return commands.unsetMark(this.name);
			},
			toggleInlineClass: (className) => ({ commands }) => {
				return commands.toggleMark(this.name, { class: className });
			},
		};
	},
});

export default InlineClass;
