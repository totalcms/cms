<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Price property data — a NumberData that tolerates a FORMATTED currency string
 * on input (e.g. "$100,000.00", "100.000,00 €", "¥100,000") and normalizes it to
 * a float. The admin JS submits a clean number on the happy path; this is the
 * server-side guarantee for raw API posts, imports, JS-disabled browsers, and
 * pasted values. Locale-free: separator roles are inferred from the string.
 */
class PriceData extends NumberData
{
	public function __construct(string|int|float $price = 0, array $settings = [])
	{
		parent::__construct($this->normalize($price), $settings);
	}

	/** Coerce a possibly-formatted price into a numeric string floatval() accepts. */
	private function normalize(string|int|float $value): string
	{
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}

		// Keep only digits, separators, and a sign.
		$s = preg_replace('/[^0-9,.\-]/', '', $value) ?? '';
		if ($s === '' || $s === '-') {
			return '0';
		}

		$hasComma = str_contains($s, ',');
		$hasDot   = str_contains($s, '.');

		if ($hasComma && $hasDot) {
			// Both present → the RIGHTMOST separator is the decimal point.
			$lastComma = strrpos($s, ',');
			$lastDot   = strrpos($s, '.');
			$decimal   = $lastComma > $lastDot ? ',' : '.';
			$thousands = $decimal === ',' ? '.' : ',';
			$s         = str_replace($thousands, '', $s);
			$s         = str_replace($decimal, '.', $s);
		} elseif ($hasComma) {
			$s = $this->resolveSingleSeparator($s, ',');
		} elseif ($hasDot) {
			$s = $this->resolveSingleSeparator($s, '.');
		}

		return $s;
	}

	/**
	 * A single separator type is ambiguous (thousands vs decimal). Resolve it:
	 *  - appears more than once → thousands grouping (strip all)
	 *  - appears once with exactly 3 trailing digits → thousands ("100,000")
	 *  - otherwise → decimal point ("100,50" / "100.5")
	 */
	private function resolveSingleSeparator(string $s, string $sep): string
	{
		if (substr_count($s, $sep) > 1) {
			return str_replace($sep, '', $s);
		}

		$pos   = strpos($s, $sep);
		$after = $pos !== false ? strlen($s) - $pos - 1 : 0;
		if ($after === 3) {
			return str_replace($sep, '', $s);
		}

		return str_replace($sep, '.', $s);
	}
}
