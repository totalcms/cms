import Dialog from '../dialog';
import tcmsConfirm from '../../confirm-dialog';
import { t } from '../../i18n';
import { FITS, fitOf, hasLayers, marksOf, parseOverlay, serializeOverlay, summarize, withFit, withMarks } from './overlay';
import { LivePreview, fetchOptions, fetchPreview } from './preview-client';
import LayersPanel from './layers-panel';
import { previewMarks } from './layers';

//-----------------------------------------------
// The share-image overlay designer: a JSON field with
// `"settings": {"designer": "socialOverlay"}` shows a one-line summary and a
// Design button instead of a code editor. The button opens a dialog with
// presets, a layer editor, the raw JSON and a live preview on a record the
// user picks.
//
// The field's textarea stays the value holder, so the JSON field's
// getValue(), validate() and save are unchanged. The dialog edits a working
// copy; Apply writes it back, Cancel and Escape throw it away, and a click
// on the backdrop applies (Dialog's model: a stray click never loses work).
// Nothing is saved until the form is.
//-----------------------------------------------

/** Reasons that are a choice, not a problem (Share Cards' calm list). */
const CALM = ['drawn', 'no-overlay', 'as-is', 'needs-pro'];

function element(tag, attributes = {}, children = []) {
	const node = document.createElement(tag);
	for (const [name, value] of Object.entries(attributes)) {
		if (value === null || value === undefined || value === false) continue;
		if (name === 'text') {
			node.textContent = value;
		} else if (name === 'className') {
			node.className = value;
		} else {
			node.setAttribute(name, value === true ? '' : value);
		}
	}
	node.append(...children.filter(Boolean));

	return node;
}

function remembered(key) {
	try {
		return JSON.parse(window.localStorage.getItem(key) ?? 'null');
	} catch {
		return null;
	}
}

function remember(key, value) {
	try {
		window.localStorage.setItem(key, JSON.stringify(value));
	} catch {
		// Private windows and blocked storage: the picker just starts fresh.
	}
}

export default class OverlayDesigner {

	constructor(field) {
		this.field   = field;
		this.input   = field.input;
		this.scope   = field.settings.designerScope === 'collection' ? 'collection' : 'site';
		this.options = null;
		this.record  = { collection: '', id: '' };
		this.dialog  = null;

		this.buildRow();
		this.refresh();
		this.watchRow();
	}

	//-------------------------
	// The form row
	//-------------------------

	buildRow() {
		this.thumb   = element('img', { className: 'overlay-row-thumb', alt: '', hidden: true });
		this.summary = element('span', { className: 'overlay-row-summary' });
		this.note    = element('span', { className: 'overlay-row-note', hidden: true });
		this.button  = element('button', { type: 'button', className: 'dash-button small overlay-row-button' });
		this.button.addEventListener('click', () => this.open());

		this.row = element('div', { className: 'overlay-row' }, [
			this.thumb,
			element('span', { className: 'overlay-row-text' }, [this.summary, this.note]),
			this.button,
		]);
		this.input.after(this.row);
	}

	/** Bring the row up to date with the field's value. */
	refresh() {
		const parsed = parseOverlay(this.input.value);
		const layers = parsed.ok && hasLayers(parsed.overlay);

		this.summary.textContent = parsed.ok ? summarize(parsed.overlay, t) : t('overlay.custom', {}, 'Custom JSON');
		this.button.textContent  = layers || !parsed.ok ? t('overlay.design', {}, 'Design…') : t('overlay.add', {}, 'Add overlay…');

		if (this.rowSeen) this.loadThumb();
	}

	/** Draw the row's thumbnail once the row scrolls into view. */
	watchRow() {
		if (!('IntersectionObserver' in window)) {
			this.rowSeen = true;
			this.loadThumb();
			return;
		}
		const observer = new IntersectionObserver(entries => {
			if (!entries.some(entry => entry.isIntersecting)) return;
			observer.disconnect();
			this.rowSeen = true;
			this.loadThumb();
		});
		observer.observe(this.row);
	}

