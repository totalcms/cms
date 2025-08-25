<?php

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Extension\DebugExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\Html\HtmlExtension;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

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
	) {
		$internalTemplates = TemplateRepository::RESERVED_TEMPLATE_DIR;
		if (!file_exists($internalTemplates)) {
			throw new \DomainException("Internal templates directory not found: $internalTemplates");
		}
		$paths = [$internalTemplates];

		$customTemplates  = $config->datadir . '/' . TemplateRepository::CUSTOM_TEMPLATE_DIR;
		if (file_exists($customTemplates)) {
			$paths[] = $customTemplates;
		}

		$filesystemConfig   = $config->cache['filesystem'];
		$cacheDir           = $filesystemConfig['enabled'] ? $filesystemConfig['directory'] : false;

		// Check if development mode is active (overrides cache settings)
		$devModeActive  = $devModeManager->isDevModeActive();
		$cacheEnabled   = !$config->debug && !$devModeActive && $cacheDir !== false;

		$loader     = new TwigFilesystemLoader($paths);
		$this->twig = new TwigEnvironment($loader, [
			'cache'            => $cacheEnabled ? $cacheDir : false,
			'debug'            => $config->debug || $devModeActive,
			'auto_reload'      => $config->debug || $devModeActive,   // Auto-reload in dev or devmode
			'autoescape'       => false,
			'optimizations'    => -1,               // Enable all optimizations
			'strict_variables' => false,
			'use_yield'        => false,
		]);

		$this->twig->addExtension($extension);
		$this->twig->addExtension(new StringExtension());
		$this->twig->addExtension(new StringLoaderExtension());
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

		if ($config->debug) {
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
}
