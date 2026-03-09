<?php

namespace TotalCMS\Domain\Twig\Designer;

use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Custom Twig loader that wraps the filesystem loader to run the
 * Template Designer preprocessor before Twig compilation.
 */
class DesignerAwareLoader implements LoaderInterface
{
	public function __construct(
		private readonly LoaderInterface $inner,
		private readonly TemplateDesignerPreprocessor $preprocessor,
	) {
	}

	public function getSourceContext(string $name): Source
	{
		$source = $this->inner->getSourceContext($name);

		// Run preprocessor to extract designer blocks
		$code = $this->preprocessor->preprocess($source->getCode(), $name);

		return new Source($code, $source->getName(), $source->getPath());
	}

	public function getCacheKey(string $name): string
	{
		return $this->inner->getCacheKey($name);
	}

	public function isFresh(string $name, int $time): bool
	{
		$fresh = $this->inner->isFresh($name, $time);

		// When cache is warm but registry needs populating (dev mode with auto_reload),
		// run the preprocessor to ensure the registry has block data for sync
		if ($fresh) {
			$source = $this->inner->getSourceContext($name);
			$this->preprocessor->preprocess($source->getCode(), $name);
		}

		return $fresh;
	}

	public function exists(string $name): bool
	{
		return $this->inner->exists($name);
	}
}