	async loadThumb() {
		const parsed = parseOverlay(this.input.value);
		if (!parsed.ok || !hasLayers(parsed.overlay)) {
			this.thumb.hidden = true;
			return;
		}

		try {
			await this.ensureRecord();
		} catch {
			return;
		}
		this.showProNote();
		if (!this.record.id) return;

		try {
			const result = await fetchPreview({ ...this.record, overlay: parsed.overlay, size: 'row' });
			if (result.image) {
				this.thumb.src    = result.image;
				this.thumb.hidden = false;
			}
		} catch {
			this.thumb.hidden = true;
		}
	}

	showProNote() {
		const pro = this.options?.layers !== false;
		this.note.hidden      = pro;
		this.note.textContent = pro ? '' : t('overlay.needs_pro', {}, 'Pro edition: drawn on Pro and above.');
	}

	//-------------------------
	// The preview record
	//-------------------------

	/** The collection settings form's own id: a collection overlay previews its own records. */
	ownCollection() {
		const form = this.field.container.closest('form');
		if (!form) return '';
		const field = [...form.querySelectorAll('.form-field')].find(el => !el.closest('.card-fields') && el.querySelector('input[name="id"]'));

		return field?.querySelector('input[name="id"]')?.value.trim() ?? '';
	}

	storageKey() {
		return `tcms.overlay-designer.${this.scope}.${this.scope === 'collection' ? this.ownCollection() : 'site'}`;
	}

	/** Load the picker's options once and settle on a record: remembered, else the default. */
	async ensureRecord() {
		if (this.options) return;

		const wanted = this.scope === 'collection' ? this.ownCollection() : (remembered(this.storageKey())?.collection ?? '');
		if (this.scope === 'collection' && wanted === '') {
			this.options = { layers: true, collections: [], collection: '', objects: [], default: '', presets: [], ownOverlay: false };
			return;
		}

		this.options = await fetchOptions(wanted);
		this.pickRecord(remembered(this.storageKey())?.id);
	}

	pickRecord(id) {
		const objects = this.options?.objects ?? [];
		const known   = objects.some(object => object.id === id);

		this.record = { collection: this.options?.collection ?? '', id: known ? id : (this.options?.default || objects[0]?.id || '') };
	}

	async switchCollection(collection, query = '') {
		const keep   = this.record.id;
		this.options = { ...(await fetchOptions(collection, query)), presets: this.options?.presets ?? [] };
		this.pickRecord(keep);
		remember(this.storageKey(), this.record);
	}

	//-------------------------
	// The dialog
	//-------------------------

	async open() {
		const parsed = parseOverlay(this.input.value);
		this.working   = parsed.ok ? structuredClone(parsed.overlay) : {};
		this.jsonText  = parsed.ok ? serializeOverlay(this.working) : this.input.value;
		this.jsonValid = parsed.ok;
		this.discard   = false;
		this.hidden    = new Set();

		if (!this.dialog) this.buildDialog();
		this.dialog.open();

		this.layers.selected = -1;
		this.layers.render();
		this.showTab(!parsed.ok ? 'json' : (hasLayers(this.working) ? 'layers' : 'presets'));
		this.syncFit();
		this.syncJson();
		this.syncValidity(parsed.error);

		try {
			await this.ensureRecord();
		} catch {
			this.setStatus('', t('overlay.failed', {}, 'The preview could not be drawn.'));
			return;
		}
		this.syncPicker();
		this.layers.render();
		this.drawPresets();
		this.drawNow();
	}

