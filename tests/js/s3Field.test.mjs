import TotalField from '../../javascript/totalform/totalfield.js';
import {
	checkRules,
	defineS3DepotField,
	defineS3FileField,
	downloadsLabel,
	fileKind,
	formatSize,
	probeDuration,
	putErrorMessage,
	putWithProgress,
} from '../../resources/extensions/totalcms/s3-uploads/assets/s3-field.js';

//-----------------------------------------------
// The s3file field (bundled extension totalcms/s3-uploads): the browser side
// of an upload that goes straight to the bucket. The bucket is a fake
// XMLHttpRequest and the extension's admin routes a fake fetch, so each test
// can say what the bucket and the server answer.
//-----------------------------------------------

const SCRIPT = '../../resources/extensions/totalcms/s3-uploads/assets/s3-field.js';
const S3FileField = defineS3FileField(TotalField);
const S3DepotField = defineS3DepotField(TotalField);

const POINTER = {
	id: '0a1b2c3d', key: 'site/blog/post-1/brochure/0a1b2c3d-brochure.pdf', name: 'Brochure 2026.pdf',
	size: 48211334, mime: 'application/pdf', etag: 'e', uploadedAt: '2026-09-05T14:02:11+00:00', uploadedBy: 'joe',
};

/** The markup S3FileField.php renders. */
function fieldElement({ value = null, settings = null, uploads = null } = {}) {
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123"><base href="https://example.com/admin/">';
	document.body.innerHTML = '';

	const container = document.createElement('div');
	container.className = 'form-field s3file-field';
	container.dataset.type = 's3file';
	if (settings) container.dataset.settings = JSON.stringify(settings);
	container.innerHTML = `
		<label>Brochure</label>
		<div class="form-group">
			<input type="text" name="brochure" data-proxy="true">
			<div class="s3-field" data-state="empty"${uploads ? ` data-uploads="${uploads}"` : ''}>
				<div class="s3-drop"><p>Drop a file here, or <button type="button" class="s3-choose">Choose file</button></p><input type="file" class="s3-input" hidden></div>
				<div class="s3-card" hidden>
					<div class="s3-meta"><strong class="s3-name"></strong><span class="s3-details"><span class="s3-size"></span><span class="s3-date"></span><span class="s3-downloads"></span></span></div>
					<progress class="s3-progress" max="100" value="0" hidden></progress>
					<div class="s3-actions">
						<a class="s3-open">Open</a>
						<button type="button" class="s3-replace">Replace</button>
						<button type="button" class="s3-remove">Remove</button>
						<button type="button" class="s3-cancel" hidden>Cancel</button>
						<button type="button" class="s3-retry" hidden>Try again</button>
					</div>
				</div>
				<p class="s3-error" role="alert" hidden></p>
			</div>
		</div>`;
	container.querySelector('input[name="brochure"]').value = value ? JSON.stringify(value) : '';
	document.body.append(container);
	return container;
}

/** As much of TotalForm as the field asks of it. */
function fakeForm({ id = 'post-1', editing = true } = {}) {
	return {
		collection : 'blog',
		id,
		editing,
		getId() { return this.id; },
		isEditMode() { return this.editing; },
		api : {},
	};
}

function build(options = {}, form = fakeForm()) {
	const container = fieldElement(options);
	return new S3FileField(container, { form });
}

const pdf = (name = 'a.pdf', size = 2048, type = 'application/pdf') => new File([new Uint8Array(size)], name, { type });

/** A fake bucket: every PUT is recorded and answered with FakeXHR.status on the next tick. */
class FakeXHR {
	static requests = [];
	static status   = 200;
	static hold     = false; // true: the test answers by hand

	constructor() {
		this.headers   = {};
		this.listeners = {};
		this.upload    = { listeners: {}, addEventListener: (type, fn) => { this.upload.listeners[type] = fn; } };
		FakeXHR.requests.push(this);
	}
	open(method, url) { this.method = method; this.url = url; }
	setRequestHeader(name, value) { this.headers[name] = value; }
	addEventListener(type, fn) { this.listeners[type] = fn; }
	send(body) {
		this.body = body;
		if (!FakeXHR.hold) queueMicrotask(() => this.answer(FakeXHR.status));
	}
	answer(status) {
		this.upload.listeners.progress?.({ lengthComputable: true, loaded: 1, total: 2 });
		this.status = status;
		if (status === 0) this.listeners.error?.();
		else this.listeners.load?.();
	}
	abort() { this.listeners.abort?.(); }
}

