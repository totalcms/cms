/**
 * Indent Extension
 * Adds an `indent` attribute (0-N) to paragraph and heading nodes and
 * exposes `indent`/`outdent` commands that step the level up or down.
 *
 * Rendered as `data-indent="N"` so CSS can map it to padding-inline-start.
 * Inside lists, the commands delegate to sinkListItem/liftListItem so that
 * the familiar list indenting behavior is preserved.
 */

import { Extension } from '@tiptap/core';

const DEFAULT_TYPES = ['paragraph', 'heading'];
const DEFAULT_MAX = 8;

const Indent = Extension.create({
	name: 'indent',

	addOptions() {
		return {
			types: DEFAULT_TYPES,
			max:   DEFAULT_MAX,
		};
	},

	addGlobalAttributes() {
		return [
			{
				types: this.options.types,
				attributes: {
					indent: {
						default: 0,
						parseHTML: (element) => {
							const value = parseInt(element.getAttribute('data-indent') || '0', 10);
							return Number.isFinite(value) && value > 0 ? value : 0;
						},
						renderHTML: (attributes) => {
							if (!attributes.indent) return {};
							return { 'data-indent': String(attributes.indent) };
						},
					},
				},
			},
		];
	},

	addCommands() {
		const stepIndent = (direction) => ({ state, tr, dispatch, editor }) => {
			if (editor.isActive('listItem')) {
				return direction > 0
					? editor.commands.sinkListItem('listItem')
					: editor.commands.liftListItem('listItem');
			}

			const { from, to } = state.selection;
			const { types, max } = this.options;
			let updated = false;

			state.doc.nodesBetween(from, to, (node, pos) => {
				if (!types.includes(node.type.name)) return;
				const current = node.attrs.indent || 0;
				const next = Math.max(0, Math.min(current + direction, max));
				if (next === current) return;
				if (dispatch) {
					tr.setNodeMarkup(pos, null, { ...node.attrs, indent: next });
				}
				updated = true;
			});

			return updated;
		};

		return {
			indent:  () => stepIndent(1),
			outdent: () => stepIndent(-1),
		};
	},
});

export default Indent;
