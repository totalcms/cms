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
		// textStyle (with Color, FontFamily, …) already parses span[style] for
		// the properties it models. Carrying those here as well rendered the
		// declaration twice, and each save nested one more span around a
		// `<span class style>` (customer report, 2026-09-16). Keep only what
		// textStyle does not model; claim nothing when nothing is left.
		// Resolved inside getAttrs: the schema does not exist yet when
		// parseHTML() itself runs.
		const modelledProperties = () => {
			const attrs = this.editor?.schema?.marks?.textStyle?.spec?.attrs || {};
			return new Set(Object.keys(attrs).map((attr) => attr.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)));
		};

		return [
			{
				tag: 'span[style]',
				getAttrs: (element) => {
					const modelled = modelledProperties();
					// textStyle's nested-span merge concatenates parent and child
					// style attributes as `${parent};${child}` even when the
					// child has none, so a literal "null" can turn up here.
					// Only `property: value` pairs count.
					const kept = String(element.getAttribute('style') || '')
						.split(';')
						.map((declaration) => declaration.trim())
						.filter((declaration) => declaration.includes(':'))
						.filter((declaration) => !modelled.has(declaration.split(':')[0].trim().toLowerCase()));
					if (kept.length === 0) return false;
					return { style: kept.join('; ') };
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