/** Fake admin routes: presign and commit succeed unless a test overrides one. */
function fakeRoutes(overrides = {}) {
	const calls = [];
	const json  = (status, body) => Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

	globalThis.fetch = vi.fn((url, init = {}) => {
		const name = String(url).split('/').pop().split('?')[0];
		const body = init.body ? JSON.parse(init.body) : null;
		calls.push({ name, url: String(url), init, body });

		if (overrides[name]) return overrides[name](body, json);
		if (name === 'presign') {
			return json(200, { url: 'https://bucket.example/key?X-Amz-Signature=abc', method: 'PUT', headers: { 'Content-Type': body.mime }, key: 'key', token: 'tok' });
		}
		if (name === 'commit') {
			return json(200, { value: { ...POINTER, name: 'a.pdf', size: 2048 }, item: {} });
		}
		return json(200, { counts: { brochure: 12 } });
	});

	return calls;
}

const settled = () => new Promise(resolve => setTimeout(resolve, 0));

beforeEach(() => {
	FakeXHR.requests = [];
	FakeXHR.status   = 200;
	FakeXHR.hold     = false;
	globalThis.XMLHttpRequest = FakeXHR;
	vi.restoreAllMocks();
});

describe('registration', () => {
	test('the script registers both field types when the admin registry is there', async () => {
		const registerFieldType = vi.fn();
		globalThis.TotalCMS = { TotalField, registerFieldType };
		vi.resetModules();

		await import(SCRIPT);

		expect(registerFieldType.mock.calls.map(call => call[0])).toEqual(['s3file', 's3depot']);
		expect(Object.getPrototypeOf(registerFieldType.mock.calls[0][1])).toBe(TotalField);
		expect(Object.getPrototypeOf(registerFieldType.mock.calls[1][1])).toBe(TotalField);
		delete globalThis.TotalCMS;
	});

	test('without the registry it loads quietly', async () => {
		delete globalThis.TotalCMS;
		vi.resetModules();

		await expect(import(SCRIPT)).resolves.toBeDefined();
	});
});

describe('formatSize', () => {
	test.each([[0, '0 B'], [999, '999 B'], [1024, '1 KB'], [1536, '1.5 KB'], [48211334, '46 MB'], [1288490189, '1.2 GB'], [5497558138880, '5120 GB']])(
		'%i bytes reads as %s, the same as on the server',
		(bytes, label) => expect(formatSize(bytes)).toBe(label),
	);
});

describe('checkRules', () => {
	const file = (overrides = {}) => ({ name: 'a.pdf', size: 1024000, type: 'application/pdf', ...overrides });

	test('no rules allow the file', () => {
		expect(checkRules({}, file())).toEqual([]);
		expect(checkRules(undefined, file())).toEqual([]);
	});

	test('a size limit is in KB', () => {
		expect(checkRules({ size: { max: 1000 } }, file())).toEqual([]);
		expect(checkRules({ size: { max: 1000 } }, file({ size: 1024001 }))).toEqual(['a.pdf is 1000 KB. The limit for this field is 1000 KB.']);
		expect(checkRules({ size: { min: 2 } }, file({ size: 1536 }))).toEqual(['a.pdf is 1.5 KB. Files for this field must be at least 2 KB.']);
	});

	test('more than 5 GiB cannot go in a single upload', () => {
		expect(checkRules({}, file({ name: 'movie.mov', size: 5368709121 }))).toEqual(['movie.mov is larger than 5 GB, the most a single upload can carry.']);
	});

	test('file types and names are regular expressions and any one may match', () => {
		const rules = { filetype: ['application/pdf', 'audio/.*'], filename: ['\\.pdf$', '\\.mp3$'] };

		expect(checkRules(rules, file())).toEqual([]);
		expect(checkRules(rules, file({ name: 'a.mp3', type: 'audio/mpeg' }))).toEqual([]);
		expect(checkRules(rules, file({ name: 'a.zip', type: 'application/zip' }))).toEqual([
			'a.zip is not an accepted file type for this field.',
			'a.zip is not an accepted file name for this field.',
		]);
		expect(checkRules({ filetype: 'application/pdf' }, file())).toEqual([]);
	});

	test('a rule that does not compile refuses the file', () => {
		expect(checkRules({ filetype: ['('] }, file())).toEqual(['a.pdf is not an accepted file type for this field.']);
	});

	test('a file with no type is checked as a plain byte stream', () => {
		expect(checkRules({ filetype: ['application/octet-stream'] }, file({ type: '' }))).toEqual([]);
	});

	test('a count limit refuses once the field is full', () => {
		expect(checkRules({ count: { max: 2 } }, file(), 2)).toEqual(['This field holds at most 2 files.']);
		expect(checkRules({ count: { max: 2 } }, file(), 1)).toEqual([]);
	});
});