	buildDialog() {
		const node = element('dialog', { className: 'cms-modal large overlay-designer', 'aria-label': this.field.label });

		// Header: the title, the fit, a close button (which cancels).
		this.fitSelect = element('select', { className: 'overlay-fit' });
		this.fitSelect.addEventListener('change', () => {
			this.working = withFit(this.working, this.fitSelect.value);
			this.changed();
		});
		const closeButton = element('button', { type: 'button', className: 'overlay-close', 'aria-label': t('confirm.cancel', {}, 'Cancel'), text: '×' });
		closeButton.addEventListener('click', () => this.cancel());

		const header = element('header', { className: 'overlay-header' }, [
			element('h2', { text: this.field.label }),
			element('label', { className: 'overlay-fit-label' }, [element('span', { text: t('overlay.fit', {}, 'Crop') }), this.fitSelect]),
			closeButton,
		]);

		// Tabs.
		this.tabs = {};
		const tabList = element('nav', { className: 'overlay-tabs', role: 'tablist' });
		for (const [name, label] of [['presets', t('overlay.tab.presets', {}, 'Presets')], ['layers', t('overlay.tab.layers', {}, 'Layers')], ['json', t('overlay.tab.json', {}, 'JSON')]]) {
			const tab = element('button', { type: 'button', role: 'tab', className: 'overlay-tab', text: label });
			tab.addEventListener('click', () => this.showTab(name));
			this.tabs[name] = tab;
			tabList.append(tab);
		}

		// Panes.
		this.presetGrid = element('div', { className: 'overlay-presets' });
		this.jsonHolder = element('div', { className: 'overlay-json-editor' });
		this.jsonError  = element('p', { className: 'overlay-json-error', hidden: true });
		this.panes = {
			presets : element('section', { className: 'overlay-pane', role: 'tabpanel' }, [this.presetGrid]),
			layers  : element('section', { className: 'overlay-pane overlay-layers', role: 'tabpanel' }),
			json    : element('section', { className: 'overlay-pane', role: 'tabpanel' }, [this.jsonHolder, this.jsonError]),
		};
		this.layers = new LayersPanel(this.panes.layers, this);

		// Preview.
		this.collectionSelect = element('select', { className: 'overlay-collection', hidden: this.scope === 'collection' });
		this.collectionSelect.addEventListener('change', () => this.onCollection());
		this.search = element('input', { type: 'search', className: 'overlay-search', placeholder: t('overlay.search', {}, 'Search records') });
		this.search.addEventListener('input', () => {
			clearTimeout(this.searchTimer);
			this.searchTimer = setTimeout(() => this.onSearch(), 300);
		});
		this.objectSelect = element('select', { className: 'overlay-object' });
		this.objectSelect.addEventListener('change', () => this.onObject());

		this.stage       = element('div', { className: 'overlay-stage' });
		this.stageImage  = element('img', { alt: '', hidden: true });
		this.stage.append(this.stageImage);
		this.status      = element('p', { className: 'overlay-status' });
		this.previewNote = element('p', { className: 'overlay-note', hidden: true });

		const preview = element('aside', { className: 'overlay-preview' }, [
			element('div', { className: 'overlay-picker' }, [
				element('span', { className: 'overlay-picker-label', text: t('overlay.preview_with', {}, 'Preview with') }),
				this.collectionSelect,
				this.search,
				this.objectSelect,
			]),
			this.stage,
			this.status,
			this.previewNote,
		]);

		// Footer.
		const remove = element('button', { type: 'button', className: 'overlay-remove', text: t('overlay.remove', {}, 'Remove overlay') });
		remove.addEventListener('click', () => {
			this.working = withMarks(withFit(this.working, ''), []);
			this.changed();
		});
		const cancel = element('button', { type: 'button', className: 'dash-button transparent', text: t('confirm.cancel', {}, 'Cancel') });
		cancel.addEventListener('click', () => this.cancel());
		this.applyButton = element('button', { type: 'button', className: 'dash-button', text: t('overlay.apply', {}, 'Apply') });
		this.applyButton.addEventListener('click', () => this.dialog.close());

		node.append(
			header,
			tabList,
			element('div', { className: 'overlay-body' }, [element('div', { className: 'overlay-panes' }, Object.values(this.panes)), preview]),
			element('footer', { className: 'overlay-actions' }, [remove, element('span', { className: 'overlay-spacer' }), cancel, this.applyButton]),
		);
		document.body.append(node);

		this.preview = new LivePreview(result => this.showPreview(result));
		this.dialog  = new Dialog(node, {
			onDismiss : () => { this.discard = true; },
			onClose   : () => this.closed(),
		});
	}

	cancel() {
		this.discard = true;
		this.dialog.close();
	}

	closed() {
		this.preview.cancel();
		if (!this.discard && this.jsonValid) {
			this.input.value = serializeOverlay(this.working);
			this.field.changed();
			this.refresh();
		}
	}

	showTab(name) {
		for (const [tab, button] of Object.entries(this.tabs)) {
			const active = tab === name;
			button.setAttribute('aria-selected', active ? 'true' : 'false');
			this.panes[tab].hidden = !active;
		}
		this.activeTab = name;
		if (name === 'json') this.ensureJsonEditor();
	}

