import TotalCMS from '../../javascript/totalcms.js';

//-----------------------------------------------
// A form's data-api is the REST base (`{base}/api`). Routes resolve against
// it — except /admin/... routes, which live at the site base, outside /api:
// a register form posts to /admin/register/{collection}, admin-routed
// totalforms post to /admin/.... buildApiQuery drops the trailing /api for
// those, so data-api can always be the REST base and the form's other calls
// (the id "taken?" check, the mailer action) still reach /api.
//-----------------------------------------------

const origin = window.location.origin;
const client = url => new TotalCMS({ url });

describe('TotalCMS.buildApiQuery', () => {
	test('prefixes ordinary routes with the REST base', () => {
		expect(client('/api').buildApiQuery('/collections/users/joe')).toBe(`${origin}/api/collections/users/joe`);
	});

	test('resolves /admin routes against the site base, root install', () => {
		expect(client('/api').buildApiQuery('/admin/register/users')).toBe(`${origin}/admin/register/users`);
	});

	test('resolves /admin routes against the site base, subfolder install', () => {
		expect(client('/tcms/api').buildApiQuery('/admin/register/users')).toBe(`${origin}/tcms/admin/register/users`);
	});

	test('leaves a base without /api alone (older markup that already dropped it)', () => {
		expect(client('').buildApiQuery('/admin/register/users')).toBe(`${origin}/admin/register/users`);
		expect(client('/tcms').buildApiQuery('/admin/settings/smtp')).toBe(`${origin}/tcms/admin/settings/smtp`);
	});

	test('only a path segment named admin counts', () => {
		expect(client('/api').buildApiQuery('/administrators')).toBe(`${origin}/api/administrators`);
	});

	test('keeps query params', () => {
		expect(client('/api').buildApiQuery('/admin/x', { a: 1 })).toBe(`${origin}/admin/x?a=1`);
	});
});
