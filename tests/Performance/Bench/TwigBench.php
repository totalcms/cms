<?php

declare(strict_types=1);

namespace Tests\Performance\Bench;

use PhpBench\Attributes as Bench;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * A listing written the common way: cms.collection.objects() returns the
 * whole index, and the template shows 20 of it.
 */
#[Bench\ParamProviders('provideSizes')]
final class TwigBench extends BenchCase
{
	private const TEMPLATE = "{% for post in cms.collection.objects('posts')|slice(0, 20) %}<h2>{{ post.title }}</h2>{{ post.summary|raw }}{% endfor %}";

	public function clearAll(): void
	{
		$this->coldCaches();
	}

	public function primeAndReset(): void
	{
		$this->render();
		$this->freshRequest();
	}

	/** After a Total CMS cache clear: compiled Twig and the index both rebuilt (OPcache stays warm). */
	#[Bench\BeforeMethods(['boot', 'clearAll'])]
	public function benchObjectsListingCold(): void
	{
		$this->render();
	}

	#[Bench\BeforeMethods(['boot', 'primeAndReset'])]
	public function benchObjectsListingWarm(): void
	{
		$this->render();
	}

	private function render(): void
	{
		$this->container->get(TwigEngine::class)->renderString(self::TEMPLATE);
	}
}