	/**
	 * After any edit that is not typed into the JSON tab. The Layers tab
	 * redraws itself for its own edits (so a control keeps its focus), and
	 * is redrawn here for everyone else's.
	 */
	changed({ from = '' } = {}) {
		this.jsonText = serializeOverlay(this.working);
		this.syncJson();
		this.syncFit();
		this.markPreset();
		if (from !== 'layers') this.layers.render();
		this.drawSoon();
	}

	//-------------------------
	// Fit
	//-------------------------

	syncFit() {
		const fit   = fitOf(this.working);
		const names = { '': t('overlay.fit.focal', {}, 'Focal point'), 'crop-top': t('overlay.fit.top', {}, 'Top'), 'crop-center': t('overlay.fit.center', {}, 'Center'), 'crop-bottom': t('overlay.fit.bottom', {}, 'Bottom') };
		const fits  = FITS.includes(fit) ? FITS : [...FITS, fit];

		this.fitSelect.replaceChildren(...fits.map(value => element('option', { value, text: names[value] ?? t('overlay.fit.custom', { fit: value }, value) })));
		this.fitSelect.value    = fit;
		this.fitSelect.disabled = !this.jsonValid;
	}

	//-------------------------
	// JSON tab
	//-------------------------

	ensureJsonEditor() {
		if (this.jsonEditor || !window.TotalCMSCodeMirror) return;

		this.jsonEditor = window.TotalCMSCodeMirror.createJsonEditor(this.jsonHolder, {
			value             : this.jsonText,
			matchBrackets     : true,
			autoCloseBrackets : true,
			lineWrapping      : true,
		});
		this.jsonEditor.on('change', () => this.onJsonTyped(this.jsonEditor.getValue()));
		setTimeout(() => this.jsonEditor.refresh(), 50);
	}

	syncJson() {
		if (!this.jsonEditor || this.jsonEditor.getValue() === this.jsonText) return;
		this.syncing = true;
		this.jsonEditor.setValue(this.jsonText);
		this.syncing = false;
	}

	onJsonTyped(text) {
		if (this.syncing) return;
		this.jsonText = text;

		const parsed = parseOverlay(text);
		this.jsonValid = parsed.ok;
		this.syncValidity(parsed.error);
		if (!parsed.ok) return;

		this.working = parsed.overlay;
		this.syncFit();
		this.markPreset();
		this.layers.render();
		this.drawSoon();
	}

	syncValidity(error = '') {
		this.applyButton.disabled = !this.jsonValid;
		this.fitSelect.disabled   = !this.jsonValid;
		this.tabs.presets.disabled = !this.jsonValid;
		this.tabs.layers.disabled  = !this.jsonValid;
		this.jsonError.hidden      = this.jsonValid;
		this.jsonError.textContent = this.jsonValid ? '' : t('overlay.json_invalid', { error: error || t('form.invalid_json', {}, 'Invalid JSON format.') }, `Invalid JSON: ${error}`);
	}

	//-------------------------
	// Presets tab
	//-------------------------

	async drawPresets() {
		const presets = this.options?.presets ?? [];
		this.presetCards = presets.map(preset => {
			const image = element('img', { alt: '', loading: 'lazy' });
			const card  = element('button', { type: 'button', className: 'overlay-preset', 'aria-pressed': 'false' }, [
				element('span', { className: 'overlay-preset-thumb' }, [image]),
				element('strong', { text: t(`overlay.preset.${preset.id}.name`, {}, preset.id) }),
				element('span', { className: 'overlay-preset-desc', text: t(`overlay.preset.${preset.id}.desc`, {}, '') }),
			]);
			card.addEventListener('click', () => this.usePreset(preset));

			return { preset, card, image };
		});
		this.presetGrid.replaceChildren(...this.presetCards.map(entry => entry.card));
		this.markPreset();

		// One at a time, so a dialog opening never floods the image renderer.
		const record = { ...this.record };
		for (const entry of this.presetCards) {
			if (!record.id || record.id !== this.record.id) return;
			try {
				const result = await fetchPreview({ ...record, overlay: entry.preset.overlay, size: 'thumb' });
				if (result.image) entry.image.src = result.image;
			} catch {
				// A thumbnail that will not draw just stays empty.
			}
		}
	}

	markPreset() {
		const current = JSON.stringify(marksOf(this.working));
		for (const entry of this.presetCards ?? []) {
			entry.card.setAttribute('aria-pressed', JSON.stringify(entry.preset.overlay.marks) === current ? 'true' : 'false');
		}
	}