describe('probeDuration', () => {
	/** Stand-in for <audio>: the test fires its events. */
	function fakeMedia() {
		const media = { listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; }, removeAttribute() {}, duration: NaN };
		vi.spyOn(document, 'createElement').mockReturnValue(media);
		URL.createObjectURL = vi.fn(() => 'blob:x');
		URL.revokeObjectURL = vi.fn();
		return media;
	}

	test('a document has no duration and is not probed', async () => {
		const create = vi.spyOn(document, 'createElement');

		await expect(probeDuration(pdf())).resolves.toBeNull();
		expect(create).not.toHaveBeenCalled();
	});

	test('audio resolves with its length once the metadata loads', async () => {
		const media   = fakeMedia();
		const pending = probeDuration(pdf('a.mp3', 10, 'audio/mpeg'));
		media.duration = 2714.3;
		media.listeners.loadedmetadata();

		await expect(pending).resolves.toBe(2714.3);
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x');
	});

	test('a file the browser cannot decode resolves with null', async () => {
		const media   = fakeMedia();
		const pending = probeDuration(pdf('a.mp4', 10, 'video/mp4'));
		media.listeners.error();

		await expect(pending).resolves.toBeNull();
	});

	test('a file that never loads resolves with null after the timeout', async () => {
		vi.useFakeTimers();
		fakeMedia();
		const pending = probeDuration(pdf('a.mp3', 10, 'audio/mpeg'), 5000);
		vi.advanceTimersByTime(5000);

		await expect(pending).resolves.toBeNull();
		vi.useRealTimers();
	});
});

describe('putWithProgress', () => {
	test('it PUTs the file with only the headers it is given, without cookies, and reports progress', async () => {
		const progress = vi.fn();
		const file     = pdf();

		await putWithProgress('https://bucket.example/key', file, { 'Content-Type': 'application/pdf' }, progress);

		const xhr = FakeXHR.requests[0];
		expect(xhr.method).toBe('PUT');
		expect(xhr.url).toBe('https://bucket.example/key');
		expect(xhr.headers).toEqual({ 'Content-Type': 'application/pdf' });
		expect(xhr.withCredentials).toBe(false);
		expect(xhr.body).toBe(file);
		expect(progress).toHaveBeenCalledWith(0.5);
	});

	test('an empty file reports no progress instead of a broken fraction', async () => {
		const progress = vi.fn();
		const original = FakeXHR.prototype.answer;
		FakeXHR.prototype.answer = function (status) {
			this.upload.listeners.progress?.({ lengthComputable: true, loaded: 0, total: 0 });
			this.status = status;
			this.listeners.load?.();
		};

		await putWithProgress('https://bucket.example/key', pdf('empty.txt', 0, 'text/plain'), {}, progress);
		FakeXHR.prototype.answer = original;

		expect(progress).not.toHaveBeenCalled();
	});

	test('a refusal rejects with the status', async () => {
		FakeXHR.status = 403;

		await expect(putWithProgress('https://bucket.example/key', pdf(), {}, null)).rejects.toEqual({ status: 403 });
	});

	test('a blocked request rejects with status 0', async () => {
		FakeXHR.status = 0;

		await expect(putWithProgress('https://bucket.example/key', pdf(), {}, null)).rejects.toEqual({ status: 0 });
	});

	test('the messages name the likely cause', () => {
		expect(putErrorMessage({ status: 0 })).toContain('missing CORS rules on the bucket');
		expect(putErrorMessage({ status: 403 })).toBe('The bucket refused the upload (403). The upload link may have expired, or the key lacks write permission.');
		expect(putErrorMessage({ status: 500 })).toBe('The bucket refused the upload (500).');
	});
});

