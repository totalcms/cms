import LocalizedTextField from "./localizedtext.js";
import TiptapEditor from "./tiptap/TiptapEditor.js";
import DOMPurify from 'dompurify';

window.DOMPurify = DOMPurify;

//-----------------------------------------------
// Total CMS Localized Styled Text Field (Pro)
//
// Tab-per-locale interface where each pane hosts a Tiptap editor. Inherits
// the tab-switching machinery from LocalizedTextField; adds onLocaleSwitched()
// to nudge the now-visible Tiptap editor so its layout settles after the
// pane comes out of `hidden`.
//-----------------------------------------------
export default class LocalizedStyledTextField extends LocalizedTextField {

	constructor(container, settings) {
		super(container, settings);

		this.settings = Object.assign({}, this.defaultConfig(), this.settings);

		// One Tiptap instance per locale, keyed by locale code so we can
		// look up the right editor in getValue() / setValue() / onLocaleSwitched().
		this.editors = {};

		const textareas = Array.from(this.container.querySelectorAll('textarea[data-locale]'));
		textareas.forEach(textarea => {
			if (textarea.dataset.steInitialized) return;
			textarea.dataset.steInitialized = 'true';

			const code   = textarea.dataset.locale;
			const editor = new TiptapEditor(textarea, this.settings);
			if (code) this.editors[code] = editor;

			editor.updateUploadEnabled?.();
		});

		this.bindUploadReadyWatchers();
		this.storedValue = JSON.stringify(this.getValue());
	}

	bindUploadReadyWatchers() {
		const refresh = () => Object.values(this.editors).forEach(e => e?.updateUploadEnabled?.());
		const inputs  = new Set();

		const allFormIdInputs = Array.from(this.form?.form?.querySelectorAll('input[name="id"]') || []);
		const formIdInput = allFormIdInputs.find(input => !input.closest('dialog'));
		if (formIdInput) inputs.add(formIdInput);

		if (this.deckItem) {
			const itemIdInput = this.deckItem.querySelector('dialog input[name="id"]');
			if (itemIdInput) inputs.add(itemIdInput);
		}

		inputs.forEach(input => input.addEventListener('input', refresh));
	}

	/**
	 * Called by LocalizedTextField.switchToLocale() after the new pane is
	 * shown. Dispatches an empty transaction on the matching Tiptap editor
	 * so ProseMirror recomputes layout for the now-visible element. Without
	 * this, editors first revealed by a tab click can render with stale
	 * dimensions, broken popover positioning, or 0-height image placeholders.
	 */
	onLocaleSwitched(locale) {
		// Defensive — switchToLocale() is wired up by the parent class during
		// its constructor, before this subclass has populated this.editors.
		// Optional chaining + early return covers any edge case where a tab
		// click lands before init finishes.
		const editor = this.editors?.[locale];
		if (!editor?.view) return;
		requestAnimationFrame(() => {
			editor.view.dispatch(editor.state.tr);
		});
	}

	getValue() {
		// Parent constructor calls this.getValue() to seed storedValue *before*
		// this subclass has finished initializing this.editors. Return an empty
		// dict in that window; the subclass constructor re-snapshots storedValue
		// at the end, after editors are populated.
		const out = {};
		if (!this.editors) return out;

		Object.entries(this.editors).forEach(([code, editor]) => {
			let html = editor?.getHTML?.() ?? '';
			html = html.replace(/\%25/g, "%");

			// Treat editor with only empty tags as truly empty.
			const tmp = document.createElement('div');
			tmp.innerHTML = html;
			if (tmp.textContent.trim() === '' && !tmp.querySelector('img, video, audio, iframe, hr, table')) {
				html = '';
			}

			out[code] = html;
		});
		return out;
	}

	setValue(value) {
		if (!this.editors) return;
		const dict = (value && typeof value === 'object') ? value : {};
		Object.entries(this.editors).forEach(([code, editor]) => {
			editor?.setHTML?.(dict[code] ?? '');
		});
		this.changed();
	}

	uploadAPI() {
		const ctx = this.getUploadContext();
		if (!ctx) return null;
		const path = ctx.subpath ? `/${ctx.subpath}` : '';
		return this.api.buildApiQuery(`/upload/${ctx.collection}/${ctx.id}/${ctx.property}${path}`);
	}

	defaultConfig() {
		const uploadUrl = () => this.uploadAPI();
		return {
			heightMin          : 200,
			heightMax          : 800,
			wordCounterCount   : false,
			pasteAsPlainText   : true,
			onContentChanged   : () => this.changed(),
			uploadUrl          : uploadUrl,
			imagePreset        : this.settings.imagePreset || null,
			imageUploadRules   : this.settings.imageUploadRules || this.settings.rules || {},
		};
	}

	schema() {
		return {
			"type"  : "localizedstyledtext",
			"field" : "localizedstyledtext"
		};
	}
}