	async usePreset(preset) {
		const current = JSON.stringify(marksOf(this.working));
		const known   = (this.presetCards ?? []).some(entry => JSON.stringify(entry.preset.overlay.marks) === current);

		if (hasLayers(this.working) && !known) {
			const replace = await tcmsConfirm({ message: t('overlay.replace_confirm', {}, 'Replace the current layers with this preset?'), confirmLabel: t('overlay.replace', {}, 'Replace'), countdown: 0 });
			if (!replace) return;
		}

		this.working = withMarks(this.working, structuredClone(preset.overlay.marks));
		this.hidden.clear();
		this.layers.selected = -1;
		this.changed();
		this.showTab('layers');
	}

	//-------------------------
	// Preview pane
	//-------------------------

	syncPicker() {
		const options = this.options ?? {};

		this.collectionSelect.replaceChildren(...(options.collections ?? []).map(c => element('option', { value: c.id, text: c.name })));
		this.collectionSelect.value = this.record.collection;

		const objects = options.objects ?? [];
		this.objectSelect.replaceChildren(...objects.map(o => element('option', { value: o.id, text: o.draft ? `${o.title} · ${t('overlay.draft', {}, 'draft')}` : o.title })));
		this.objectSelect.value    = this.record.id;
		this.objectSelect.disabled = objects.length === 0;

		if (this.scope === 'collection' && this.ownCollection() === '') {
			this.setStatus('', t('overlay.save_first', {}, 'Save the collection first to preview it.'));
		} else if (objects.length === 0) {
			this.setStatus('', t('overlay.no_records', {}, 'No records to preview with.'));
		}

		const own = this.scope === 'site' && options.ownOverlay;
		const name = (options.collections ?? []).find(c => c.id === this.record.collection)?.name ?? this.record.collection;
		this.ownOverlayNote = own ? t('overlay.own_overlay', { collection: name }, `${name} uses its own overlay. Its shares won't use this one.`) : '';
	}

	async onCollection() {
		this.search.value = '';
		try {
			await this.switchCollection(this.collectionSelect.value);
		} catch {
			this.setStatus('', t('overlay.failed', {}, 'The preview could not be drawn.'));
			return;
		}
		this.afterRecordChange();
	}

	async onSearch() {
		try {
			await this.switchCollection(this.record.collection, this.search.value.trim());
		} catch {
			return;
		}
		this.syncPicker();
		if (this.objectSelect.value !== this.record.id) this.afterRecordChange();
	}

	onObject() {
		this.record = { ...this.record, id: this.objectSelect.value };
		remember(this.storageKey(), this.record);
		this.afterRecordChange();
	}

	afterRecordChange() {
		this.syncPicker();
		// The Insert field menu lists the preview collection's fields.
		this.layers.renderControls();
		this.drawPresets();
		this.drawNow();
		this.loadThumb();
	}

	/** The working copy, less the layers hidden with the eye or not finished. */
	previewBody() {
		return { ...this.record, overlay: withMarks(this.working, previewMarks(marksOf(this.working), this.hidden ?? new Set())), size: 'full' };
	}

	drawNow() {
		if (!this.record.id) return;
		this.stage.classList.add('loading');
		this.preview.now(this.previewBody());
	}

	drawSoon() {
		if (!this.record.id) return;
		this.stage.classList.add('loading');
		this.preview.schedule(this.previewBody());
	}

	showPreview(result) {
		this.stage.classList.remove('loading');
		if (result.error) {
			this.setStatus('', t('overlay.failed', {}, 'The preview could not be drawn.'));
			return;
		}

		this.stageImage.hidden = !result.image;
		if (result.image) this.stageImage.src = result.image;
		this.setStatus(result.reason, result.message);

		const notes = [];
		if (result.asIs) notes.push(t('overlay.as_is', {}, 'This record shares its image as is, so its real share has no overlay.'));
		if (this.ownOverlayNote) notes.push(this.ownOverlayNote);
		this.previewNote.hidden      = notes.length === 0;
		this.previewNote.textContent = notes.join(' ');
	}

	setStatus(reason, message) {
		this.status.textContent = message ?? '';
		this.status.className   = `overlay-status ${reason === 'drawn' ? 'ok' : (CALM.includes(reason) || reason === '' ? 'info' : 'warning')}`;
	}
}
