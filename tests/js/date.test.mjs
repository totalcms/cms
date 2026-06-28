import DateField from '../../javascript/totalform/date.js';

// formatDate normalises an ISO value: trim to the minute, and for type=date drop the time.
const dateField = (type) => { const f = Object.create(DateField.prototype); f.type = type; return f; };

describe('DateField.formatDate', () => {
	test('datetime keeps the value to the minute', () => {
		expect(dateField('datetime').formatDate('2026-05-04T10:41:30.000Z')).toBe('2026-05-04T10:41');
	});
	test('date strips the time portion', () => {
		expect(dateField('date').formatDate('2026-05-04T10:41:30')).toBe('2026-05-04');
	});
	test('empty input returns empty', () => {
		expect(dateField('date').formatDate('')).toBe('');
	});
});
