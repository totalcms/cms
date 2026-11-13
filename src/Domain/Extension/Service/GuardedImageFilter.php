<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Intervention\Image\Interfaces\ImageInterface;
use Throwable;
use TotalCMS\Domain\ImageWorks\Filter\FilterContext;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;

/**
 * An extension's ImageFilter class with every call run under ExtensionGuard,
 * as its Twig callables and event listeners are: a throw is logged with the
 * extension's id and counted toward auto-quarantine.
 *
 * label() falls back to the filter name, so one broken extension cannot take
 * down the admin ImageWorks tool. version() falls back to "err": the URL still
 * renders, keyed apart from the filter's real versions. apply() rethrows the
 * extension's own error after the guard has counted it, so FilterManipulator
 * logs the skip and keeps the image as it was.
 */
final readonly class GuardedImageFilter implements ImageFilter
{
	public function __construct(
		private ImageFilter $filter,
		private ExtensionGuard $guard,
		private string $extensionId,
		private string $name,
	) {
	}

	public function label(): string
	{
		return $this->guard->run($this->extensionId, "imagefilter:{$this->name}:label", fn (): string => $this->filter->label(), $this->name);
	}

	public function version(): string
	{
		return $this->guard->run($this->extensionId, "imagefilter:{$this->name}:version", fn (): string => $this->filter->version(), 'err');
	}

	public function apply(ImageInterface $image, FilterContext $context): ImageInterface
	{
		// Set by reference inside the guarded call when the extension throws.
		/** @var Throwable|null $failure */
		$failure = null;
		$result  = $this->guard->run(
			$this->extensionId,
			"imagefilter:{$this->name}",
			function () use ($image, $context, &$failure): ImageInterface {
				try {
					return $this->filter->apply($image, $context);
				} catch (Throwable $e) {
					$failure = $e;

					throw $e;
				}
			},
			null,
		);

		if ($failure instanceof Throwable) {
			throw $failure;
		}

		return $result ?? $image;
	}
}
