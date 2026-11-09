import { vi } from 'vite-plus/test';

//-----------------------------------------------
// The public depot drop: the comment box holds the queue until Send, the bot
// fields and the comment ride along with every chunk, and a refusal shows the
// server's words on the file card.
//-----------------------------------------------

const made = vi.hoisted(() => []);

vi.mock('@deltablot/dropzone', () => {
	class FakeDropzone {
		constructor(element, options) {
			this.element = element;
			this.options = options;
			this.handlers = {};
			this.queued = [];
			this.processed = 0;
			made.push(this);
		}
		on(name, fn) { this.handlers[name] = fn; }
		emit(name, ...args) { return this.handlers[name]?.(...args); }
		getQueuedFiles() { return this.queued; }
		// One chunk request, built the way Dropzone builds it: the `params` option
		// REPLACES the default that carries the dz* chunk fields, then `sending`
		// fires so listeners can add to the FormData. Do not simplify this; a
		// fake that skips the replacement is how a params override once hid.
		request() {
			const chunk = { index: 0, file: { size: 12, upload: { uuid: 'u-1', totalChunkCount: 3 } } };
			const fallback = () => ({ dzuuid: chunk.file.upload.uuid, dzchunkindex: chunk.index, dztotalchunkcount: 3 });
			const params = typeof this.options.params === 'function' ? this.options.params([], {}, chunk) : fallback();
			const formData = new FormData();
			for (const [k, v] of Object.entries(params || {})) formData.append(k, v);
			this.emit('sending', {}, {}, formData);
			return formData;
		}
		processQueue() { this.processed++; }
	}
	FakeDropzone.createElement = (html) => {
		const el = document.createElement('div');
		el.innerHTML = html;
		return el.firstElementChild;
	};
	return { default: FakeDropzone };
});

const DepotDrop = (await import('../../javascript/totalform/depot-drop.js')).default;
const TotalForm = (await import('../../javascript/totalform/totalform.js')).default;

const CARD = '<li class="depot-drop-card"><div class="dz-preview"><div class="file-icon"></div><p class="filename"></p></div></li>';

function build({ settings = null, comments = false, bot = false, tags = false } = {}) {
	const el = document.createElement('div');
	if (settings) el.dataset.settings = JSON.stringify(settings);
	el.dataset.type = 'depotDrop';
	el.innerHTML = `
		<div class="form-group">
			<input type="text" name="files"/>
			${bot ? '<div class="depot-drop-bot"><input type="text" name="extra_reference_code"/><input type="hidden" name="form_ts" value="TOKEN"/></div>' : ''}
			<div class="depot-drop-zone dz-clickable"><ul class="total-preview"></ul></div>
			<template class="file-template">${CARD}</template>
			${comments || tags ? `<div class="depot-drop-comment">${tags ? '<select class="depot-drop-tag"><option value=""></option><option value="Invoice">Invoice</option></select>' : ''}${comments ? '<textarea class="depot-drop-comment-text" maxlength="1000"></textarea>' : ''}<button type="button" class="depot-drop-send"></button></div>` : ''}
		</div>`;
	document.body.appendChild(el);
	const form = { collection: 'docs', id: 'inbox', api: { headers: {}, buildApiQuery: (a) => `/api${a}` }, form: document.createElement('form'), runEditActions: vi.fn() };
	const field = new DepotDrop(el, { form });
	return { field, el, dz: made[made.length - 1] };
}

function addFile(field, name = 'a.pdf') {
	const file = { name };
	field.onFileAdded(file);
	return file;
}

beforeEach(() => {
	made.length = 0;
	document.body.innerHTML = '';
});

describe('DepotDrop remove button', () => {
	const removeOf = (file) => file.previewElement.querySelector('.depot-drop-remove');

	test('a queued file can be taken out of the batch', () => {
		const { field, dz } = build({ comments: true });
		dz.removeFile = vi.fn();
		const file = addFile(field);

		removeOf(file).click();

		expect(dz.removeFile).toHaveBeenCalledWith(file);
	});

	test('the click does not reach the drop area, which opens the file picker', () => {
		const { field, el, dz } = build({ comments: true });
		dz.removeFile = vi.fn();
		const zone = vi.fn();
		el.querySelector('.depot-drop-zone').addEventListener('click', zone);
		const file = addFile(field);
		el.querySelector('.total-preview').appendChild(file.previewElement);

		removeOf(file).click();

		expect(zone).not.toHaveBeenCalled();
	});

	test('a file on its way loses the button, and a refused one gets it back', () => {
		const { field, dz } = build({ comments: true });
		const file = addFile(field);

		dz.emit('processing', file);
		expect(removeOf(file)).toBeNull();

		field.onError(file, 'This type of file is not accepted.');
		expect(removeOf(file)).not.toBeNull();
		expect(file.previewElement.querySelectorAll('.depot-drop-remove')).toHaveLength(1);
	});

	test('a refused file does not get a second button', () => {
		const { field } = build();
		const file = addFile(field);

		field.onError(file, 'This type of file is not accepted.');

		expect(file.previewElement.querySelectorAll('.depot-drop-remove')).toHaveLength(1);
	});

	test('the label comes from the page', () => {
		const { field } = build({ settings: { messages: { remove_file: 'Quitar' } } });
		const file = addFile(field);

		expect(removeOf(file).getAttribute('aria-label')).toBe('Quitar');
	});
});

