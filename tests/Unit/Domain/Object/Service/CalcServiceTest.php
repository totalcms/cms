<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use TotalCMS\Domain\Object\Service\CalcService;

// --------------------------------------------------
// Basic Arithmetic
// --------------------------------------------------

it('evaluates simple addition', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} + ${b}', ['a' => 10, 'b' => 5]);
	expect($result)->toBe(15.0);
});

it('evaluates simple subtraction', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} - ${b}', ['a' => 10, 'b' => 3]);
	expect($result)->toBe(7.0);
});

it('evaluates simple multiplication', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${price} * ${quantity}', ['price' => 25, 'quantity' => 4]);
	expect($result)->toBe(100.0);
});

it('evaluates simple division', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} / ${b}', ['a' => 20, 'b' => 4]);
	expect($result)->toBe(5.0);
});

it('evaluates modulo', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} % ${b}', ['a' => 10, 'b' => 3]);
	expect($result)->toBe(1.0);
});

it('handles division by zero gracefully', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} / ${b}', ['a' => 10, 'b' => 0]);
	expect($result)->toBe(0.0);
});

it('handles modulo by zero gracefully', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} % ${b}', ['a' => 10, 'b' => 0]);
	expect($result)->toBe(0.0);
});

// --------------------------------------------------
// Order of Operations
// --------------------------------------------------

it('respects multiplication before addition', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} + ${b} * ${c}', ['a' => 2, 'b' => 3, 'c' => 4]);
	expect($result)->toBe(14.0);
});

it('respects parentheses', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('(${a} + ${b}) * ${c}', ['a' => 2, 'b' => 3, 'c' => 4]);
	expect($result)->toBe(20.0);
});

it('handles nested parentheses', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('((${a} + ${b}) * ${c}) - ${d}', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
	expect($result)->toBe(5.0);
});

// --------------------------------------------------
// Unary Minus
// --------------------------------------------------

it('handles unary minus', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('-${a}', ['a' => 5]);
	expect($result)->toBe(-5.0);
});

it('handles unary minus in expressions', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} + -${b}', ['a' => 10, 'b' => 3]);
	expect($result)->toBe(7.0);
});

// --------------------------------------------------
// Decimal / Float Values
// --------------------------------------------------

it('handles decimal values', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${price} * ${taxRate}', ['price' => 100, 'taxRate' => 0.08]);
	expect($result)->toBe(8.0);
});

it('handles string numeric values', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} + ${b}', ['a' => '10', 'b' => '20']);
	expect($result)->toBe(30.0);
});

// --------------------------------------------------
// Missing / Invalid Fields
// --------------------------------------------------

it('treats missing fields as zero', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} + ${missing}', ['a' => 10]);
	expect($result)->toBe(10.0);
});

it('treats non-numeric fields as zero', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} + ${name}', ['a' => 10, 'name' => 'hello']);
	expect($result)->toBe(10.0);
});

it('handles all fields missing', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${a} + ${b}', []);
	expect($result)->toBe(0.0);
});

// --------------------------------------------------
// Literal Numbers
// --------------------------------------------------

it('supports literal numbers in expressions', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${price} * 1.08', ['price' => 100]);
	expect($result)->toEqualWithDelta(108.0, 0.01);
});

it('supports expressions with only literals', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('10 + 5', []);
	expect($result)->toBe(15.0);
});

// --------------------------------------------------
// Built-in Math Functions
// --------------------------------------------------

it('evaluates round()', function (): void {
	$calc = new CalcService();
	expect($calc->evaluate('round(${a})', ['a' => 4.6]))->toBe(5.0);
	expect($calc->evaluate('round(${a})', ['a' => 4.4]))->toBe(4.0);
});

it('evaluates round() with precision', function (): void {
	$calc = new CalcService();
	expect($calc->evaluate('round(${a}, 2)', ['a' => 4.567]))->toBe(4.57);
	expect($calc->evaluate('round(${a}, 2)', ['a' => 4.564]))->toBe(4.56);
	expect($calc->evaluate('round(${a}, 1)', ['a' => 4.567]))->toBe(4.6);
	expect($calc->evaluate('round(${a}, 0)', ['a' => 4.567]))->toBe(5.0);
});

it('evaluates round() with precision in expression', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('round(${price} * ${quantity}, 2)', ['price' => 19.99, 'quantity' => 3]);
	expect($result)->toBe(59.97);
});

it('evaluates floor()', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('floor(${a})', ['a' => 4.9]);
	expect($result)->toBe(4.0);
});

it('evaluates ceil()', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('ceil(${a})', ['a' => 4.1]);
	expect($result)->toBe(5.0);
});

it('evaluates abs()', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('abs(${a})', ['a' => -7]);
	expect($result)->toBe(7.0);
});

it('evaluates min() with multiple args', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('min(${a}, ${b}, ${c})', ['a' => 10, 'b' => 3, 'c' => 7]);
	expect($result)->toBe(3.0);
});

it('evaluates max() with multiple args', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('max(${a}, ${b}, ${c})', ['a' => 10, 'b' => 3, 'c' => 7]);
	expect($result)->toBe(10.0);
});

// --------------------------------------------------
// Aggregate Functions
// --------------------------------------------------

it('evaluates sum()', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${a}, ${b}, ${c})', ['a' => 10, 'b' => 20, 'c' => 30]);
	expect($result)->toBe(60.0);
});

