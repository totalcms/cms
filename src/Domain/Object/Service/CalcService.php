<?php

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Rendering\Utilities\TemplatePlaceholder;

/**
 * Safe math expression evaluator for calc fields.
 * Evaluates expressions like "${price} * ${quantity} - ${discount}"
 * after field references have been replaced with numeric values.
 *
 * Supports deck aggregation: sum(${items.total}), avg(${items.price}), etc.
 * Deck references use dot notation: ${deckProperty.fieldName}
 *
 * Supports: +, -, *, /, %, parentheses, unary minus
 * Functions: round, floor, ceil, abs, min, max, sum, avg, count
 */
class CalcService
{
	/** @var array<array{type:string,value:string|float}> */
	private array $tokens = [];

	private int $pos = 0;

	/**
	 * Evaluate a calc expression using the provided object data.
	 *
	 * @param string $expression The calc expression (e.g., "${price} * ${quantity}")
	 * @param array<string,mixed> $objectData Object data for field replacement
	 */
	public function evaluate(string $expression, array $objectData): float
	{
		// First, expand deck references into comma-separated values
		// e.g., sum(${items.total}) → sum(10, 20, 30)
		$expr = (string)preg_replace_callback('/\$\{(\w+)\.(\w+)\}/', function (array $matches) use ($objectData): string {
			$deckProp  = $matches[1];
			$fieldName = $matches[2];
			$items     = $objectData[$deckProp] ?? [];

			if (!is_array($items)) {
				return '0';
			}

			$values = [];
			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$val      = $item[$fieldName] ?? 0;
				$values[] = is_numeric($val) ? (string)(float)$val : '0';
			}

			return $values !== [] ? implode(', ', $values) : '0';
		}, $expression);

		// Then replace simple field references with their numeric values.
		// The deck-dot-notation pass above has already consumed any ${prop.field} forms,
		// so anything remaining is a single-key reference.
		$expr = TemplatePlaceholder::render($expr, function (string $key) use ($objectData): string {
			$value = $objectData[$key] ?? 0;

			return is_numeric($value) ? (string)(float)$value : '0';
		});