describe('DepotDrop tag menu', () => {
	test('a tag menu alone holds the queue until send', () => {
		const { field, el, dz } = build({ tags: true });
		expect(dz.options.autoProcessQueue).toBe(false);

		addFile(field);
		dz.getQueuedFiles = () => [{}];
		el.querySelector('.depot-drop-send').click();

		expect(dz.options.autoProcessQueue).toBe(true);
		expect(el.querySelector('.depot-drop-tag').disabled).toBe(true);
	});

	test('the picked tag goes with the upload, fixed at send time', () => {
		const { field, el, dz } = build({ tags: true });
		dz.getQueuedFiles = () => [{}];
		el.querySelector('.depot-drop-tag').value = 'Invoice';
		field.send();
		el.querySelector('.depot-drop-tag').value = '';

		expect(JSON.parse(field.extraParams().meta)).toEqual({ tags: ['Invoice'] });
	});

	test('no pick sends no meta', () => {
		const { field, dz } = build({ tags: true });
		dz.getQueuedFiles = () => [{}];
		field.send();

		expect(field.extraParams().meta).toBeUndefined();
	});

	test('comment and tag travel together', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true, tags: true });
		dz.getQueuedFiles = () => [{}];
		el.querySelector('.depot-drop-comment-text').value = 'note';
		el.querySelector('.depot-drop-tag').value = 'Invoice';
		field.send();

		expect(JSON.parse(field.extraParams().meta)).toEqual({ comments: 'note', tags: ['Invoice'] });
	});

	test('the tag stays after a refusal and clears after a success', () => {
		const { field, el, dz } = build({ tags: true });
		dz.getQueuedFiles = () => [{}];
		const select = el.querySelector('.depot-drop-tag');

		select.value = 'Invoice';
		field.send();
		field.onQueueComplete();
		expect(select.value).toBe('Invoice');
		expect(select.disabled).toBe(false);
		expect(dz.options.autoProcessQueue).toBe(false);

		field.send();
		field.hadSuccess = true;
		field.onQueueComplete();
		expect(select.value).toBe('');
	});
});

describe('DepotDrop comments', () => {
	test('comments on holds the queue until send', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true });
		expect(dz.options.autoProcessQueue).toBe(false);

		dz.queued = [addFile(field)];
		el.querySelector('.depot-drop-send').click();

		expect(dz.processed).toBe(1);
		expect(dz.options.autoProcessQueue).toBe(true);
	});

	test('send does nothing with an empty queue', () => {
		const { el, dz } = build({ settings: { comments: true }, comments: true });

		el.querySelector('.depot-drop-send').click();

		expect(dz.processed).toBe(0);
		expect(dz.options.autoProcessQueue).toBe(false);
	});

	test('comments off still uploads on drop', () => {
		const { dz } = build();
		expect(dz.options.autoProcessQueue).toBe(true);
	});

	test('the comment goes with every chunk as meta, fixed at send time', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true });
		dz.queued = [addFile(field)];
		el.querySelector('.depot-drop-comment-text').value = 'for the team';
		el.querySelector('.depot-drop-send').click();
		el.querySelector('.depot-drop-comment-text').value = 'changed later';

		const data = dz.request();

		expect(JSON.parse(data.get('meta'))).toEqual({ comments: 'for the team' });
		expect(data.get('dzuuid')).toBe('u-1');
	});

	test('an empty comment is allowed', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true });
		dz.queued = [addFile(field)];
		el.querySelector('.depot-drop-send').click();

		expect(JSON.parse(dz.request().get('meta'))).toEqual({ comments: '' });
	});

	test('comments off sends no meta', () => {
		const { dz } = build();
		const data = dz.request();
		expect(data.has('meta')).toBe(false);
		expect(data.get('dzuuid')).toBe('u-1');
	});

	test('the comment clears after queuecomplete only when a file succeeded', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true });
		const text = el.querySelector('.depot-drop-comment-text');
		const file = addFile(field);

		text.value = 'keep me';
		dz.queued = [file];
		el.querySelector('.depot-drop-send').click();
		dz.emit('error', file, 'nope', { status: 400 });
		dz.emit('queuecomplete');
		expect(text.value).toBe('keep me');
		expect(dz.options.autoProcessQueue).toBe(false);

		const second = addFile(field, 'b.pdf');
		dz.queued = [second];
		el.querySelector('.depot-drop-send').click();
		dz.emit('success', second, { status: 'ok' });
		dz.emit('queuecomplete');
		expect(text.value).toBe('');
	});
});

