<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Typography\Data;

/**
 * Which typography rules run. Every rule is a key; the defaults are the set
 * that is correct in ordinary prose in any language. The three rules that
 * change markup (fractions, ordinals, wrap) are off unless asked for.
 */
final class TypographyOptions
{
	public const DASHES_EM = 'em';
	public const DASHES_EN = 'en';

	public readonly QuoteStyle $quoteStyle;

	public function __construct(
		/** A locale code for the quote style, or false to leave quotes alone. */
		public readonly string|false $quotes = 'en',
		/** 'em' (unspaced em dash, US), 'en' (spaced en dash, UK), or false. */
		public readonly string|false $dashes = self::DASHES_EM,
		public readonly bool $ellipsis = true,
		public readonly bool $math = true,
		public readonly bool $symbols = true,
		public readonly bool $primes = true,
		public readonly bool $nbsp = true,
		public readonly bool $widont = true,
		public readonly bool $fractions = false,
		public readonly bool $ordinals = false,
		public readonly bool $wrap = false,
	) {
		if ($dashes !== false && $dashes !== self::DASHES_EM && $dashes !== self::DASHES_EN) {
			throw new \InvalidArgumentException("typography: dashes must be 'em', 'en' or false, got '{$dashes}'");
		}

		$this->quoteStyle = QuoteStyle::forLocale($quotes === false ? 'en' : $quotes);
	}

	/**
	 * Build from the hash a template passes to the filter. Unknown keys are an
	 * error so a typo does not silently leave a rule at its default.
	 *
	 * @param array<string,mixed> $options
	 */
	public static function fromArray(array $options, string $defaultLocale): self
	{
		$known = ['quotes', 'dashes', 'ellipsis', 'math', 'symbols', 'primes', 'nbsp', 'widont', 'fractions', 'ordinals', 'wrap'];

		$unknown = array_diff(array_keys($options), $known);
		if ($unknown !== []) {
			throw new \InvalidArgumentException('typography: unknown option(s) ' . implode(', ', $unknown) . '; known: ' . implode(', ', $known));
		}

		$quotes = $options['quotes'] ?? $defaultLocale;
		if ($quotes === true) {
			$quotes = $defaultLocale;
		}
		if ($quotes !== false && !is_string($quotes)) {
			throw new \InvalidArgumentException('typography: quotes must be a locale code or false');
		}

		$dashes = $options['dashes'] ?? self::DASHES_EM;
		if ($dashes === true) {
			$dashes = self::DASHES_EM;
		}
		if ($dashes !== false && !is_string($dashes)) {
			throw new \InvalidArgumentException('typography: dashes must be a string or false');
		}

		$flag = static fn (string $key, bool $default): bool => isset($options[$key]) ? (bool)$options[$key] : $default;

		return new self(
			quotes: $quotes,
			dashes: $dashes,
			ellipsis: $flag('ellipsis', true),
			math: $flag('math', true),
			symbols: $flag('symbols', true),
			primes: $flag('primes', true),
			nbsp: $flag('nbsp', true),
			widont: $flag('widont', true),
			fractions: $flag('fractions', false),
			ordinals: $flag('ordinals', false),
			wrap: $flag('wrap', false),
		);
	}
}
