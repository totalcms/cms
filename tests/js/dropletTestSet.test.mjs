import DropletTestSet from '../../javascript/totalform/droplet-testset.js';

//-----------------------------------------------
// DropletTestSet validates files against upload rules before they're sent to
// the server. The filetype/filename arrays are lists of alternatives — a file
// passes when it matches ANY entry, not all of them (a file has exactly one
// MIME type, so requiring all entries would reject every file whenever the
// list has more than one entry).
//-----------------------------------------------

describe('DropletTestSet filetype rules', () => {
	test('file matching one of several allowed types passes', () => {
		const testSet = new DropletTestSet({
			filetype: ['image/jpeg', 'image/png', 'image/webp'],
		});
		const pass = testSet.processRules({ type: 'image/jpeg', name: 'photo.jpg' }, 1);
		expect(pass).toBe(true);
		expect(testSet.errors).toHaveLength(0);
	});

	test('file matching none of the allowed types fails with a single error', () => {
		const testSet = new DropletTestSet({
			filetype: ['image/jpeg', 'image/png', 'image/webp'],
		});
		const pass = testSet.processRules({ type: 'application/pdf', name: 'doc.pdf' }, 1);
		expect(pass).toBe(false);
		expect(testSet.errors).toHaveLength(1);
	});

	test('single-entry filetype list still validates', () => {
		const testSet = new DropletTestSet({ filetype: ['image/jpeg'] });
		expect(testSet.processRules({ type: 'image/jpeg', name: 'a.jpg' }, 1)).toBe(true);
		expect(testSet.processRules({ type: 'image/png', name: 'a.png' }, 1)).toBe(false);
	});

	test('regex alternation entries keep working', () => {
		const testSet = new DropletTestSet({ filetype: ['image/(jpeg|png|webp)'] });
		expect(testSet.processRules({ type: 'image/webp', name: 'a.webp' }, 1)).toBe(true);
		expect(testSet.processRules({ type: 'image/gif', name: 'a.gif' }, 1)).toBe(false);
	});

	test('filename list passes when any pattern matches', () => {
		const testSet = new DropletTestSet({ filename: ['\\.jpg$', '\\.png$'] });
		expect(testSet.processRules({ type: 'image/jpeg', name: 'photo.jpg' }, 1)).toBe(true);
		expect(testSet.processRules({ type: 'image/gif', name: 'photo.gif' }, 1)).toBe(false);
	});
});
