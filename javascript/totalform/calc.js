//-----------------------------------------------
// Calc Field Helper
// Provides math expression evaluation for number fields.
// Uses ${fieldname} syntax to reference sibling form fields.
// Uses ${deckProperty.fieldName} syntax to reference deck item fields
// for aggregate functions: sum(${items.total}), avg(${items.price}), etc.
//-----------------------------------------------
export default class Calc {

	constructor(field) {
		this.field = field;
		this.expression = field.settings.calc;
	}

	/**
	 * Parse the calc expression and return referenced field names.
	 * Returns { fields: ['price', 'qty'], deckRefs: [{ deck: 'items', field: 'total' }] }
	 */
	getReferences() {
		const fields = [];
		const deckRefs = [];

		(this.expression.match(/\${(.*?)}/g) || [])
			.map(v => v.slice(2, -1))
			.forEach(ref => {
				if (ref.includes('.')) {
					const [deck, field] = ref.split('.', 2);
					deckRefs.push({ deck, field });
				} else {
					fields.push(ref);
				}
			});

		return { fields, deckRefs };
	}

	/**
	 * Attach change listeners to referenced fields and deck containers.
	 */
	attachListeners(callback) {
		const { fields, deckRefs } = this.getReferences();
		const searchScope = this.field.isInDeck ? this.field.deckItem : this.field.form.form;

		// Listen to sibling field changes
		fields.forEach(name => {
			const input = searchScope.querySelector(`[name="${name}"]`);
			if (!input) return;
			input.addEventListener("change", () => callback());
			input.addEventListener("input", () => callback());
		});

		// Listen to deck container changes (item add/remove/edit)
		if (deckRefs.length > 0 && this.field.form) {
			const uniqueDecks = [...new Set(deckRefs.map(r => r.deck))];
			uniqueDecks.forEach(deckProp => {
				const deckContainer = searchScope.querySelector(
					`.form-field[data-type="deck"] [name="${deckProp}"],` +
					`.form-field[data-type="deckTable"] [name="${deckProp}"]`
				);
				const deckField = deckContainer?.closest('.form-field');
				if (!deckField) return;

				// field-change fires when any deck item changes, is added, or removed
				deckField.addEventListener("field-change", () => callback());
				deckField.addEventListener("subfield-change", () => callback());
			});
		}
	}

	/**
	 * Evaluate the calc expression using current form field values.
	 * Returns the computed number, or null if evaluation fails.
	 */
	evaluate() {
		const data = this.collectFormData();

		// First, expand deck references into comma-separated values
		// e.g., sum(${items.total}) → sum(10, 20, 30)
		let expr = this.expression.replace(/\${(\w+)\.(\w+)}/g, (match, deckProp, fieldName) => {
			const values = this.collectDeckFieldValues(deckProp, fieldName);
			return values.length > 0 ? values.join(', ') : '0';
		});

		// Then replace simple field references with their numeric values
		expr = expr.replace(/\${(.*?)}/g, (match, key) => {
			const val = parseFloat(data[key]);
			return isNaN(val) ? '0' : String(val);
		});

		try {
			let result = this.parse(expr);

			// Clamp to min/max settings if defined
			const min = this.field.settings.min;
			const max = this.field.settings.max;
			if (min !== undefined && min !== null && result < Number(min)) result = Number(min);
			if (max !== undefined && max !== null && result > Number(max)) result = Number(max);

			return result;
		} catch {
			return null;
		}
	}

	/**
	 * Collect values for a specific field from all items in a deck.
	 */
	collectDeckFieldValues(deckProp, fieldName) {
		const values = [];

		// If we're in a deck ourselves, we can't aggregate sibling deck items
		if (this.field.isInDeck) return values;
		if (!this.field.form) return values;

		const form = this.field.form.form;

		// Find the deck field container
		const deckInput = form.querySelector(
			`.form-field[data-type="deck"] [name="${deckProp}"],` +
			`.form-field[data-type="deckTable"] [name="${deckProp}"]`
		);
		const deckField = deckInput?.closest('.form-field');
		if (!deckField) return values;

		// Determine item selector based on deck type
		const isDeckTable = deckField.dataset.type === 'deckTable';
		const itemSelector = isDeckTable ? '.deck-table-row' : '.deck-item';
		const items = deckField.querySelectorAll(itemSelector);

		items.forEach(item => {
			// Find the field within this deck item
			const fieldInput = item.querySelector(`[name="${fieldName}"]`);
			if (fieldInput) {
				const val = parseFloat(fieldInput.value);
				if (!isNaN(val)) values.push(val);
			}
		});

		return values;
	}