		return $this->parse($expr);
	}

	private function parse(string $expr): float
	{
		$this->tokens = $this->tokenize($expr);
		$this->pos    = 0;

		$result = $this->parseExpression();

		if ($this->pos < count($this->tokens)) {
			throw new \RuntimeException('Unexpected token in calc expression');
		}

		return $result;
	}

	/**
	 * @return array<array{type:string,value:string|float}>
	 */
	private function tokenize(string $expr): array
	{
		$tokens = [];
		$i      = 0;
		$len    = strlen($expr);

		while ($i < $len) {
			$ch = $expr[$i];

			// Skip whitespace
			if (ctype_space($ch)) {
				$i++;
				continue;
			}

			// Number
			if (ctype_digit($ch) || $ch === '.') {
				$num = '';
				while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
					$num .= $expr[$i++];
				}
				$tokens[] = ['type' => 'number', 'value' => (float)$num];
				continue;
			}

			// Function name
			if (ctype_alpha($ch) || $ch === '_') {
				$name = '';
				while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
					$name .= $expr[$i++];
				}
				$tokens[] = ['type' => 'function', 'value' => $name];
				continue;
			}

			// Operators and parens
			if (str_contains('+-*/%(),', $ch)) {
				$tokens[] = ['type' => 'operator', 'value' => $ch];
				$i++;
				continue;
			}

			throw new \RuntimeException(sprintf('Unexpected character in calc expression: %s', $ch));
		}

		return $tokens;
	}

	/** @return array{type:string,value:string|float}|null */
	private function peek(): ?array
	{
		return $this->pos < count($this->tokens) ? $this->tokens[$this->pos] : null;
	}

	/** @return array{type:string,value:string|float} */
	private function consume(?string $expected = null): array
	{
		$token = $this->tokens[$this->pos++] ?? null;

		if ($expected !== null && ($token === null || $token['value'] !== $expected)) {
			throw new \RuntimeException(sprintf('Expected "%s" in calc expression', $expected));
		}

		if ($token === null) {
			throw new \RuntimeException('Unexpected end of calc expression');
		}

		return $token;
	}

	/** Handles + and - */
	private function parseExpression(): float
	{
		$left = $this->parseTerm();

		while (($t = $this->peek()) !== null && ($t['value'] === '+' || $t['value'] === '-')) {
			$op    = $this->consume()['value'];
			$right = $this->parseTerm();
			$left  = $op === '+' ? $left + $right : $left - $right;
		}

		return $left;
	}

	/** Handles *, /, % */
	private function parseTerm(): float
	{
		$left = $this->parseUnary();

		while (($t = $this->peek()) !== null && in_array($t['value'], ['*', '/', '%'], true)) {
			$op    = $this->consume()['value'];
			$right = $this->parseUnary();

			if ($op === '*') {
				$left *= $right;
			} elseif ($op === '/') {
				$left = $right != 0 ? $left / $right : 0;
			} else {
				$left = $right != 0 ? fmod($left, $right) : 0;
			}
		}

		return $left;
	}

	/** Handles unary minus */
	private function parseUnary(): float
	{
		if (($t = $this->peek()) !== null && $t['value'] === '-') {
			$this->consume();

			return -$this->parsePrimary();
		}

		return $this->parsePrimary();
	}

	/** Numbers, parenthesized expressions, function calls */
	private function parsePrimary(): float
	{
		$token = $this->peek();

		if ($token === null) {
			throw new \RuntimeException('Unexpected end of calc expression');
		}

		// Number
		if ($token['type'] === 'number') {
			$this->consume();

			return (float)$token['value'];
		}

		// Parenthesized expression
		if ($token['value'] === '(') {
			$this->consume('(');
			$result = $this->parseExpression();
			$this->consume(')');

			return $result;
		}

		// Function call
		if ($token['type'] === 'function') {
			$name = (string)$this->consume()['value'];
			$this->consume('(');
			$args = $this->parseArgList();
			$this->consume(')');

			return $this->callFunction($name, $args);
		}

		throw new \RuntimeException(sprintf('Unexpected token in calc expression: %s', (string)$token['value']));
	}

	/** @return array<float> */
	private function parseArgList(): array
	{
		$args = [];
		$t    = $this->peek();

		if ($t !== null && $t['value'] !== ')') {
			$args[] = $this->parseExpression();
			while (($t = $this->peek()) !== null && $t['value'] === ',') {
				$this->consume(',');
				$args[] = $this->parseExpression();
			}
		}

		return $args;
	}

	/**
	 * Clamp a calc result to min/max settings if defined.
	 *
	 * @param array<string,mixed> $settings
	 */
	public function clampValue(float $value, array $settings): float
	{
		$min = $settings['min'] ?? null;
		$max = $settings['max'] ?? null;

		if (is_numeric($min) && $value < (float)$min) {
			$value = (float)$min;
		}

		if (is_numeric($max) && $value > (float)$max) {
			$value = (float)$max;
		}

		return $value;
	}

	/** @param array<float> $args */
	private function callFunction(string $name, array $args): float
	{
		$val = $args[0] ?? 0.0;

		return match (strtolower($name)) {
			'round' => round($val, (int)($args[1] ?? 0)),
			'floor' => floor($val),
			'ceil'  => ceil($val),
			'abs'   => abs($val),
			'min'   => $args !== [] ? min($args) : 0.0,
			'max'   => $args !== [] ? max($args) : 0.0,
			'sum'   => array_sum($args),
			'avg'   => $args !== [] ? array_sum($args) / count($args) : 0.0,
			'count' => (float)count($args),
			default => throw new \RuntimeException(sprintf('Unknown function in calc expression: %s', $name)),
		};
	}
}
