<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Extension\DebugExtension;
use Twig\Extra\Html\HtmlExtension;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * Twig template processor.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class TwigEngine
{
	private TwigEnvironment $twig;
	private CacheManager $cacheManager;

	public function __construct(
		Config $config,
		TotalCMSTwigExtension $extension,
		CacheManager $cacheManager,
	) {
		$internalTemplates = TemplateRepository::RESERVED_TEMPLATE_DIR;
		$customTemplates   = $config->datadir . '/' . TemplateRepository::CUSTOM_TEMPLATE_DIR;

		$cache             = $config->cache ?? [];
		$filesystemConfig  = $cache['filesystem'] ?? [];
		$cacheDirectory    = $filesystemConfig['directory'] ?? '';
		$cacheDir          = $filesystemConfig['enabled'] ? $cacheDirectory : false;
		$debug             = ($cacheDir === false);

		$this->cacheManager = $cacheManager;

		if (!file_exists($internalTemplates)) {
			throw new \DomainException("Internal templates directory not found: $internalTemplates");
		}
		$paths = [$internalTemplates];
		if (file_exists($customTemplates)) {
			$paths[] = $customTemplates;
		}

		$loader     = new TwigFilesystemLoader($paths);
		$this->twig = new TwigEnvironment($loader, [
			'cache'            => $cacheDir,
			'debug'            => $debug,
			'autoescape'       => false,
			'optimizations'    => -1,          // Enable all optimizations
			'strict_variables' => false,
			'auto_reload'      => $debug,      // Auto-reload in dev, disabled in production
			'use_yield'        => false,
		]);

		$this->twig->addExtension($extension);
		$this->twig->addExtension(new StringExtension());
		$this->twig->addExtension(new HtmlExtension());
		$this->twig->addExtension(new MarkdownExtension());

		$this->twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
			public function load(string $class)
			{
				if (MarkdownRuntime::class === $class) {
					return new MarkdownRuntime(new ParsedownMarkdown());
				}

				return null;
			}
		});

		if ($debug) {
			$this->twig->addExtension(new DebugExtension());
		}
	}

	/** @param array<mixed> $data */
	public function render(string $templateName, array $data = []): string
	{
		try {
			return $this->twig->render($templateName, $data);
		} catch (\Exception $e) {
			return sprintf(
				'<p class="cms-twig-error render-error"><strong>Error rendering template</strong>: %s - %s</p><pre class="cms-twig-traceback">%s</pre>',
				$templateName,
				$e->getMessage(),
				$e->getPrevious(),
			);
		}
	}

	/** @param array<mixed> $data */
	public function renderString(string $template, array $data = []): string
	{
		$twig = $this->twig->createTemplate($template);

		try {
			return $twig->render($data);
		} catch (\Exception $e) {
			throw $e->getPrevious() ?? $e;
		}
	}

	/**
	 * Get cache manager instance.
	 */
	public function getCacheManager(): CacheManager
	{
		return $this->cacheManager;
	}

	/**
	 * Clear all caches including OPcache.
	 */
	public function clearAllCaches(): bool
	{
		return $this->cacheManager->clearAllCaches();
	}

	/**
	 * Get comprehensive cache statistics.
	 *
	 * @return array<string,mixed>
	 */
	public function getCacheStats(): array
	{
		return $this->cacheManager->getCacheStats();
	}

	/**
	 * Get optimal cache configuration recommendations.
	 *
	 * @return array<string,mixed>
	 */
	public function getCacheRecommendations(): array
	{
		return $this->cacheManager->getOptimalCacheConfig();
	}
}