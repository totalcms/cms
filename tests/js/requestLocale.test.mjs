import { localeHeaders, localeHeadersFor } from '../../javascript/request-locale.js';

// The page's language (<html lang>) rides along on every request so the
// server can answer in it. Same-origin only, like the CSRF header.

afterEach(() => {
	document.documentElement.removeAttribute('lang');
	delete window.TCMS_CONFIG;
});

describe('localeHeaders', () => {
	test('sends the page language', () => {
		document.documentElement.lang = 'es';
		expect(localeHeaders()).toEqual({ 'X-Locale': 'es' });
	});

	test('trims whitespace', () => {
		document.documentElement.setAttribute('lang', ' es-MX ');
		expect(localeHeaders()).toEqual({ 'X-Locale': 'es-MX' });
	});

	test('is empty when lang is empty', () => {
		document.documentElement.setAttribute('lang', '');
		expect(localeHeaders()).toEqual({});
	});

	test('is empty when there is no lang attribute', () => {
		expect(localeHeaders()).toEqual({});
	});
});

describe('localeHeaders rendered locale', () => {
	test('the locale the page was rendered in wins over lang', () => {
		document.documentElement.lang = 'en';
		window.TCMS_CONFIG = { locale: 'de_DE' };
		expect(localeHeaders()).toEqual({ 'X-Locale': 'de_DE' });
	});

	test('falls back to lang when the config has no locale', () => {
		document.documentElement.lang = 'es';
		window.TCMS_CONFIG = { confirmCountdown: 2 };
		expect(localeHeaders()).toEqual({ 'X-Locale': 'es' });
	});

	test('falls back to lang when the config locale is invalid', () => {
		document.documentElement.lang = 'es';
		window.TCMS_CONFIG = { locale: '中文' };
		expect(localeHeaders()).toEqual({ 'X-Locale': 'es' });
	});

	test('localeHeadersFor uses the rendered locale too', () => {
		window.TCMS_CONFIG = { locale: 'de_DE' };
		expect(localeHeadersFor('/api/x')).toEqual({ 'X-Locale': 'de_DE' });
	});
});

describe('localeHeaders validation', () => {
	test('drops a non-Latin1 value that would make Headers throw', () => {
		document.documentElement.setAttribute('lang', '中文');
		expect(localeHeaders()).toEqual({});
	});

	test('drops a value over 35 characters', () => {
		document.documentElement.setAttribute('lang', 'a'.repeat(36));
		expect(localeHeaders()).toEqual({});
	});

	test('keeps a region tag', () => {
		document.documentElement.setAttribute('lang', 'es-US');
		expect(localeHeaders()).toEqual({ 'X-Locale': 'es-US' });
	});
});

describe('localeHeadersFor', () => {
	test('sends the header to a same-origin path', () => {
		document.documentElement.lang = 'es';
		expect(localeHeadersFor('/api/action/webhook')).toEqual({ 'X-Locale': 'es' });
	});

	test('withholds the header from a cross-origin URL', () => {
		document.documentElement.lang = 'es';
		expect(localeHeadersFor('https://other.example/hook')).toEqual({});
	});

	test('withholds the header when the URL is unparseable', () => {
		document.documentElement.lang = 'es';
		expect(localeHeadersFor('http://')).toEqual({});
	});

	test('sends the header to an absolute same-origin URL', () => {
		document.documentElement.lang = 'es';
		expect(localeHeadersFor(`${window.location.origin}/api/upload`)).toEqual({ 'X-Locale': 'es' });
	});

	test('is empty for a same-origin URL when lang is empty', () => {
		document.documentElement.setAttribute('lang', '');
		expect(localeHeadersFor('/api/upload')).toEqual({});
	});
});
