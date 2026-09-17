/**
 * ListStyle - Custom extensions for BulletList and OrderedList
 * that support a CSS class attribute for list-style-type control.
 *
 * Adds `setListClass` command to apply ste-* classes to list nodes.
 */

import BulletList from '@tiptap/extension-bullet-list';
import OrderedList from '@tiptap/extension-ordered-list';
import { ListItem } from '@tiptap/extension-list';

// Tiptap's list item is `paragraph block*`: the first child must be a
// paragraph. Hand-authored steps that open with a heading, a figure or a
// classed <div> did not fit, so the parser emptied the item, pushed its
// content out after the list and re-wrapped the following items in a stray
// <ul> (customer report, 2026-09-16). Any block may lead now; bare text still
// wraps in a paragraph, and cleanHTML() still unwraps a lone one.
export const BlockListItem = ListItem.extend({
	content: 'block+',
});

export const BULLET_STYLES = [
	{ value: '',              label: 'Disc',   icon: 'unordered-list' },
	{ value: 'ste-circle',    label: 'Circle', icon: 'unordered-list' },
	{ value: 'ste-square',    label: 'Square', icon: 'unordered-list' },
	{ value: 'ste-none',      label: 'None',   icon: 'unordered-list' },
];

export const ORDERED_STYLES = [
	{ value: '',                  label: '1, 2, 3',    icon: 'ordered-list' },
	{ value: 'ste-lower-alpha',   label: 'a, b, c',    icon: 'ordered-list' },
	{ value: 'ste-upper-alpha',   label: 'A, B, C',    icon: 'ordered-list' },
	{ value: 'ste-lower-roman',   label: 'i, ii, iii',  icon: 'ordered-list' },
	{ value: 'ste-upper-roman',   label: 'I, II, III',  icon: 'ordered-list' },
	{ value: 'ste-none',          label: 'None',        icon: 'ordered-list' },
];

export const StyledBulletList = BulletList.extend({
	addAttributes() {
		return {
			...this.parent?.(),
			class: {
				default: null,
				parseHTML: (element) => element.getAttribute('class') || null,
				renderHTML: (attributes) => {
					if (!attributes.class) return {};
					return { class: attributes.class };
				},
			},
		};
	},

	addCommands() {
		return {
			...this.parent?.(),
			setBulletListClass: (className) => ({ commands }) => {
				return commands.updateAttributes('bulletList', {
					class: className || null,
				});
			},
		};
	},
});

export const StyledOrderedList = OrderedList.extend({
	addAttributes() {
		return {
			...this.parent?.(),
			class: {
				default: null,
				parseHTML: (element) => element.getAttribute('class') || null,
				renderHTML: (attributes) => {
					if (!attributes.class) return {};
					return { class: attributes.class };
				},
			},
		};
	},

	addCommands() {
		return {
			...this.parent?.(),
			setOrderedListClass: (className) => ({ commands }) => {
				return commands.updateAttributes('orderedList', {
					class: className || null,
				});
			},
		};
	},
});