it('evaluates avg()', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('avg(${a}, ${b}, ${c})', ['a' => 10, 'b' => 20, 'c' => 30]);
	expect($result)->toBe(20.0);
});

it('evaluates count()', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('count(${a}, ${b}, ${c})', ['a' => 10, 'b' => 20, 'c' => 30]);
	expect($result)->toBe(3.0);
});

it('returns 0 for avg with no args', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('avg()', []);
	expect($result)->toBe(0.0);
});

// --------------------------------------------------
// Deck Item Aggregation
// --------------------------------------------------

it('evaluates sum of deck item field', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total})', [
		'items' => [
			'item1' => ['id' => 'item1', 'total' => 100],
			'item2' => ['id' => 'item2', 'total' => 200],
			'item3' => ['id' => 'item3', 'total' => 50],
		],
	]);
	expect($result)->toBe(350.0);
});

it('evaluates avg of deck item field', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('avg(${items.price})', [
		'items' => [
			'a' => ['id' => 'a', 'price' => 10],
			'b' => ['id' => 'b', 'price' => 20],
			'c' => ['id' => 'c', 'price' => 30],
		],
	]);
	expect($result)->toBe(20.0);
});

it('evaluates count of deck items', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('count(${items.id})', [
		'items' => [
			'a' => ['id' => 'a', 'price' => 10],
			'b' => ['id' => 'b', 'price' => 20],
		],
	]);
	expect($result)->toBe(2.0);
});

it('evaluates min of deck item field', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('min(${items.price})', [
		'items' => [
			'a' => ['id' => 'a', 'price' => 30],
			'b' => ['id' => 'b', 'price' => 10],
			'c' => ['id' => 'c', 'price' => 20],
		],
	]);
	expect($result)->toBe(10.0);
});

it('evaluates max of deck item field', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('max(${items.price})', [
		'items' => [
			'a' => ['id' => 'a', 'price' => 30],
			'b' => ['id' => 'b', 'price' => 10],
			'c' => ['id' => 'c', 'price' => 20],
		],
	]);
	expect($result)->toBe(30.0);
});

it('handles empty deck for aggregation', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total})', ['items' => []]);
	expect($result)->toBe(0.0);
});

it('handles missing deck property', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total})', []);
	expect($result)->toBe(0.0);
});

it('handles deck items with missing field', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total})', [
		'items' => [
			'a' => ['id' => 'a', 'total' => 100],
			'b' => ['id' => 'b'],
			'c' => ['id' => 'c', 'total' => 50],
		],
	]);
	expect($result)->toBe(150.0);
});

it('handles deck items with non-numeric field values', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total})', [
		'items' => [
			'a' => ['id' => 'a', 'total' => 100],
			'b' => ['id' => 'b', 'total' => 'invalid'],
			'c' => ['id' => 'c', 'total' => 50],
		],
	]);
	expect($result)->toBe(150.0);
});

it('handles non-array deck property', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total})', ['items' => 'not-an-array']);
	expect($result)->toBe(0.0);
});

// --------------------------------------------------
// Complex / Real-World Expressions
// --------------------------------------------------

it('evaluates invoice line total (price * quantity)', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('${price} * ${quantity}', ['price' => 29.99, 'quantity' => 3]);
	expect($result)->toEqualWithDelta(89.97, 0.01);
});

it('evaluates invoice grand total with tax', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${lineItems.total}) * 1.08', [
		'lineItems' => [
			'a' => ['id' => 'a', 'total' => 100],
			'b' => ['id' => 'b', 'total' => 200],
		],
	]);
	expect($result)->toEqualWithDelta(324.0, 0.01);
});

it('evaluates discount calculation', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total}) - ${discount}', [
		'discount' => 50,
		'items'    => [
			'a' => ['id' => 'a', 'total' => 100],
			'b' => ['id' => 'b', 'total' => 200],
		],
	]);
	expect($result)->toBe(250.0);
});

it('evaluates rounded total', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('round(${price} * ${quantity} * 1.0875)', [
		'price'    => 19.99,
		'quantity' => 7,
	]);
	expect($result)->toBe(round(19.99 * 7 * 1.0875));
});

it('evaluates function within arithmetic', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('round(${subtotal} * 0.08) + ${subtotal}', ['subtotal' => 100]);
	expect($result)->toBe(108.0);
});

it('mixes deck aggregation with scalar fields', function (): void {
	$calc   = new CalcService();
	$result = $calc->evaluate('sum(${items.total}) + ${shipping}', [
		'shipping' => 15,
		'items'    => [
			'a' => ['id' => 'a', 'total' => 50],
			'b' => ['id' => 'b', 'total' => 75],
		],
	]);
	expect($result)->toBe(140.0);
});

// --------------------------------------------------
// Error Handling
// --------------------------------------------------

it('throws on unknown function', function (): void {
	$calc = new CalcService();
	$calc->evaluate('unknown(${a})', ['a' => 5]);
})->throws(\RuntimeException::class, 'Unknown function in calc expression: unknown');

it('throws on invalid characters', function (): void {
	$calc = new CalcService();
	$calc->evaluate('${a} & ${b}', ['a' => 1, 'b' => 2]);
})->throws(\RuntimeException::class);

it('throws on unbalanced parentheses', function (): void {
	$calc = new CalcService();
	$calc->evaluate('(${a} + ${b}', ['a' => 1, 'b' => 2]);
})->throws(\RuntimeException::class);