	/**
	 * Collect current form data from the appropriate scope.
	 */
	collectFormData() {
		if (this.field.isInDeck) {
			const data = {};
			const fields = this.field.deckItem.querySelectorAll('input, textarea, select');
			fields.forEach(field => data[field.name] = field.value);
			return data;
		}

		let data = this.field.form.generateData();
		return Object.entries(data).reduce((acc, [key, value]) => {
			if (typeof value === 'string' || typeof value === 'number') {
				acc[key] = String(value);
			}
			return acc;
		}, {});
	}

	// --------------------------------------------------
	// Safe math expression parser (no eval)
	// Supports: +, -, *, /, %, parentheses, unary minus
	// Functions: round, floor, ceil, abs, min, max, sum, avg, count
	// --------------------------------------------------

	parse(expr) {
		this.tokens = this.tokenize(expr);
		this.pos = 0;
		const result = this.parseExpression();
		if (this.pos < this.tokens.length) {
			throw new Error('Unexpected token: ' + this.tokens[this.pos]);
		}
		return result;
	}

	tokenize(expr) {
		const tokens = [];
		let i = 0;
		while (i < expr.length) {
			const ch = expr[i];

			// Skip whitespace
			if (/\s/.test(ch)) { i++; continue; }

			// Number (integer or decimal)
			if (/[0-9.]/.test(ch)) {
				let num = '';
				while (i < expr.length && /[0-9.]/.test(expr[i])) {
					num += expr[i++];
				}
				tokens.push({ type: 'number', value: parseFloat(num) });
				continue;
			}

			// Function name or identifier
			if (/[a-zA-Z_]/.test(ch)) {
				let name = '';
				while (i < expr.length && /[a-zA-Z0-9_]/.test(expr[i])) {
					name += expr[i++];
				}
				tokens.push({ type: 'function', value: name });
				continue;
			}

			// Operators and parens
			if ('+-*/%(),'.includes(ch)) {
				tokens.push({ type: 'operator', value: ch });
				i++;
				continue;
			}

			throw new Error('Unexpected character: ' + ch);
		}
		return tokens;
	}

	peek() {
		return this.pos < this.tokens.length ? this.tokens[this.pos] : null;
	}

	consume(expected) {
		const token = this.tokens[this.pos++];
		if (expected && (!token || token.value !== expected)) {
			throw new Error(`Expected "${expected}" but got "${token?.value}"`);
		}
		return token;
	}

	// Expression: handles + and -
	parseExpression() {
		let left = this.parseTerm();
		while (this.peek() && (this.peek().value === '+' || this.peek().value === '-')) {
			const op = this.consume().value;
			const right = this.parseTerm();
			left = op === '+' ? left + right : left - right;
		}
		return left;
	}

	// Term: handles * / %
	parseTerm() {
		let left = this.parseUnary();
		while (this.peek() && (this.peek().value === '*' || this.peek().value === '/' || this.peek().value === '%')) {
			const op = this.consume().value;
			const right = this.parseUnary();
			if (op === '*') left = left * right;
			else if (op === '/') left = right !== 0 ? left / right : 0;
			else left = left % right;
		}
		return left;
	}

	// Unary: handles unary minus
	parseUnary() {
		if (this.peek() && this.peek().value === '-') {
			this.consume();
			return -this.parsePrimary();
		}
		return this.parsePrimary();
	}

	// Primary: numbers, parenthesized expressions, function calls
	parsePrimary() {
		const token = this.peek();
		if (!token) throw new Error('Unexpected end of expression');

		// Number
		if (token.type === 'number') {
			this.consume();
			return token.value;
		}

		// Parenthesized expression
		if (token.value === '(') {
			this.consume('(');
			const result = this.parseExpression();
			this.consume(')');
			return result;
		}

		// Function call
		if (token.type === 'function') {
			const name = this.consume().value;
			this.consume('(');
			const args = this.parseArgList();
			this.consume(')');
			return this.callFunction(name, args);
		}

		throw new Error('Unexpected token: ' + token.value);
	}

	parseArgList() {
		const args = [];
		if (this.peek() && this.peek().value !== ')') {
			args.push(this.parseExpression());
			while (this.peek() && this.peek().value === ',') {
				this.consume(',');
				args.push(this.parseExpression());
			}
		}
		return args;
	}

	callFunction(name, args) {
		switch (name.toLowerCase()) {
			case 'round': return Math.round(args[0] ?? 0);
			case 'floor': return Math.floor(args[0] ?? 0);
			case 'ceil':  return Math.ceil(args[0] ?? 0);
			case 'abs':   return Math.abs(args[0] ?? 0);
			case 'min':   return Math.min(...args);
			case 'max':   return Math.max(...args);
			case 'sum':   return args.reduce((a, b) => a + b, 0);
			case 'avg':   return args.length > 0 ? args.reduce((a, b) => a + b, 0) / args.length : 0;
			case 'count': return args.length;
			default: throw new Error('Unknown function: ' + name);
		}
	}
}