describe('S3FileField', () => {
	test('an empty field shows the drop zone and has no value', () => {
		fakeRoutes();
		const field = build();

		expect(field.getValue()).toBeNull();
		expect(field.isDroplet()).toBe(true);
		expect(field.root.dataset.state).toBe('empty');
		expect(field.drop.hidden).toBe(false);
		expect(field.card.hidden).toBe(true);
	});

	test('fileKind names what a file is from its type, then its name, as the server does', () => {
		expect(fileKind('photo.JPG', 'image/jpeg')).toBe('image');
		expect(fileKind('episode.mp3', 'audio/mpeg')).toBe('audio');
		expect(fileKind('clip.mov', 'video/quicktime')).toBe('video');
		expect(fileKind('brochure.pdf', 'application/pdf')).toBe('doc');
		expect(fileKind('notes.txt', 'text/plain')).toBe('text');
		expect(fileKind('prices.csv', 'text/csv')).toBe('sheet');
		expect(fileKind('deck.pptx', 'application/octet-stream')).toBe('slides');
		expect(fileKind('site.zip', 'application/zip')).toBe('archive');
		expect(fileKind('data.json', 'application/json')).toBe('code');
		expect(fileKind('photo.heic', '')).toBe('image');
		expect(fileKind('mystery', 'application/octet-stream')).toBe('file');
		expect(fileKind(undefined, undefined)).toBe('file');
	});

	test('the downloads label adds the all-time total only when it says more than the count', () => {
		expect(downloadsLabel(12, 12)).toBe('12 downloads');
		expect(downloadsLabel(1, undefined)).toBe('1 download');
		expect(downloadsLabel(7, 40)).toBe('7 downloads · 40 all time');
		// A file that replaced a downloaded one: nothing yet for it, but the history shows.
		expect(downloadsLabel(undefined, 40)).toBe('0 downloads · 40 all time');
		expect(downloadsLabel(undefined, undefined)).toBe('');
		expect(downloadsLabel('x', null)).toBe('');
	});

	test('a stored file shows its card, an Open link and its download count', async () => {
		fakeRoutes();
		const field = build({ value: POINTER });
		await settled();

		const open = new URL(field.root.querySelector('.s3-open').href);

		expect(field.getValue()).toEqual(POINTER);
		expect(field.root.dataset.state).toBe('filled');
		expect(field.root.querySelector('.s3-name').textContent).toBe('Brochure 2026.pdf');
		expect(field.root.querySelector('.s3-size').textContent).toBe('46 MB');
		expect(field.root.querySelector('.s3-date').textContent).toBe('2026-09-05');
		expect(field.root.querySelector('.s3-downloads').textContent).toBe('12 downloads');
		expect(field.card.dataset.kind).toBe('doc');
		expect(open.pathname).toBe('/admin/ext/totalcms/s3-uploads/open');
		expect(Object.fromEntries(open.searchParams)).toEqual({ collection: 'blog', id: 'post-1', property: 'brochure' });
		expect(field.isUnsaved()).toBe(false);
	});

	test('on a saved object a chosen file uploads at once and lands as the saved value', async () => {
		const calls = fakeRoutes();
		const field = build();

		field.selectFile(pdf());
		await settled();

		const [presign, commit] = calls.filter(call => call.name !== 'stats');

		expect(presign.name).toBe('presign');
		expect(presign.url).toBe('https://example.com/admin/ext/totalcms/s3-uploads/presign');
		expect(presign.body).toEqual({ collection: 'blog', id: 'post-1', property: 'brochure', filename: 'a.pdf', size: 2048, mime: 'application/pdf' });
		expect(presign.init.headers['X-CSRF-Token']).toBe('csrf-123');
		expect(commit.name).toBe('commit');
		expect(commit.body).toEqual({ token: 'tok' });
		expect(commit.init.headers['X-CSRF-Token']).toBe('csrf-123');

		// The bucket gets the type and nothing that belongs to this site.
		expect(FakeXHR.requests).toHaveLength(1);
		expect(FakeXHR.requests[0].headers).toEqual({ 'Content-Type': 'application/pdf' });

		expect(field.getValue().name).toBe('a.pdf');
		expect(JSON.parse(field.input.value).key).toBe(POINTER.key);
		expect(field.isUnsaved()).toBe(false);
		expect(field.droplet.isComplete()).toBe(true);
		expect(field.root.dataset.state).toBe('filled');
	});

	test('the file input feeds the same path', async () => {
		fakeRoutes();
		const field = build();
		Object.defineProperty(field.fileInput, 'files', { value: [pdf()], configurable: true });
		Object.defineProperty(field.fileInput, 'value', { value: '', writable: true, configurable: true });

		field.fileInput.dispatchEvent(new Event('change', { bubbles: true }));
		await settled();

		expect(field.getValue().name).toBe('a.pdf');
	});

	test('on a new object the file waits for the form to save, then uploads against the new id', async () => {
		const calls = fakeRoutes();
		const form  = fakeForm({ id: '', editing: false });
		const field = build({}, form);

		field.selectFile(pdf());
		await settled();

		expect(calls).toHaveLength(0);
		expect(FakeXHR.requests).toHaveLength(0);
		expect(field.isUnsaved()).toBe(true);
		expect(field.droplet.isComplete()).toBe(false);
		expect(field.root.dataset.state).toBe('queued');
		expect(field.root.querySelector('.s3-date').textContent).toBe('Uploads when you save');

		// What TotalForm.saveDroplets() does once the object exists.
		form.id = 'post-9';
		const done = vi.fn();
		field.updateAPIUrl();
		field.droplet.onQueueComplete(done);
		field.droplet.autoProcessQueue();
		field.droplet.processQueue();
		await settled();

		expect(calls[0].body.id).toBe('post-9');
		expect(done).toHaveBeenCalledTimes(1);
		expect(field.droplet.isComplete()).toBe(true);
		expect(field.isUnsaved()).toBe(false);
		expect(field.getValue().name).toBe('a.pdf');
	});

	test('a file that breaks a rule is refused before anything is sent', async () => {
		const calls = fakeRoutes();
		const field = build({ settings: { rules: { filetype: ['application/pdf'] } } });

		field.selectFile(pdf('a.zip', 10, 'application/zip'));
		await settled();

		expect(calls).toHaveLength(0);
		expect(field.message.textContent).toBe('a.zip is not an accepted file type for this field.');
		expect(field.message.hidden).toBe(false);
		expect(field.droplet.isComplete()).toBe(true);
		expect(field.isUnsaved()).toBe(false);
	});

	test('a blocked upload shows the CORS message and keeps the file for another try', async () => {
		const calls = fakeRoutes();
		FakeXHR.status = 0;
		const field = build();

		field.selectFile(pdf());
		await settled();

		expect(field.message.textContent).toContain('missing CORS rules on the bucket');
		expect(field.root.dataset.state).toBe('failed');
		expect(field.root.querySelector('.s3-retry').hidden).toBe(false);
		expect(field.getValue()).toBeNull();
		expect(calls.map(call => call.name)).toEqual(['presign']);

		FakeXHR.status = 200;
		field.root.querySelector('.s3-retry').click();
		await settled();

		expect(field.getValue().name).toBe('a.pdf');
		expect(field.message.hidden).toBe(true);
	});

	test('a refusal from the site shows the server\'s own message', async () => {
		fakeRoutes({ presign: (body, json) => json(403, { error: 'You do not have permission to upload files to blog.', code: 's3.forbidden' }) });
		const field = build();

		field.selectFile(pdf());
		await settled();

		expect(field.message.textContent).toBe('You do not have permission to upload files to blog.');
		expect(FakeXHR.requests).toHaveLength(0);
	});

	test('during a deferred upload a failure holds the form until the editor retries or cancels', async () => {
		fakeRoutes();
		FakeXHR.status = 0;
		const form  = fakeForm({ id: '', editing: false });
		const field = build({}, form);
		field.selectFile(pdf());

		form.id = 'post-9';
		const done = vi.fn();
		field.droplet.onQueueComplete(done);
		field.droplet.processQueue();
		await settled();

		expect(done).not.toHaveBeenCalled();
		expect(field.droplet.isComplete()).toBe(false);

		field.root.querySelector('.s3-cancel').click();
		await settled();

		expect(done).toHaveBeenCalledTimes(1);
		expect(field.droplet.isComplete()).toBe(true);
		expect(field.isUnsaved()).toBe(false);
		expect(field.root.dataset.state).toBe('empty');
	});

	test('Cancel stops a running upload and nothing is committed', async () => {
		const calls = fakeRoutes();
		FakeXHR.hold = true;
		const field = build({ value: POINTER });

		field.selectFile(pdf());
		await settled();
		expect(field.root.dataset.state).toBe('uploading');
		expect(field.progress.hidden).toBe(false);

		field.root.querySelector('.s3-cancel').click();
		await settled();

		expect(calls.map(call => call.name)).not.toContain('commit');
		expect(field.getValue()).toEqual(POINTER);
		expect(field.root.dataset.state).toBe('filled');
		expect(field.isUnsaved()).toBe(false);
	});

	test('Cancel before the file has started to upload still cancels: nothing is sent or committed', async () => {
		let answer;
		const calls = fakeRoutes({
			presign: (body, json) => new Promise(resolve => {
				answer = () => resolve(json(200, { url: 'https://bucket.example/key', headers: { 'Content-Type': body.mime }, token: 'tok' }));
			}),
		});
		const field = build({ value: POINTER });

		field.selectFile(pdf());
		await settled();
		field.root.querySelector('.s3-cancel').click();
		answer();
		await settled();

		expect(FakeXHR.requests).toHaveLength(0);
		expect(calls.map(call => call.name)).not.toContain('commit');
		expect(field.getValue()).toEqual(POINTER);
		expect(field.root.dataset.state).toBe('filled');
		expect(field.droplet.isComplete()).toBe(true);
	});

	test('Cancel after the file reached the bucket stops before the commit', async () => {
		const calls = fakeRoutes();
		FakeXHR.hold = true;
		const field = build({ value: POINTER });

		field.selectFile(pdf());
		await settled();
		// The PUT finishes in the same instant the editor cancels.
		field.handle.cancelled = true;
		FakeXHR.requests[0].answer(200);
		await settled();

		expect(calls.map(call => call.name)).not.toContain('commit');
		expect(field.getValue()).toEqual(POINTER);
	});

	test('the form cannot save while a file is uploading', async () => {
		fakeRoutes();
		FakeXHR.hold = true;
		const field = build({ value: POINTER });

		expect(field.validate()).toBe(true);

		field.selectFile(pdf());
		await settled();

		expect(field.validate()).toBe(false);
		expect(field.message.textContent).toBe('Wait for the upload to finish, then save.');

		FakeXHR.requests[0].answer(200);
		await settled();

		expect(field.validate()).toBe(true);
		expect(field.message.hidden).toBe(true);
	});

	test('picking another file after a failed deferred upload starts it at once', async () => {
		fakeRoutes();
		FakeXHR.status = 0;
		const form  = fakeForm({ id: '', editing: false });
		const field = build({}, form);
		field.selectFile(pdf('first.pdf'));

		form.id = 'post-9';
		const done = vi.fn();
		field.droplet.onQueueComplete(done);
		field.droplet.processQueue();
		await settled();
		expect(field.root.dataset.state).toBe('failed');

		FakeXHR.status = 200;
		field.selectFile(pdf('second.pdf'));
		await settled();

		expect(done).toHaveBeenCalledTimes(1);
		expect(field.droplet.isComplete()).toBe(true);
		expect(FakeXHR.requests).toHaveLength(2);
	});

	test('Remove clears the value and marks the field unsaved', () => {
		fakeRoutes();
		const field = build({ value: POINTER });

		field.root.querySelector('.s3-remove').click();

		expect(field.getValue()).toBeNull();
		expect(field.input.value).toBe('');
		expect(field.isUnsaved()).toBe(true);
		expect(field.root.dataset.state).toBe('empty');
	});

	test('cancelling a failed upload keeps an unsaved removal unsaved', async () => {
		fakeRoutes();
		FakeXHR.status = 0;
		const field = build({ value: POINTER });

		field.root.querySelector('.s3-remove').click();
		field.selectFile(pdf());
		await settled();
		field.root.querySelector('.s3-cancel').click();
		await settled();

		// The file is gone from the field, and that still has to be saved.
		expect(field.getValue()).toBeNull();
		expect(field.isUnsaved()).toBe(true);
	});

	test('Remove on a file that is only waiting drops it and leaves the field clean', () => {
		fakeRoutes();
		const field = build({}, fakeForm({ id: '', editing: false }));
		field.selectFile(pdf());

		field.root.querySelector('.s3-remove').click();

		expect(field.droplet.isComplete()).toBe(true);
		expect(field.isUnsaved()).toBe(false);
		expect(field.root.dataset.state).toBe('empty');
	});

	test('with uploads off the field is read-only but still shows its file', async () => {
		const calls = fakeRoutes();
		const field = build({ value: POINTER, uploads: 'off' });

		field.selectFile(pdf());
		field.root.querySelector('.s3-remove').click();
		await settled();

		expect(calls.filter(call => call.name !== 'stats')).toHaveLength(0);
		expect(field.getValue()).toEqual(POINTER);
		expect(field.root.querySelector('.s3-replace').hidden).toBe(true);
		expect(field.root.querySelector('.s3-remove').hidden).toBe(true);
		expect(field.root.querySelector('.s3-name').textContent).toBe('Brochure 2026.pdf');
	});

	test('a stored value that is not JSON is handed back as it is', () => {
		fakeRoutes();
		const container = fieldElement();
		container.querySelector('input[name="brochure"]').value = 'legacy text';
		const field = new S3FileField(container, { form: fakeForm() });

		expect(field.getValue()).toBe('legacy text');
		expect(field.root.dataset.state).toBe('empty');
	});
});

