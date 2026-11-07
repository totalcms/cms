//-----------------------------------------------
// The REAL Dropzone, with only XMLHttpRequest faked. A `params` option replaces
// Dropzone's default, which is what adds dzuuid, dzchunkindex and the rest to
// each chunk; without them the server stores every chunk as its own file. The
// FakeDropzone in depotDrop.test.mjs cannot catch that, so this file does not
// mock Dropzone at all.
//-----------------------------------------------
import DepotDrop from '../../javascript/totalform/depot-drop.js';

const sent = [];

class FakeXHR {
	constructor() {
		this.readyState = 1;
		this.status = 200;
		this.responseType = '';
		this.upload = {};
	}
	open() {}
	setRequestHeader() {}
	getResponseHeader() { return 'application/json'; }
	getAllResponseHeaders() { return 'content-type: application/json'; }
	abort() {}
	send(formData) {
		sent.push(formData);
		this.readyState = 4;
		this.responseText = '{"status":"chunk received"}';
		setTimeout(() => this.onload?.({}), 0);
	}
}

function build() {
	const el = document.createElement('div');
	el.dataset.type = 'depotDrop';
	el.dataset.settings = JSON.stringify({ comments: false });
	el.innerHTML = `
		<div class="form-group">
			<input type="text" name="files"/>
			<div class="depot-drop-bot"><input type="text" name="extra_reference_code"/><input type="hidden" name="form_ts" value="TOKEN"/></div>
			<div class="depot-drop-zone dz-clickable"><ul class="total-preview"></ul></div>
			<template class="file-template"><li class="depot-drop-card"><div class="dz-preview"><div class="file-icon"></div><p class="filename"></p></div></li></template>
		</div>`;
	document.body.appendChild(el);
	const form = { collection: 'docs', id: 'inbox', api: { headers: {}, buildApiQuery: (a) => `/api${a}` }, form: document.createElement('form'), runEditActions: vi.fn() };
	return new DepotDrop(el, { form });
}

test('every chunk carries Dropzone\'s dz* fields and the bot fields', async () => {
	const realXHR = globalThis.XMLHttpRequest;
	globalThis.XMLHttpRequest = FakeXHR;
	try {
		const field = build();
		const big = new File([new Uint8Array(12 * 1024 * 1024)], 'big.pdf', { type: 'application/pdf' });
		field.dropzone.addFile(big);

		await vi.waitFor(() => expect(sent.length).toBe(3), { timeout: 4000 });

		const uuids = new Set();
		sent.forEach((fd, i) => {
			expect(fd.get('dzchunkindex')).toBe(String(i));
			expect(fd.get('dztotalchunkcount')).toBe('3');
			expect(fd.get('dztotalfilesize')).toBe(String(12 * 1024 * 1024));
			expect(fd.get('dzchunksize')).toBe(String(5 * 1024 * 1024));
			expect(fd.get('form_ts')).toBe('TOKEN');
			expect(fd.get('extra_reference_code')).toBe('');
			uuids.add(fd.get('dzuuid'));
		});
		expect(uuids.size).toBe(1);
		expect([...uuids][0]).toBeTruthy();
	} finally {
		globalThis.XMLHttpRequest = realXHR;
	}
});