describe('DepotDrop public grant', () => {
	test('bot fields go with every chunk', () => {
		const { el, dz } = build({ bot: true });
		el.querySelector('[name=extra_reference_code]').value = '';

		const data = dz.request();

		expect(data.get('form_ts')).toBe('TOKEN');
		expect(data.get('extra_reference_code')).toBe('');
		expect(data.get('dzchunkindex')).toBe('0');
	});

	test('the accept list and size cap come from the settings, the chunk size stays', () => {
		const { dz } = build({ settings: { acceptedFiles: '.pdf,.jpg', maxFilesize: 5 } });

		expect(dz.options.acceptedFiles).toBe('.pdf,.jpg');
		expect(dz.options.maxFilesize).toBe(5);
		expect(dz.options.maxFiles).toBeNull();
		expect(dz.options.chunkSize).toBe(5 * 1024 * 1024);
	});
});

describe('DepotDrop refusals', () => {
	function refuse(message, xhr) {
		const { field, el, dz } = build();
		const file = addFile(field);
		dz.emit('error', file, message, xhr);
		return { file, el };
	}

	test('a 400 details array shows on the file card', () => {
		const { file } = refuse({ error: 'File upload validation failed', details: ['That file type is not accepted.', 'Too big.'] }, { status: 400 });

		const shown = file.previewElement.querySelector('.dz-error-message');
		expect(shown.textContent).toBe('That file type is not accepted. Too big.');
		expect(file.previewElement.classList.contains('dz-error')).toBe(true);
	});

	test('a 422 shows the reload message', () => {
		const { file } = refuse({ error: { message: 'This form has expired. Reload the page and try again.' } }, { status: 422 });

		expect(file.previewElement.querySelector('.dz-error-message').textContent).toContain('Reload the page');
	});

	test('a 422 shows the rendered message, not the server\'s words, even when they mention reloading', () => {
		const { field, dz } = build({ settings: { messages: { upload_expired: 'Formular abgelaufen.' } } });
		const file = addFile(field);

		dz.emit('error', file, { error: { message: 'This form has expired. Reload the page and try again.' } }, { status: 422 });

		expect(file.previewElement.querySelector('.dz-error-message').textContent).toBe('Formular abgelaufen.');
	});

	test('an HTML error page from the host is never shown verbatim', () => {
		const { file } = refuse('<html><body>413 Request Entity Too Large</body></html>', { status: 413 });

		const text = file.previewElement.querySelector('.dz-error-message').textContent;
		expect(text).not.toContain('<');
		expect(text).toMatch(/try again/i);
	});

	test('a short plain string reply is shown', () => {
		const { file } = refuse("You can't upload files of this type.", undefined);

		expect(file.previewElement.querySelector('.dz-error-message').textContent).toBe("You can't upload files of this type.");
	});

	test('a 422 with an unreadable body still says to reload', () => {
		const { file } = refuse('<html>', { status: 422 });

		expect(file.previewElement.querySelector('.dz-error-message').textContent).toMatch(/reload/i);
	});

	test('server and visitor text is never parsed as HTML', () => {
		const { file } = refuse({ details: ['<img src=x onerror=alert(1)>'] }, { status: 400 });

		const shown = file.previewElement.querySelector('.dz-error-message');
		expect(shown.querySelector('img')).toBeNull();
		expect(shown.textContent).toBe('<img src=x onerror=alert(1)>');
	});

	test('a visitor filename is never parsed as HTML', () => {
		const { field } = build();
		const file = addFile(field, '<img src=x onerror=alert(1)>.pdf');

		expect(file.previewElement.querySelector('img')).toBeNull();
		expect(file.previewElement.querySelector('.filename').textContent).toBe('<img src=x onerror=alert(1)>.pdf');
	});
});

describe('DepotDrop and form edit actions', () => {
	test('the bot token reaches the payload a mailer edit action receives', () => {
		const { field } = build({ bot: true });
		field.form.form.classList.add('depot-drop-form');
		const form = Object.create(TotalForm.prototype);
		form.fields = [field];

		const data = form.generateData();

		expect(data.form_ts).toBe('TOKEN');
		expect(data.extra_reference_code).toBe('');
	});

	test('a drop field in a regular form leaves the form\'s own token and honeypot alone', () => {
		const { field } = build({ bot: true });
		const own = (property, value) => ({ property, isSubField: () => false, getValue: () => value });
		const form = Object.create(TotalForm.prototype);
		form.fields = [own('form_ts', 'CREATE-TOKEN'), own('extra_reference_code', 'bot-filled'), field];

		const data = form.generateData();

		expect(data.form_ts).toBe('CREATE-TOKEN');
		expect(data.extra_reference_code).toBe('bot-filled');
	});

	test('a drop with no bot fields adds nothing to the payload', () => {
		const { field } = build();
		const form = Object.create(TotalForm.prototype);
		form.fields = [field];

		expect(Object.keys(form.generateData())).toEqual(['files']);
	});
});