//-----------------------------------------------
// The s3depot field: many files on one property
//-----------------------------------------------

const item = (id, name) => ({
	id, key: `site/docs/d1/files/${id}-${name}`, name, size: 1536, mime: 'application/pdf', etag: '', uploadedAt: '2026-09-05T14:02:11+00:00', uploadedBy: 'joe',
});

/** The markup S3DepotField.php renders. */
function depotElement({ value = [], settings = null, uploads = null } = {}) {
	document.head.innerHTML = '<meta name="csrf-token" content="csrf-123"><base href="https://example.com/admin/">';
	document.body.innerHTML = '';

	const card = `
		<li class="s3-card" data-file-id="" draggable="true">
			<div class="s3-meta"><strong class="s3-name"></strong><span class="s3-details"><span class="s3-size"></span><span class="s3-date"></span><span class="s3-downloads"></span></span></div>
			<progress class="s3-progress" max="100" value="0" hidden></progress>
			<div class="s3-actions">
				<a class="s3-open">Open</a>
				<button type="button" class="s3-remove">Remove</button>
				<button type="button" class="s3-cancel" hidden>Cancel</button>
				<button type="button" class="s3-retry" hidden>Try again</button>
			</div>
		</li>`;

	const container = document.createElement('div');
	container.className = 'form-field s3depot-field';
	container.dataset.type = 's3depot';
	if (settings) container.dataset.settings = JSON.stringify(settings);
	container.innerHTML = `
		<label>Files</label>
		<div class="form-group">
			<input type="text" name="files" data-proxy="true">
			<div class="s3-field s3-depot" data-state="empty"${uploads ? ` data-uploads="${uploads}"` : ''}>
				<div class="s3-drop"><p>Drop files here, or <button type="button" class="s3-choose">Choose files</button></p><input type="file" class="s3-input" multiple hidden></div>
				<ul class="s3-list"></ul>
				<template class="s3-item-template">${card}</template>
				<p class="s3-error" role="alert" hidden></p>
			</div>
		</div>`;
	container.querySelector('input[name="files"]').value = value.length ? JSON.stringify(value) : '';
	document.body.append(container);
	return container;
}

