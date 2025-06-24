<?php

namespace TotalCMS\Domain\Twig;

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
 */
final class TwigEngine
{
	private TwigEnvironment $twig;
	private ?TwigCacheManager $cacheManager = null;
	
	/** @var array<string,array<string,mixed>> */
	private static array $renderStats = [];
	
	/** @var bool Enable performance monitoring (disabled by default) */
	private static bool $monitoringEnabled = false;

	public function __construct(Config $config, TotalCMSTwigExtension $extension)
	{
		$internalTemplates = TemplateRepository::RESERVED_TEMPLATE_DIR;
		$customTemplates   = $config->datadir . '/' . TemplateRepository::CUSTOM_TEMPLATE_DIR;
		$cacheDir          = $config->cachedir === 'false' ? false : $config->cachedir;
		$debug             = $cacheDir === false ? true : false;                        // enable debug is no cache dir

		// Initialize cache manager if caching is enabled
		if ($cacheDir !== false) {
			$this->cacheManager = new TwigCacheManager($config);
		}

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
			'optimizations'    => -1,        // Enable all optimizations
			// Note: strict_variables disabled - would break existing templates that rely on null coalescing
		]);

		$this->twig->addExtension($extension);
		$this->twig->addExtension(new StringExtension());
		// $this->twig->addExtension(new IntlExtension());
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

		// !BUG: this is not working: https://github.com/twigphp/Twig/issues/4113
		// $this->twig->getRuntime(EscaperRuntime::class)->setSafeClasses([
		// 	TotalForm::class           => ['html'],
		// 	TotalCMSTwigAdapter::class => ['html'],
		// ]);
	}

	/** @param array<mixed> $data */
	public function render(string $templateName, array $data = []): string
	{
		// Only track performance if monitoring is enabled
		$startTime = self::$monitoringEnabled ? microtime(true) : 0;
		$startMemory = self::$monitoringEnabled ? memory_get_usage(true) : 0;
		
		try {
			$string = $this->twig->render($templateName, $data);
			
			// Record performance stats only if enabled
			if (self::$monitoringEnabled) {
				$this->recordStats($templateName, $startTime, $startMemory, true);
			}
			
		} catch (\Exception $e) {
			if (self::$monitoringEnabled) {
				$this->recordStats($templateName, $startTime, $startMemory, false);
			}
			
			$string = sprintf(
				'<p class="cms-twig-error render-error"><strong>Error rendering template</strong>: %s - %s</p><pre class="cms-twig-traceback">%s</pre>',
				$templateName,
				$e->getMessage(),
				$e->getPrevious(),
			);
		}

		return $string;
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
	 * Record performance statistics for template rendering.
	 * Only called when monitoring is enabled.
	 */
	private function recordStats(string $templateName, float $startTime, int $startMemory, bool $success): void
	{
		$renderTime = microtime(true) - $startTime;
		$memoryUsed = memory_get_usage(true) - $startMemory;
		
		// Initialize stats array if not exists (faster than isset check)
		if (!array_key_exists($templateName, self::$renderStats)) {
			self::$renderStats[$templateName] = [
				'count' => 0,
				'total_time' => 0.0,
				'max_time' => 0.0,
				'total_memory' => 0,
				'max_memory' => 0,
				'errors' => 0,
			];
		}
		
		// Use reference for performance
		$stats = &self::$renderStats[$templateName];
		$stats['count']++;
		$stats['total_time'] += $renderTime;
		$stats['total_memory'] += $memoryUsed;
		
		// Only update max values if current is larger (avoid unnecessary max() calls)
		if ($renderTime > $stats['max_time']) {
			$stats['max_time'] = $renderTime;
		}
		if ($memoryUsed > $stats['max_memory']) {
			$stats['max_memory'] = $memoryUsed;
		}
		
		if (!$success) {
			$stats['errors']++;
		}
	}
	
	/**
	 * Enable performance monitoring.
	 */
	public static function enableMonitoring(): void
	{
		self::$monitoringEnabled = true;
	}
	
	/**
	 * Disable performance monitoring.
	 */
	public static function disableMonitoring(): void
	{
		self::$monitoringEnabled = false;
	}
	
	/**
	 * Check if monitoring is enabled.
	 */
	public static function isMonitoringEnabled(): bool
	{
		return self::$monitoringEnabled;
	}
	
	/**
	 * Get rendering performance statistics.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function getRenderStats(): array
	{
		return self::$renderStats;
	}
	
	/**
	 * Clear rendering statistics.
	 */
	public static function clearRenderStats(): void
	{
		self::$renderStats = [];
	}
	
	/**
	 * Get summary statistics for all templates.
	 *
	 * @return array<string,mixed>
	 */
	public static function getStatsSummary(): array
	{
		$totalTime = 0.0;
		$totalMemory = 0;
		$totalCount = 0;
		$totalErrors = 0;
		$slowestTemplate = '';
		$slowestTime = 0.0;
		
		foreach (self::$renderStats as $template => $stats) {
			$totalTime += $stats['total_time'];
			$totalMemory += $stats['total_memory'];
			$totalCount += $stats['count'];
			$totalErrors += $stats['errors'];
			
			if ($stats['max_time'] > $slowestTime) {
				$slowestTime = $stats['max_time'];
				$slowestTemplate = $template;
			}
		}
		
		return [
			'total_renders' => $totalCount,
			'total_time' => $totalTime,
			'total_memory' => $totalMemory,
			'total_errors' => $totalErrors,
			'avg_time' => $totalCount > 0 ? $totalTime / $totalCount : 0,
			'avg_memory' => $totalCount > 0 ? $totalMemory / $totalCount : 0,
			'slowest_template' => $slowestTemplate,
			'slowest_time' => $slowestTime,
			'templates_count' => count(self::$renderStats),
		];
	}
	
	/**
	 * Get cache manager instance.
	 */
	public function getCacheManager(): ?TwigCacheManager
	{
		return $this->cacheManager;
	}
	
	/**
	 * Clear all Twig caches including OPcache.
	 */
	public function clearAllCaches(): bool
	{
		if ($this->cacheManager === null) {
			return true; // No caching enabled
		}
		
		return $this->cacheManager->clearAllCaches();
	}
	
	/**
	 * Get comprehensive cache statistics.
	 *
	 * @return array<string,mixed>
	 */
	public function getCacheStats(): array
	{
		if ($this->cacheManager === null) {
			return ['caching_enabled' => false];
		}
		
		return $this->cacheManager->getCacheStats();
	}
	
	/**
	 * Get optimal cache configuration recommendations.
	 *
	 * @return array<string,mixed>
	 */
	public function getCacheRecommendations(): array
	{
		if ($this->cacheManager === null) {
			return [
				'caching_enabled' => false,
				'recommendations' => ['❌ Twig caching is disabled - enable for better performance']
			];
		}
		
		return $this->cacheManager->getOptimalCacheConfig();
	}
}
