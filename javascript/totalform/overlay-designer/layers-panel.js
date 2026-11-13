import TotalSortable from '../total-sortable';
import { t } from '../../i18n';
import { layerName, marksOf, withMarks } from './overlay';
import {
	ALIGNMENTS,
	GRADIENTS,
	MAX_LAYERS,
	PALETTE,
	POSITIONS,
	colorInputValue,
	editorKind,
	formatSize,
	imagePath,
	insertPlaceholder,
	isComplete,
	moveLayer,
	newLayer,
	otherKeys,
	parseImagePath,
	parseSize,
	storedColor,
	withKey,
} from './layers';

//-----------------------------------------------
// The overlay designer's Layers tab: the list of layers, top of the list
// drawn last (on top) as in an image editor, and the controls for the
// selected one. Every edit goes through the designer's working copy, so the
// JSON tab, the preview and Apply all see it.
//-----------------------------------------------

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

function option(value, text, selected = false) {
	return element('option', { value, text, selected });
}

export default class LayersPanel {

	/**
	 * @param {HTMLElement} host the tab's pane
	 * @param {object} designer the OverlayDesigner: working, options, hidden, changed()
	 */
	constructor(host, designer) {
		this.designer = designer;
		this.selected = -1;

		this.addSelect = element('select', { className: 'overlay-layer-add' });
		this.addSelect.addEventListener('change', () => this.add(this.addSelect.value));
		this.addNote   = element('span', { className: 'overlay-layer-max', hidden: true, text: t('overlay.layers.max', {}, 'Six layers is the most a card can draw.') });

		this.list     = element('ol', { className: 'overlay-layer-list' });
		this.empty    = element('p', { className: 'overlay-layer-empty', text: t('overlay.layers.empty', {}, 'No layers yet. Add one, or start from a preset.') });
		this.controls = element('div', { className: 'overlay-layer-controls' });

		host.append(
			element('div', { className: 'overlay-layer-bar' }, [this.addSelect, this.addNote]),
			this.list,
			this.empty,
			this.controls,
		);

		this.sortable = new TotalSortable(this.list, {
			handle : '.overlay-layer-handle',
			onEnd  : event => this.dropped(event.oldIndex, event.newIndex),
		});
	}

	get marks() {
		return marksOf(this.designer.working);
	}

	/** List rows run top-down; drawing order runs bottom-up. */
	drawIndex(row) {
		return this.marks.length - 1 - row;
	}

	//-------------------------
	// The list
	//-------------------------

	/** Redraw everything: after a preset, a JSON edit, or a change to the list. */
	render() {
		const marks = this.marks;
		if (this.selected >= marks.length) this.selected = marks.length - 1;
		if (this.selected < 0 && marks.length > 0) this.selected = marks.length - 1;

		this.list.replaceChildren(...marks.map((layer, index) => this.row(layer, index)).reverse());
		this.empty.hidden = marks.length > 0;
		this.renderAdd();
		this.renderControls();
	}

	renderAdd() {
		const full = this.marks.length >= MAX_LAYERS;
		this.addSelect.replaceChildren(
			option('', t('overlay.layers.add', {}, 'Add layer…'), true),
			...['fill', 'text', 'image', 'logo'].map(kind => option(kind, t(`overlay.layer.kind.${kind}`, {}, kind))),
		);
		this.addSelect.disabled = full;
		this.addNote.hidden     = !full;
	}

	row(layer, index) {
		const kind   = editorKind(layer);
		const hidden = this.designer.hidden.has(layer);
		const name   = element('span', { className: 'overlay-layer-name', text: this.nameOf(layer) });

		const eye = element('button', {
			type         : 'button',
			className    : `overlay-layer-eye${hidden ? ' off' : ''}`,
			'aria-label' : hidden ? t('overlay.layer.show', {}, 'Show in preview') : t('overlay.layer.hide', {}, 'Hide from preview'),
			'aria-pressed': hidden ? 'false' : 'true',
		});
		eye.addEventListener('click', event => {
			event.stopPropagation();
			this.toggleHidden(index);
		});

		const remove = element('button', { type: 'button', className: 'overlay-layer-delete', 'aria-label': t('overlay.layer.delete', {}, 'Delete layer') });
		remove.addEventListener('click', event => {
			event.stopPropagation();
			this.remove(index);
		});

		const row = element('li', {
			className       : `overlay-layer${index === this.selected ? ' selected' : ''}${hidden ? ' hidden-layer' : ''}`,
			tabindex        : '0',
			'aria-selected' : index === this.selected ? 'true' : 'false',
		}, [
			element('span', { className: 'overlay-layer-handle', 'aria-hidden': 'true', title: t('overlay.layer.drag', {}, 'Drag to reorder') }),
			element('span', { className: `overlay-layer-icon kind-${kind ?? 'other'}`, 'aria-hidden': 'true' }),
			name,
			eye,
			remove,
		]);
		row.addEventListener('click', () => this.select(index));
		row.addEventListener('keydown', event => {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				this.select(index);
			}
		});

