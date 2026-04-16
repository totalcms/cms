<?php

namespace TotalCMS\Domain\Twig\Service;

use Cake\Chronos\Chronos;
use Cake\I18n\I18n;
use Cake\I18n\RelativeTimeFormatter;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Twig\Designer\DesignerAwareLoader;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerPreprocessor;
use TotalCMS\Domain\Twig\Designer\TemplateDesignerSync;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Extension\CoreExtension;
use Twig\Extension\DebugExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\Html\HtmlExtension;
use Twig\Extra\Intl\IntlExtension;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;
use Twig\TwigFunction;

/**
 * Twig template processor.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
readonly class TwigEngine
{
	private TwigEnvironment $twig;

	public function __construct(
		Config $config,
		TotalCMSTwigExtension $extension,
		DevModeManager $devModeManager,
		EditionFeatureService $editionFeatures,
		private TemplateDesignerPreprocessor $designerPreprocessor,
		TemplateDesignerSync $designerSync,
	) {
		$internalTemplates = TemplateRepository::reservedTemplateDir();
		if (!file_exists($internalTemplates)) {
			throw new \DomainException("Internal templates directory not found: $internalTemplates");
		}
		$paths = [$internalTemplates];

		// Only add custom templates path if edition allows templates feature
		$customTemplates = $config->datadir . '/' . TemplateRepository::CUSTOM_TEMPLATE_DIR;
		if (file_exists($customTemplates) && $editionFeatures->can(EditionFeature::TEMPLATES)) {
			$paths[] = $customTemplates;
		}

		// Twig requires filesystem caching for compiled templates (can't use APCu/Redis)
		// Always use the cache directory for Twig, regardless of application cache backend settings
		$cacheDir = $config->cachedir;

		// Check if development mode is active (overrides cache settings)
		$devModeActive  = $devModeManager->isDevModeActive();
		$cacheEnabled   = !$config->debug && !$devModeActive && $cacheDir !== '';

		$filesystemLoader = new TwigFilesystemLoader($paths);
		$loader           = new DesignerAwareLoader($filesystemLoader, $this->designerPreprocessor);
		$this->twig       = new TwigEnvironment($loader, [
			'cache'            => $cacheEnabled ? $cacheDir : false,
			'debug'            => $config->debug || $devModeActive,
			'auto_reload'      => $config->debug || $devModeActive,   // Auto-reload in dev or devmode
			'autoescape'       => false,
			'optimizations'    => -1,               // Enable all optimizations
			'strict_variables' => false,
			'use_yield'        => false,
		]);

		$this->twig->getExtension(CoreExtension::class)->setTimezone($config->timezone);

		$this->twig->addExtension($extension);
		$this->twig->addExtension(new StringExtension());
		$this->twig->addExtension(new StringLoaderExtension());
		$this->twig->addExtension(new HtmlExtension());
		$this->twig->addExtension(new MarkdownExtension());

		// Configure locale for internationalization (requires intl extension)
		// Check both extension_loaded AND class existence to handle edge cases
		if (extension_loaded('intl') && class_exists('IntlDateFormatter')) {
			// Set PHP's default locale for IntlExtension and other intl functions
			\Locale::setDefault($config->locale);
			// Set CakePHP I18n locale for RelativeTimeFormatter translations
			// Note: I18n::setLocale() internally calls \Locale::setDefault()
			I18n::setLocale($config->locale);
			$this->twig->addExtension(new IntlExtension());
		}

		// Configure Chronos to use locale-aware RelativeTimeFormatter
		// This works without intl but translations will default to English
		Chronos::diffFormatter(new RelativeTimeFormatter());

		$this->twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
			public function load(string $class)
			{
				if (MarkdownRuntime::class === $class) {
					return new MarkdownRuntime(new ParsedownMarkdown());
				}

				return null;
			}
		});

		if ($config->debug) {
			$this->twig->addExtension(new DebugExtension());
		}

		// Register Template Designer sync function
		$this->twig->addFunction(new TwigFunction(
			'_tcms_designer_sync',
			fn (string $key): string => $designerSync->sync($key),
			['is_safe'               => ['html']],
		));
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
		// Run the designer preprocessor on string templates since createTemplate() bypasses the loader
		$template = $this->designerPreprocessor->preprocess($template, '__string_template__');

		$twig = $this->twig->createTemplate($template);

		try {
			return $twig->render($data);
		} catch (\Exception $e) {
			throw $e->getPrevious() ?? $e;
		}
	}

	/**
	 * Register Twig functions, filters, and globals from extensions.
	 *
	 * @param list<TwigFunction> $functions
	 * @param list<\Twig\TwigFilter>   $filters
	 * @param array<string,mixed>      $globals
	 */
	public function registerExtensionItems(array $functions, array $filters, array $globals): void
	{
		foreach ($functions as $fn) {
			$this->twig->addFunction($fn);
		}
		foreach ($filters as $filter) {
			$this->twig->addFilter($filter);
		}
		foreach ($globals as $name => $value) {
			$this->twig->addGlobal($name, $value);
		}
	}
}
