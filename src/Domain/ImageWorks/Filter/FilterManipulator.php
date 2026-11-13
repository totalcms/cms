<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Manipulators\BaseManipulator;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Exception\FilterTooLargeException;

/**
 * Takes the place of Glide's Filter manipulator. Runs the filters `filt`
 * names, in order, at the strength `filtamt` sets. It sits where Glide's own
 * Filter does, after the resize and before the marks, so marks stay unfiltered.
 *
 * `filtv` is not read here: it only has to be a param so it is part of
 * Glide's cache key (see ImageFilterRegistry::chainVersion()).
 */
final class FilterManipulator extends BaseManipulator
{
	/** @param array<string> $palette */
	public function __construct(
		private readonly ImageFilterRegistry $filters,
		private readonly array $palette,
		private readonly int $maxPixels,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/** @return list<string> */
	public function getApiParams(): array
	{
		return ['filt', 'filtamt', 'duo', 'filtv'];
	}

	public function run(ImageInterface $image): ImageInterface
	{
		$names  = $this->getParam('filt');
		$amount = $this->amount();
		if (!is_string($names) || $names === '' || $amount === 0) {
			return $image;
		}

		$active = [];
		foreach (explode(',', $names) as $name) {
			$entry = $this->filters->get(trim($name));
			if ($entry instanceof RegisteredFilter) {
				$active[] = $entry;
			}
		}
		if ($active === []) {
			return $image;
		}

		$driver = $image->core()->native() instanceof \Imagick ? 'imagick' : 'gd';
		$this->guardSize($image, $driver, $active);

		$context = new FilterContext($driver, array_values($this->palette), $this->duo());
		$target  = $amount < 100 ? clone $image : $image;

		foreach ($active as $entry) {
			$target = $this->applyOne($entry, $target, $context);
		}

		return $amount < 100 ? StrengthBlend::apply($image, $target, $amount) : $target;
	}

	/**
	 * One filter, or the image as it was when the filter throws. Recipes work
	 * out everything that can fail before they touch a pixel; an extension's
	 * class gets a copy, kept only when apply() returns.
	 */
	private function applyOne(RegisteredFilter $entry, ImageInterface $image, FilterContext $context): ImageInterface
	{
		try {
			return $entry->filter->apply($entry->isExtensionClass() ? clone $image : $image, $context);
		} catch (FilterTooLargeException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger?->warning('ImageWorks filter skipped', [
				'filter' => $entry->name,
				'owner'  => $entry->owner,
				'error'  => $e->getMessage(),
			]);

			return $image;
		}
	}

	/**
	 * `filterMaxPixels` counts every frame: an animation is filtered frame by
	 * frame. Zero or less means no limit.
	 *
	 * @param list<RegisteredFilter> $active
	 */
	private function guardSize(ImageInterface $image, string $driver, array $active): void
	{
		$pixels = $image->width() * $image->height() * max(1, count($image));
		if ($this->maxPixels <= 0 || $pixels <= $this->maxPixels) {
			return;
		}

		foreach ($active as $entry) {
			if ($entry->isPixelLimitedOn($driver)) {
				throw FilterTooLargeException::forSize($image->width(), $image->height(), $this->maxPixels);
			}
		}
	}

	/** The strength as 0-100; a missing or bad value is full strength. */
	private function amount(): int
	{
		$amount = $this->getParam('filtamt');

		return is_numeric($amount) ? max(0, min(100, (int)round((float)$amount))) : 100;
	}

	/** @return array{string,string}|null */
	private function duo(): ?array
	{
		$duo = $this->getParam('duo');
		if (!is_string($duo)) {
			return null;
		}

		$parts = explode(',', $duo);

		return count($parts) === 2 ? [$parts[0], $parts[1]] : null;
	}
}
