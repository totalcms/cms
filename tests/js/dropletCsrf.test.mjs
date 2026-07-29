import { vi } from 'vitest';

//-----------------------------------------------
// Dropzone builds its own XHR, so it never picks up the CSRF header that
// TotalCMSApi puts on fetch(). Since the auth middlewares started enforcing
// CSRF on session-authenticated /api writes, an upload without the header is a
// 403 — every image, file, gallery and depot drop in the admin. Pin the header
// onto the Dropzone options for all three droplet variants.
//-----------------------------------------------

const captured = vi.hoisted(() => []);

vi.mock('@deltablot/dropzone', () => {
	class FakeDropzone {
		constructor(element, options) {
			captured.push(options);
			this.element = element;
			this.options = options;
		}
		on() {}
	}
	FakeDropzone.createElement = () => document.createElement('div');
	return { default: FakeDropzone };
});

const Droplet      = (await import('../../javascript/totalform/droplet.js')).default;
const DepotDroplet = (await import('../../javascript/totalform/droplet-depot.js')).default;
const DepotDrop    = (await import('../../javascript/totalform/depot-drop.js')).default;

const TOKEN = 'a1b2c3d4';

function metaTag(value) {
	const tag = document.createElement('meta');
	tag.setAttribute('name', 'csrf-token');
	tag.setAttribute('content', value);
	document.head.appendChild(tag);
}

// Markup each droplet variant expects to find inside its field container.
function container({ template = 'template' } = {}) {
	const el = document.createElement('div');
	el.innerHTML = `
		<div class="total-preview"></div>
		<${template === 'template' ? 'template' : 'template class="file-template"'}><div class="dz-preview"></div></template>
	`;
	document.body.appendChild(el);
	return el;
}

function lastOptions() {
	return captured[captured.length - 1];
}

beforeEach(() => {
	captured.length = 0;
	document.head.innerHTML = '';
	document.body.innerHTML = '';
});

describe('Droplet (image / file / gallery uploads)', () => {
	test('sends the CSRF header with the upload', () => {
		metaTag(TOKEN);
		const el = container();
		new Droplet({ container: el }, { apiUrl: '/api/collections/image/demo/image' });

		expect(lastOptions().headers['X-CSRF-Token']).toBe(TOKEN);
	});

	test('keeps caller-supplied request headers alongside the token', () => {
		metaTag(TOKEN);
		const el = container();
		new Droplet({ container: el }, { requestHeaders: { 'X-Custom': 'yes' } });

		expect(lastOptions().headers).toEqual({ 'X-CSRF-Token': TOKEN, 'X-Custom': 'yes' });
	});

	test('omits the header when the page carries no token', () => {
		const el = container();
		new Droplet({ container: el }, {});

		expect(lastOptions().headers).toEqual({});
	});
});

describe('DepotDroplet', () => {
	test('sends the CSRF header with the upload', () => {
		metaTag(TOKEN);
		const el = container({ template: 'file-template' });
		new DepotDroplet({ container: el }, { apiUrl: '/api/depot/demo' });

		expect(lastOptions().headers['X-CSRF-Token']).toBe(TOKEN);
	});
});

describe('DepotDropField', () => {
	test('sends the CSRF header with the upload', () => {
		metaTag(TOKEN);
		const el = container({ template: 'file-template' });

		const field = Object.create(DepotDrop.prototype);
		field.container = el;
		field.settings = {};
		field.property = 'files';
		field.preview = el.querySelector('.total-preview');
		field.previewTemplate = el.querySelector('template').innerHTML;
		field.form = { api: {} };
		field.apiUploadUrl = () => '/api/depot/demo';
		field.setupDropzone();

		expect(lastOptions().headers['X-CSRF-Token']).toBe(TOKEN);
	});
});
