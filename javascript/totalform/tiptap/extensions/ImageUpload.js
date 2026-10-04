/**
 * ImageUpload Extension
 * Custom image node with upload dialog, drag-drop, paste support.
 * Uses DropletTestSet for client-side rule validation.
 * The upload dialog (with its Image Manager tab) is in ImageDialog.js.
 */

import Image from '@tiptap/extension-image';
import { mergeAttributes } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { getUploadUrl, uploadFile, validateFile } from '../upload.js';
import { createImagePopoverPlugin } from './ImagePopover.js';
import { createImageDialog } from './ImageDialog.js';
import { tiptapInsertTarget } from './insertTargets.js';

/**
 * Extended Image extension with upload support
 */
const ImageUpload = Image.extend({
	name: 'image',

	// popover: the width/float controls. Off for markdown, which cannot store them.
	addOptions() {
		return {
			...this.parent?.(),
			popover: true,
		};
	},

	addAttributes() {
		return {
			...this.parent?.(),
			'data-preset': { default: null },
			width: { default: null },
			'data-float': {
				default: null,
				parseHTML: el => el.getAttribute('data-float'),
				renderHTML: attrs => attrs['data-float'] ? { 'data-float': attrs['data-float'] } : {},
			},
			'data-size': {
				default: null,
				parseHTML: el => el.getAttribute('data-size'),
				renderHTML: attrs => attrs['data-size'] ? { 'data-size': attrs['data-size'] } : {},
			},
		};
	},

	renderHTML({ HTMLAttributes }) {
		// Authored classes first, then the editor's own. The ste-img-* tokens
		// are regenerated from float/size each render, so strip stale ones
		// from the authored set to keep the round trip stable.
		const classes = String(HTMLAttributes.class || '').split(/\s+/).filter((c) => c && !c.startsWith('ste-img--'));
		if (HTMLAttributes['data-float']) classes.push(`ste-img--float-${HTMLAttributes['data-float']}`);
		if (HTMLAttributes['data-size']) classes.push(`ste-img--${HTMLAttributes['data-size']}`);

		const attrs = { ...HTMLAttributes };
		attrs.class = classes.length ? classes.join(' ') : null;

		return ['img', mergeAttributes(this.options.HTMLAttributes, attrs)];
	},

	addCommands() {
		return {
			...this.parent?.(),
			openImageDialog: () => ({ editor }) => {
				const uploadConfig = editor.options.imageUploadConfig || {};
				createImageDialog(tiptapInsertTarget(editor), uploadConfig);
				return true;
			},
		};
	},

	addProseMirrorPlugins() {
		const uploadConfig = this.editor.options.imageUploadConfig || {};
		const rules = uploadConfig.rules || {};

		return [
			...(this.parent?.() || []),
			new Plugin({
				props: {
					handleDrop: (view, event) => {
						if (!event.dataTransfer?.files?.length) return false;

						const file = event.dataTransfer.files[0];
						if (!file.type.startsWith('image/')) return false;

						event.preventDefault();
						const url = getUploadUrl(uploadConfig);

						validateFile(file, rules).then(result => {
							if (!result.valid) {
								console.warn('Image drop rejected:', result.errors);
								return;
							}
							return uploadFile(file, url, uploadConfig);
						}).then(data => {
							if (!data) return;
							const { schema } = view.state;
							const node = schema.nodes.image.create({ src: data.link });
							const pos = view.posAtCoords({ left: event.clientX, top: event.clientY });
							if (pos) {
								const tr = view.state.tr.insert(pos.pos, node);
								view.dispatch(tr);
							}
						}).catch(err => console.error('Image drop upload failed:', err));

						return true;
					},
					handlePaste: (view, event) => {
						const items = event.clipboardData?.items;
						if (!items) return false;

						for (const item of items) {
							if (item.type.startsWith('image/')) {
								event.preventDefault();
								const file = item.getAsFile();
								if (!file) continue;

								const url = getUploadUrl(uploadConfig);

								validateFile(file, rules).then(result => {
									if (!result.valid) {
										console.warn('Image paste rejected:', result.errors);
										return;
									}
									return uploadFile(file, url, uploadConfig);
								}).then(data => {
									if (!data) return;
									const { schema } = view.state;
									const node = schema.nodes.image.create({ src: data.link });
									const tr = view.state.tr.replaceSelectionWith(node);
									view.dispatch(tr);
								}).catch(err => console.error('Image paste upload failed:', err));

								return true;
							}
						}
						return false;
					},
				},
			}),
			...(this.options.popover === false ? [] : [createImagePopoverPlugin(this.editor)]),
		];
	},
});

export default ImageUpload;
export { createImageDialog };