function buildDepot(options = {}, form = fakeForm({ id: 'd1' })) {
	form.collection = 'docs';
	return new S3DepotField(depotElement(options), { form });
}

/** Routes for a depot: the server keeps the list and answers each commit with all of it. */
function depotRoutes(stored = []) {
	const server = [...stored];
	let next = 0;
	const calls = fakeRoutes({
		presign : (body, json) => json(200, { url: `https://bucket.example/${body.filename}`, headers: { 'Content-Type': body.mime }, token: body.filename }),
		commit  : (body, json) => {
			const added = item(`${String(next++).padStart(2, '0')}aaaaaa`, body.token);
			server.push(added);
			return json(200, { value: [...server], item: added });
		},
		stats   : (body, json) => json(200, { counts: { 'files/0a1b2c3d': 7 } }),
	});
	return { calls, server };
}

const names = field => field.getValue().map(file => file.name);
const cards = field => [...field.root.querySelectorAll('.s3-list > li')];

describe('S3DepotField', () => {
	test('an empty depot has an empty list as its value', () => {
		depotRoutes();
		const field = buildDepot();

		expect(field.getValue()).toEqual([]);
		expect(field.isDroplet()).toBe(true);
		expect(field.root.dataset.state).toBe('empty');
		expect(field.isUnsaved()).toBe(false);
	});

	test('stored files show as cards in stored order, each with its own Open link and count', async () => {
		depotRoutes();
		const field = buildDepot({ value: [item('1a1b2c3d', 'second.pdf'), item('0a1b2c3d', 'first.pdf')] });
		await settled();

		const open = new URL(cards(field)[1].querySelector('.s3-open').href);

		expect(names(field)).toEqual(['second.pdf', 'first.pdf']);
		expect(cards(field).map(card => card.dataset.fileId)).toEqual(['1a1b2c3d', '0a1b2c3d']);
		expect(cards(field)[0].querySelector('.s3-name').textContent).toBe('second.pdf');
		expect(cards(field).map(card => card.dataset.kind)).toEqual(['doc', 'doc']);
		expect(cards(field)[0].querySelector('.s3-size').textContent).toBe('1.5 KB');
		expect(Object.fromEntries(open.searchParams)).toEqual({ collection: 'docs', id: 'd1', property: 'files', file: '0a1b2c3d' });
		expect(cards(field)[1].querySelector('.s3-downloads').textContent).toBe('7 downloads');
		expect(field.root.dataset.state).toBe('filled');
	});

	test('several files upload three at a time', async () => {
		depotRoutes();
		FakeXHR.hold = true;
		const field = buildDepot();

		field.selectFiles([pdf('a.pdf'), pdf('b.pdf'), pdf('c.pdf'), pdf('d.pdf')]);
		await settled();

		expect(FakeXHR.requests.map(xhr => xhr.url)).toEqual(['https://bucket.example/a.pdf', 'https://bucket.example/b.pdf', 'https://bucket.example/c.pdf']);
		expect(cards(field)).toHaveLength(4);

		FakeXHR.requests[0].answer(200);
		await settled();

		expect(FakeXHR.requests).toHaveLength(4);
		expect(FakeXHR.requests[3].url).toBe('https://bucket.example/d.pdf');

		FakeXHR.requests.slice(1).forEach(xhr => xhr.answer(200));
		await settled();

		expect(names(field).sort()).toEqual(['a.pdf', 'b.pdf', 'c.pdf', 'd.pdf']);
		expect(field.droplet.isComplete()).toBe(true);
		expect(field.isUnsaved()).toBe(false);
	});

	test('each commit answers with the whole list, so files finishing together are all kept', async () => {
		depotRoutes([item('0a1b2c3d', 'old.pdf')]);
		const field = buildDepot({ value: [item('0a1b2c3d', 'old.pdf')] });

		field.selectFiles([pdf('a.pdf'), pdf('b.pdf')]);
		await settled();

		expect(names(field)).toEqual(['old.pdf', 'a.pdf', 'b.pdf']);
		expect(JSON.parse(field.input.value)).toHaveLength(3);
		expect(cards(field)).toHaveLength(3);
	});

	test('a full depot refuses more files before anything is sent', async () => {
		const { calls } = depotRoutes([item('0a1b2c3d', 'one.pdf')]);
		const field = buildDepot({ value: [item('0a1b2c3d', 'one.pdf')], settings: { rules: { count: { max: 2 } } } });

		field.selectFiles([pdf('a.pdf'), pdf('b.pdf')]);
		await settled();

		expect(names(field)).toEqual(['one.pdf', 'a.pdf']);
		expect(calls.filter(call => call.name === 'presign')).toHaveLength(1);
		expect(field.message.textContent).toBe('This field holds at most 2 files.');
	});

	test('a file that breaks a rule is skipped and the others still upload', async () => {
		depotRoutes();
		const field = buildDepot({ settings: { rules: { filetype: ['application/pdf'] } } });

		field.selectFiles([pdf('a.zip', 10, 'application/zip'), pdf('b.pdf')]);
		await settled();

		expect(names(field)).toEqual(['b.pdf']);
		expect(field.message.textContent).toBe('a.zip is not an accepted file type for this field.');
	});

	test('Remove drops one file and marks the field unsaved', async () => {
		depotRoutes();
		const field = buildDepot({ value: [item('0a1b2c3d', 'one.pdf'), item('1a1b2c3d', 'two.pdf')] });

		cards(field)[0].querySelector('.s3-remove').click();

		expect(names(field)).toEqual(['two.pdf']);
		expect(field.isUnsaved()).toBe(true);
		expect(cards(field)).toHaveLength(1);
	});

	test('a file removed but not yet saved does not come back when another upload commits', async () => {
		depotRoutes([item('0a1b2c3d', 'one.pdf')]);
		const field = buildDepot({ value: [item('0a1b2c3d', 'one.pdf')] });

		cards(field)[0].querySelector('.s3-remove').click();
		field.selectFiles([pdf('a.pdf')]);
		await settled();

		expect(names(field)).toEqual(['a.pdf']);
		// The removal still has to be saved; the upload itself is already on disk.
		expect(field.isUnsaved()).toBe(true);

		field.saved();
		expect(field.isUnsaved()).toBe(false);
	});

	test('moving a file changes the order of the value and marks the field unsaved', () => {
		depotRoutes();
		const field = buildDepot({ value: [item('0a1b2c3d', 'one.pdf'), item('1a1b2c3d', 'two.pdf'), item('2a1b2c3d', 'three.pdf')] });

		field.move('2a1b2c3d', '0a1b2c3d');

		expect(names(field)).toEqual(['three.pdf', 'one.pdf', 'two.pdf']);
		expect(cards(field).map(card => card.dataset.fileId)).toEqual(['2a1b2c3d', '0a1b2c3d', '1a1b2c3d']);
		expect(field.isUnsaved()).toBe(true);

		field.move('2a1b2c3d', null);
		expect(names(field)).toEqual(['one.pdf', 'two.pdf', 'three.pdf']);
	});

	test('on a new object the files wait for the save, then all upload and the form is told once', async () => {
		const { calls } = depotRoutes();
		const form  = fakeForm({ id: '', editing: false });
		const field = buildDepot({}, form);

		field.selectFiles([pdf('a.pdf'), pdf('b.pdf')]);
		await settled();

		expect(calls).toHaveLength(0);
		expect(field.isUnsaved()).toBe(true);
		expect(field.droplet.isComplete()).toBe(false);

		form.id = 'd9';
		const done = vi.fn();
		field.droplet.onQueueComplete(done);
		field.droplet.processQueue();
		await settled();

		expect(calls.filter(call => call.name === 'presign').map(call => call.body.id)).toEqual(['d9', 'd9']);
		expect(done).toHaveBeenCalledTimes(1);
		expect(names(field).sort()).toEqual(['a.pdf', 'b.pdf']);
		expect(field.isUnsaved()).toBe(false);
	});

	test('a failed file can be tried again or cancelled without touching the others', async () => {
		depotRoutes();
		FakeXHR.status = 0;
		const field = buildDepot();

		field.selectFiles([pdf('a.pdf')]);
		await settled();

		const failed = cards(field)[0];
		expect(failed.dataset.state).toBe('failed');
		expect(field.message.textContent).toContain('missing CORS rules on the bucket');
		expect(field.droplet.isComplete()).toBe(false);

		FakeXHR.status = 200;
		failed.querySelector('.s3-retry').click();
		await settled();

		expect(names(field)).toEqual(['a.pdf']);
		expect(field.droplet.isComplete()).toBe(true);
	});

	test('the form cannot save while files are uploading', async () => {
		depotRoutes();
		FakeXHR.hold = true;
		const field = buildDepot();

		field.selectFiles([pdf('a.pdf')]);
		await settled();

		expect(field.validate()).toBe(false);
		expect(field.message.textContent).toBe('Wait for the uploads to finish, then save.');

		FakeXHR.requests[0].answer(200);
		await settled();

		expect(field.validate()).toBe(true);
	});

	test('with uploads off nothing can be added or removed', async () => {
		const { calls } = depotRoutes();
		const field = buildDepot({ value: [item('0a1b2c3d', 'one.pdf')], uploads: 'off' });

		field.selectFiles([pdf('a.pdf')]);
		cards(field)[0].querySelector('.s3-remove').click();
		await settled();

		expect(calls.filter(call => call.name === 'presign')).toHaveLength(0);
		expect(names(field)).toEqual(['one.pdf']);
		expect(cards(field)[0].querySelector('.s3-remove').hidden).toBe(true);
	});
});