describe('DepotDrop visual feedback', () => {
	test('a refused file does not mark the comment box as invalid', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true });
		const file = addFile(field);

		dz.emit('error', file, { details: ['This type of file is not allowed.'] }, { status: 400 });

		expect(el.classList.contains('error')).toBe(false);
		expect(file.previewElement.classList.contains('dz-error')).toBe(true);
	});

	test('without a comment box a refusal still marks the field, as before', () => {
		const { field, el, dz } = build();
		dz.emit('error', addFile(field), 'nope', { status: 400 });

		expect(el.classList.contains('error')).toBe(true);
	});

	test('a sent card is marked and a status line appears after the batch', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true });
		const file = addFile(field);
		dz.queued = [file];
		el.querySelector('.depot-drop-send').click();

		dz.emit('success', file, { status: 'ok' });
		dz.emit('queuecomplete');

		expect(file.previewElement.classList.contains('dz-success')).toBe(true);
		expect(file.previewElement.querySelector('.dz-progress .dz-status')).not.toBeNull();
		const line = el.querySelector('.depot-drop-comment .depot-drop-status');
		expect(line.getAttribute('role')).toBe('status');
		expect(line.textContent).toBe('Your files were sent.');
	});

	test('no status line when every file was refused, and it clears when new files are added', () => {
		const { field, el, dz } = build({ settings: { comments: true }, comments: true });
		const refused = addFile(field);
		dz.queued = [refused];
		el.querySelector('.depot-drop-send').click();
		dz.emit('error', refused, 'nope', { status: 400 });
		dz.emit('queuecomplete');
		expect(el.querySelector('.depot-drop-status').textContent).toBe('');

		const ok = addFile(field, 'b.pdf');
		dz.queued = [ok];
		el.querySelector('.depot-drop-send').click();
		dz.emit('success', ok, {});
		dz.emit('queuecomplete');
		expect(el.querySelector('.depot-drop-status').textContent).not.toBe('');

		addFile(field, 'c.pdf');
		expect(el.querySelector('.depot-drop-status').textContent).toBe('');
	});

	test('a comments-off drop gets the card mark and a status line after the zone', () => {
		const { field, el, dz } = build();
		const file = addFile(field);

		dz.emit('success', file, {});
		dz.emit('queuecomplete');

		expect(file.previewElement.querySelector('.dz-progress')).not.toBeNull();
		expect(el.querySelector('.depot-drop-zone + .depot-drop-status').textContent).toBe('Your files were sent.');
	});
});

describe('DepotDrop bot wait', () => {
	beforeEach(() => vi.useFakeTimers());
	afterEach(() => vi.useRealTimers());

	test('a drop right after the page loads waits until the bot check would pass', () => {
		const { dz } = build({ settings: { botWait: 3 }, bot: true });

		expect(dz.options.autoProcessQueue).toBe(false);
		vi.advanceTimersByTime(2999);
		expect(dz.processed).toBe(0);

		vi.advanceTimersByTime(1);
		expect(dz.processed).toBe(1);
		expect(dz.options.autoProcessQueue).toBe(true);
	});

	test('send right after the page loads waits too, with the comment fixed at the click', () => {
		const { field, el, dz } = build({ settings: { botWait: 3, comments: true }, comments: true, bot: true });
		dz.queued = [{ name: 'a.pdf' }];
		const text = el.querySelector('.depot-drop-comment-text');
		text.value = 'first';

		field.send();
		text.value = 'changed';
		expect(dz.processed).toBe(0);
		expect(el.querySelector('.depot-drop-send').disabled).toBe(true);

		vi.advanceTimersByTime(3000);
		expect(dz.processed).toBe(1);
		expect(JSON.parse(dz.request().get('meta')).comments).toBe('first');
	});

	test('send after the wait starts at once', () => {
		const { field, dz } = build({ settings: { botWait: 3, comments: true }, comments: true, bot: true });
		dz.queued = [{ name: 'a.pdf' }];
		vi.advanceTimersByTime(3000);

		field.send();

		expect(dz.processed).toBe(1);
	});

	test('a drop with no bot check does not wait', () => {
		const { dz } = build();

		expect(dz.options.autoProcessQueue).toBe(true);
	});
});