		return row;
	}

	nameOf(layer) {
		const name = layerName(layer, t);

		return isComplete(layer) ? name : `${name} · ${t('overlay.layer.incomplete', {}, 'not finished')}`;
	}

	select(index) {
		this.selected = index;
		[...this.list.children].forEach((row, rowIndex) => {
			const on = this.drawIndex(rowIndex) === index;
			row.classList.toggle('selected', on);
			row.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		this.renderControls();
	}

	add(kind) {
		const layer = newLayer(kind, { logo: this.designer.options?.logos?.[0] ?? '' });
		this.addSelect.value = '';
		if (!layer || this.marks.length >= MAX_LAYERS) return;

		// A fill goes underneath, where it darkens the photo behind the text;
		// anything else goes on top.
		const marks = kind === 'fill' ? [layer, ...this.marks] : [...this.marks, layer];
		this.selected = kind === 'fill' ? 0 : marks.length - 1;
		this.commit(marks);
		this.render();
	}

	remove(index) {
		const marks = this.marks.filter((_, i) => i !== index);
		if (this.selected >= index) this.selected = Math.max(0, this.selected - 1);
		this.commit(marks);
		this.render();
	}

	toggleHidden(index) {
		const layer = this.marks[index];
		if (this.designer.hidden.has(layer)) {
			this.designer.hidden.delete(layer);
		} else {
			this.designer.hidden.add(layer);
		}
		this.render();
		this.designer.drawSoon();
	}

	/** SortableJS reports list rows; the overlay stores drawing order. */
	dropped(oldRow, newRow) {
		if (oldRow === newRow || oldRow === undefined || newRow === undefined) return;
		const from = this.drawIndex(oldRow);
		const to   = this.drawIndex(newRow);
		const keep = this.marks[this.selected];

		const marks   = moveLayer(this.marks, from, to);
		this.selected = marks.indexOf(keep);
		this.commit(marks);
		this.render();
	}

	commit(marks) {
		this.designer.working = withMarks(this.designer.working, marks);
		this.designer.changed({ from: 'layers' });
	}

	/** Change one key of the selected layer, keeping the controls as they are. */
	update(key, value) {
		const marks = [...this.marks];
		const old   = marks[this.selected];
		if (!old) return;

		const layer = withKey(old, key, value);
		marks[this.selected] = layer;
		if (this.designer.hidden.has(old)) {
			this.designer.hidden.delete(old);
			this.designer.hidden.add(layer);
		}
		this.commit(marks);

		const row = this.list.children[this.marks.length - 1 - this.selected];
		const name = row?.querySelector('.overlay-layer-name');
		if (name) name.textContent = this.nameOf(layer);
	}

	//-------------------------
	// The selected layer's controls
	//-------------------------

	renderControls() {
		const layer = this.marks[this.selected];
		if (!layer) {
			this.controls.replaceChildren();
			return;
		}

		const kind   = editorKind(layer);
		const groups = {
			fill  : () => this.fillControls(layer),
			text  : () => this.textControls(layer),
			image : () => this.imageControls(layer),
			logo  : () => this.logoControls(layer),
		}[kind]?.() ?? [];

		const other = otherKeys(layer);
		this.controls.replaceChildren(
			element('h3', { text: t(`overlay.layer.kind.${kind}`, {}, kind ?? '') }),
			...groups,
			other.length > 0
				? element('p', { className: 'overlay-layer-other', text: t('overlay.ctl.also_json', { keys: other.join(', ') }, `Also set in JSON: ${other.join(', ')}`) })
				: null,
		);
	}

	fillControls(layer) {
		return [
			this.colorControl(t('overlay.ctl.color', {}, 'Color'), 'markfill', layer.markfill, { required: true }),
			this.alphaControl('markalpha', layer.markalpha, 100),
			this.selectControl(t('overlay.ctl.fade', {}, 'Fade'), 'markgradient', layer.markgradient ?? '',
				GRADIENTS.map(value => [value, t(`overlay.fade.${value || 'none'}`, {}, value || 'None')])),
			element('div', { className: 'overlay-control-pair' }, [
				this.sizeControl(t('overlay.ctl.width', {}, 'Width'), 'markw', layer.markw),
				this.sizeControl(t('overlay.ctl.height', {}, 'Height'), 'markh', layer.markh),
			]),
			this.positionControl('markpos', layer.markpos),
		];
	}

	textControls(layer) {
		const fonts = this.designer.options?.fonts ?? [];
		const font  = layer.marktextfont ?? '';

		return [
			this.textControl(layer.marktext),
			element('div', { className: 'overlay-control-pair' }, [
				this.sizeControl(t('overlay.ctl.size', {}, 'Text size'), 'marktextsize', layer.marktextsize),
				this.sizeControl(t('overlay.ctl.wrap', {}, 'Wrap width'), 'marktextw', layer.marktextw),
			]),
			element('div', { className: 'overlay-control-pair' }, [
				this.numberControl(t('overlay.ctl.lines', {}, 'Max lines'), 'marktextlines', layer.marktextlines, 1, 6),
				this.selectControl(t('overlay.ctl.align', {}, 'Alignment'), 'marktextalign', layer.marktextalign ?? 'left',
					ALIGNMENTS.map(value => [value, t(`overlay.align.${value}`, {}, value)])),
			]),
			this.colorControl(t('overlay.ctl.color', {}, 'Color'), 'marktextcolor', layer.marktextcolor ?? 'ffffff', { fallback: 'ffffff' }),
			this.colorControl(t('overlay.ctl.background', {}, 'Background'), 'marktextbg', layer.marktextbg, { allowNone: true }),
			this.selectControl(t('overlay.ctl.font', {}, 'Font'), 'marktextfont', font, [
				['', t('overlay.font.default', {}, 'Default (Roboto)')],
				...(fonts.includes(font) || font === '' ? fonts : [font, ...fonts]).map(name => [name, name]),
			]),
			this.positionControl('marktextpos', layer.marktextpos),
			element('div', { className: 'overlay-control-pair' }, [
				this.sizeControl(t('overlay.ctl.padding', {}, 'Padding'), 'marktextpad', layer.marktextpad),
				this.sizeControl(t('overlay.ctl.offset', {}, 'Lift'), 'marktexty', layer.marktexty),
			]),
		];
	}

	imageControls(layer) {
		return [
			this.photoBuilder(layer.markimage ?? ''),
			this.checkboxControl(t('overlay.ctl.circle', {}, 'Cut to a circle'), 'markshape', layer.markshape === 'circle', 'circle'),
			this.sizeControl(t('overlay.ctl.width', {}, 'Width'), 'markw', layer.markw),
			this.positionControl('markpos', layer.markpos),
			this.sizeControl(t('overlay.ctl.padding', {}, 'Padding'), 'markpad', layer.markpad),
		];
	}

	logoControls(layer) {
		const logos = this.designer.options?.logos ?? [];
		const mark  = layer.mark ?? '';
		const list  = mark === '' || logos.includes(mark) ? logos : [mark, ...logos];

		return [
			list.length > 0
				? this.selectControl(t('overlay.ctl.logo', {}, 'Logo'), 'mark', mark, list.map(name => [name, name]), { required: true })
				: element('p', { className: 'overlay-layer-other', text: t('overlay.logo.none', {}, 'Add a file to the watermarks gallery to use a logo.') }),
			this.sizeControl(t('overlay.ctl.width', {}, 'Width'), 'markw', layer.markw),
			this.alphaControl('markalpha', layer.markalpha, 100),
			this.positionControl('markpos', layer.markpos),
			this.sizeControl(t('overlay.ctl.padding', {}, 'Padding'), 'markpad', layer.markpad),
		];
	}

	//-------------------------
	// Control builders
	//-------------------------

	labelled(label, control, className = '') {
		return element('label', { className: `overlay-control ${className}`.trim() }, [element('span', { className: 'overlay-control-label', text: label }), control]);
	}

	selectControl(label, key, value, choices, { required = false } = {}) {
		const select = element('select');
		select.replaceChildren(...choices.map(([v, text]) => option(v, text, v === value)));
		select.value = value;
		select.addEventListener('change', () => this.update(key, select.value === '' && !required ? null : select.value));

		return this.labelled(label, select);
	}

	numberControl(label, key, value, min, max) {
		const input = element('input', { type: 'number', min: String(min), max: String(max), step: '1', value: value ?? '' });
		input.addEventListener('input', () => {
			const number = Number.parseInt(input.value, 10);
			this.update(key, Number.isFinite(number) ? Math.min(max, Math.max(min, number)) : null);
		});

		return this.labelled(label, input);
	}

	checkboxControl(label, key, checked, onValue) {
		const input = element('input', { type: 'checkbox', checked });
		input.addEventListener('change', () => this.update(key, input.checked ? onValue : null));

		return element('label', { className: 'overlay-control overlay-control-check' }, [input, element('span', { text: label })]);
	}

	alphaControl(key, value, fallback) {
		const current = Number.isFinite(Number(value)) && value !== undefined && value !== '' ? Number(value) : fallback;
		const output  = element('output', { text: `${current}%` });
		const input   = element('input', { type: 'range', min: '0', max: '100', step: '5', value: String(current) });
		input.addEventListener('input', () => {
			output.textContent = `${input.value}%`;
			this.update(key, Number(input.value));
		});

		return this.labelled(t('overlay.ctl.alpha', {}, 'Opacity'), element('span', { className: 'overlay-range' }, [input, output]));
	}

	sizeControl(label, key, value) {
		const size   = parseSize(value);
		const number = element('input', { type: 'number', min: '0', step: 'any', value: size.value, placeholder: '—' });
		const unit   = element('select', { 'aria-label': label });
		unit.replaceChildren(...['w', 'h', 'px'].map(u => option(u, t(`overlay.unit.${u}`, {}, u), u === size.unit)));
		unit.value = size.unit;

		const write = () => this.update(key, formatSize(number.value, unit.value));
		number.addEventListener('input', write);
		unit.addEventListener('change', write);

		return this.labelled(label, element('span', { className: 'overlay-size' }, [number, unit]));
	}

	colorControl(label, key, value, { required = false, allowNone = false, fallback = '000000' } = {}) {
		const current  = String(value ?? '');
		const palette  = PALETTE.includes(current);
		const mode     = element('select', { 'aria-label': label });
		const picker   = element('input', { type: 'color', value: colorInputValue(palette || current === '' ? fallback : current), hidden: palette || (allowNone && current === '') });

		mode.replaceChildren(
			...(allowNone ? [option('none', t('overlay.ctl.none', {}, 'None'), current === '')] : []),
			option('custom', t('overlay.ctl.color_custom', {}, 'Custom color'), !palette && current !== ''),
			...PALETTE.map((name, i) => option(name, t('overlay.ctl.color_palette', { n: i + 1 }, `Image color ${i + 1}`), name === current)),
		);
		mode.value = palette ? current : (current === '' && allowNone ? 'none' : 'custom');

		mode.addEventListener('change', () => {
			picker.hidden = mode.value !== 'custom';
			if (mode.value === 'none') {
				this.update(key, required ? fallback : null);
			} else if (mode.value === 'custom') {
				this.update(key, storedColor(picker.value));
			} else {
				this.update(key, mode.value);
			}
		});
		picker.addEventListener('input', () => this.update(key, storedColor(picker.value)));

		return this.labelled(label, element('span', { className: 'overlay-color' }, [mode, picker]));
	}

	positionControl(key, value) {
		const grid = element('span', { className: 'overlay-position', role: 'group', 'aria-label': t('overlay.ctl.position', {}, 'Position') });
		for (const position of POSITIONS) {
			const cell = element('button', {
				type          : 'button',
				className     : 'overlay-position-cell',
				'aria-label'  : t(`overlay.pos.${position}`, {}, position),
				'aria-pressed': position === value ? 'true' : 'false',
				title         : t(`overlay.pos.${position}`, {}, position),
			});
			cell.addEventListener('click', () => {
				for (const other of grid.children) other.setAttribute('aria-pressed', other === cell ? 'true' : 'false');
				this.update(key, position);
			});
			grid.append(cell);
		}

		return element('div', { className: 'overlay-control' }, [element('span', { className: 'overlay-control-label', text: t('overlay.ctl.position', {}, 'Position') }), grid]);
	}

	/** The text, with the Insert field menu that puts `${path}` at the cursor. */
	textControl(value) {
		const input = element('input', { type: 'text', value: value ?? '', className: 'overlay-text' });
		input.addEventListener('input', () => this.update('marktext', input.value));

		const menu = this.insertMenu(path => {
			const start = input.selectionStart ?? input.value.length;
			const end   = input.selectionEnd ?? start;
			const next  = insertPlaceholder(input.value, start, end, path);
			input.value = next.text;
			input.focus();
			input.setSelectionRange(next.cursor, next.cursor);
			this.update('marktext', input.value);
		});

		return this.labelled(t('overlay.ctl.text', {}, 'Text'), element('span', { className: 'overlay-text-row' }, [input, menu]));
	}

	insertMenu(onPick) {
		const fields = this.designer.options?.fields ?? [];
		const menu   = element('select', { className: 'overlay-insert', 'aria-label': t('overlay.ctl.insert', {}, 'Insert field…') });

		const share = element('optgroup', { label: t('overlay.insert.share', {}, 'Share') }, [
			option('socialTitle', t('overlay.insert.title', {}, 'Share title')),
			option('site', t('overlay.insert.site', {}, 'Site name')),
			option('path', t('overlay.insert.path', {}, 'Page path')),
		]);
		const record = fields.length > 0
			? element('optgroup', { label: t('overlay.insert.record', {}, 'This record') }, fields.map(field => option(field.path, field.label)))
			: null;

		menu.replaceChildren(option('', t('overlay.ctl.insert', {}, 'Insert field…'), true), share, record ?? '');
		menu.addEventListener('change', () => {
			const path = menu.value;
			menu.value = '';
			if (path) onPick(path);
		});

		return menu;
	}

	/**
	 * A photo from a record: the field on this record that holds an id, the
	 * collection that id points to, and that collection's image. The path
	 * box below shows what it writes and takes any path by hand.
	 */
	photoBuilder(path) {
		const sources = this.designer.options?.imageSources ?? [];
		const fields  = this.designer.options?.fields ?? [];
		const parts   = parseImagePath(path) ?? { collection: '', field: '', image: '' };
		const choose  = t('overlay.ctl.choose', {}, 'Choose…');

		const fieldSelect = element('select');
		const fieldList   = fields.some(f => f.path === 'id') ? fields : [{ path: 'id', label: 'ID' }, ...fields];
		fieldSelect.replaceChildren(option('', choose), ...fieldList.map(f => option(f.path, f.label, f.path === parts.field)));
		if (parts.field && !fieldList.some(f => f.path === parts.field)) fieldSelect.append(option(parts.field, parts.field, true));
		fieldSelect.value = parts.field;

		const collectionSelect = element('select');
		const collections      = sources.some(s => s.id === parts.collection) || !parts.collection ? sources : [{ id: parts.collection, name: parts.collection, images: [] }, ...sources];
		collectionSelect.replaceChildren(option('', choose), ...collections.map(s => option(s.id, s.name, s.id === parts.collection)));
		collectionSelect.value = parts.collection;

		const imageSelect = element('select');
		const fillImages  = () => {
			const images = collections.find(s => s.id === collectionSelect.value)?.images ?? [];
			const keep   = images.some(i => i.path === parts.image) ? parts.image : (images[0]?.path ?? '');
			imageSelect.replaceChildren(option('', choose), ...images.map(i => option(i.path, i.label, i.path === keep)));
			imageSelect.value = keep;
		};
		fillImages();

		const pathInput = element('input', { type: 'text', value: path, className: 'overlay-path', placeholder: 'team/${author}/photo' });
		const write = () => {
			const built = imagePath({ collection: collectionSelect.value, field: fieldSelect.value, image: imageSelect.value });
			if (built === '') return;
			pathInput.value = built;
			this.update('markimage', built);
		};
		fieldSelect.addEventListener('change', write);
		collectionSelect.addEventListener('change', () => {
			fillImages();
			write();
		});
		imageSelect.addEventListener('change', write);
		pathInput.addEventListener('input', () => this.update('markimage', pathInput.value.trim()));

		return element('div', { className: 'overlay-photo' }, [
			this.labelled(t('overlay.ctl.photo_field', {}, 'Record field with the id'), fieldSelect),
			this.labelled(t('overlay.ctl.photo_collection', {}, 'Collection'), collectionSelect),
			this.labelled(t('overlay.ctl.photo_image', {}, 'Image'), imageSelect),
			this.labelled(t('overlay.ctl.photo_path', {}, 'Path'), pathInput, 'overlay-control-wide'),
		]);
	}
}
