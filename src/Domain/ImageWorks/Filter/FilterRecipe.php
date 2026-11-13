<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Intervention\Image\Interfaces\ImageInterface;
use TotalCMS\Domain\ImageWorks\Exception\InvalidFilterException;

/**
 * A declarative filter: an ordered list of validated steps, defined in
 * config or by an extension.
 *
 * Definition shape: `['label' => 'Ember', 'group' => 'look', 'steps' => [['sepia', 0.25], ...]]`
 * where each step is `[op, ...args]`. See FilterStep::OPS.
 */
final readonly class FilterRecipe implements ImageFilter
{
	public const GROUPS         = ['look', 'duotone', 'effect'];
	public const MAX_STEPS      = 12;
	public const BLEND_MODES    = ['normal', 'multiply', 'screen', 'overlay', 'soft-light'];
	public const LEAK_POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'top', 'bottom', 'left', 'right'];

	/**
	 * @param list<FilterStep> $steps
	 */
	private function __construct(
		private string $label,
		private string $group,
		private array $steps,
	) {
	}

	/**
	 * @param array<string,mixed> $definition
	 *
	 * @throws InvalidFilterException
	 */
	public static function fromDefinition(string $name, array $definition): self
	{
		$label = $definition['label'] ?? '';
		$label = is_string($label) && $label !== '' ? $label : $name;

		$group = $definition['group'] ?? 'look';
		if (!is_string($group) || !in_array($group, self::GROUPS, true)) {
			throw InvalidFilterException::forFilter($name, 'group must be one of ' . implode(', ', self::GROUPS));
		}

		$rawSteps = $definition['steps'] ?? null;
		if (!is_array($rawSteps) || $rawSteps === []) {
			throw InvalidFilterException::forFilter($name, 'steps must be a non-empty list');
		}
		if (count($rawSteps) > self::MAX_STEPS) {
			throw InvalidFilterException::forFilter($name, sprintf('at most %d steps', self::MAX_STEPS));
		}

		$steps = [];
		foreach (array_values($rawSteps) as $raw) {
			$steps[] = self::parseStep($name, $raw);
		}

		return new self($label, $group, $steps);
	}

	public function apply(ImageInterface $image, FilterContext $context): ImageInterface
	{
		return RecipeRenderers::for($image)->apply($image, $this->steps, $context);
	}

	public function label(): string
	{
		return $this->label;
	}

	public function group(): string
	{
		return $this->group;
	}

	/**
	 * @return list<FilterStep>
	 */
	public function steps(): array
	{
		return $this->steps;
	}

	/** True when any step is one of $ops. */
	public function hasStep(string ...$ops): bool
	{
		foreach ($this->steps as $step) {
			if (in_array($step->op, $ops, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First 8 hex characters of a hash of the steps. Part of the cache key,
	 * so editing a recipe retires files rendered by the old one.
	 */
	public function version(): string
	{
		$data = array_map(static fn (FilterStep $s): array => ['op' => $s->op, 'args' => $s->args], $this->steps);

		return substr(sha1((string)json_encode($data)), 0, 8);
	}

	private static function parseStep(string $name, mixed $raw): FilterStep
	{
		if (!is_array($raw) || !array_is_list($raw) || !isset($raw[0]) || !is_string($raw[0])) {
			throw InvalidFilterException::forFilter($name, 'each step must be a list starting with the op name');
		}

		$op   = $raw[0];
		$args = array_slice($raw, 1);

		if (!in_array($op, FilterStep::OPS, true)) {
			throw InvalidFilterException::forFilter($name, sprintf('unknown step "%s"', $op));
		}

		return new FilterStep($op, match ($op) {
			'brightness', 'contrast', 'saturate'  => self::numbers($name, $op, $args, 1, 0, 10),
			'grayscale', 'sepia', 'fade', 'grain' => self::numbers($name, $op, $args, 1, 0, 1),
			'hue-rotate'                          => self::numbers($name, $op, $args, 1, -360, 360),
			'overlay'                             => self::overlayArgs($name, $args),
			'vignette'                            => self::vignetteArgs($name, $args),
			'leak'                                => self::leakArgs($name, $args),
			default                               => self::gradientArgs($name, $args),
		});
	}

	/**
	 * @param list<mixed> $args
	 *
	 * @return list<float>
	 */
	private static function numbers(string $name, string $op, array $args, int $count, float $min, float $max): array
	{
		if (count($args) !== $count) {
			throw InvalidFilterException::forFilter($name, sprintf('%s takes %d argument(s)', $op, $count));
		}

		return array_map(static fn (mixed $v): float => self::number($name, $op, $v, $min, $max), $args);
	}

	private static function number(string $name, string $op, mixed $value, float $min, float $max): float
	{
		if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) {
			throw InvalidFilterException::forFilter($name, sprintf('%s needs a number from %s to %s', $op, $min, $max));
		}

		return (float)$value;
	}

	private static function hex(string $name, string $op, mixed $value): string
	{
		if (is_string($value) && preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $m) === 1) {
			$hex = strtolower($m[1]);
			if (strlen($hex) === 3) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			return $hex;
		}

		throw InvalidFilterException::forFilter($name, sprintf('%s needs a 3 or 6 digit hex color', $op));
	}

	/**
	 * @param list<mixed> $args
	 *
	 * @return list<float|string>
	 */
	private static function overlayArgs(string $name, array $args): array
	{
		if (count($args) !== 3) {
			throw InvalidFilterException::forFilter($name, 'overlay takes color, mode and opacity');
		}
		if (!is_string($args[1]) || !in_array($args[1], self::BLEND_MODES, true)) {
			throw InvalidFilterException::forFilter($name, 'overlay mode must be one of ' . implode(', ', self::BLEND_MODES));
		}

		return [self::hex($name, 'overlay', $args[0]), $args[1], self::number($name, 'overlay', $args[2], 0, 1)];
	}

	/**
	 * @param list<mixed> $args
	 *
	 * @return list<float|string>
	 */
	private static function vignetteArgs(string $name, array $args): array
	{
		if (count($args) < 1 || count($args) > 2) {
			throw InvalidFilterException::forFilter($name, 'vignette takes strength and an optional color');
		}

		return [
			self::number($name, 'vignette', $args[0], 0, 1),
			array_key_exists(1, $args) ? self::hex($name, 'vignette', $args[1]) : '000000',
		];
	}

	/**
	 * @param list<mixed> $args
	 *
	 * @return list<float|string>
	 */
	private static function leakArgs(string $name, array $args): array
	{
		if (count($args) !== 3) {
			throw InvalidFilterException::forFilter($name, 'leak takes color, position and amount');
		}
		if (!is_string($args[1]) || !in_array($args[1], self::LEAK_POSITIONS, true)) {
			throw InvalidFilterException::forFilter($name, 'leak position must be one of ' . implode(', ', self::LEAK_POSITIONS));
		}

		return [self::hex($name, 'leak', $args[0]), $args[1], self::number($name, 'leak', $args[2], 0, 1)];
	}

	/**
	 * @param list<mixed> $args
	 *
	 * @return list<string>
	 */
	private static function gradientArgs(string $name, array $args): array
	{
		if (count($args) < 2 || count($args) > 8) {
			throw InvalidFilterException::forFilter($name, 'gradient-map takes 2 to 8 colors');
		}

		return array_map(static fn (mixed $v): string => self::hex($name, 'gradient-map', $v), $args);
	}
}
