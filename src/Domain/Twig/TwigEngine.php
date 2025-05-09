<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Extension\DebugExtension;
use Twig\Extra\Html\HtmlExtension;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * Twig template processor.
 */
final class TwigEngine
{
	private TwigEnvironment $twig;

	public function __construct(Config $config, TotalCMSTwigExtension $extension)
	{
		$internalTemplates = TemplateRepository::RESERVED_TEMPLATE_DIR;
		$customTemplates   = $config->datadir . '/' . TemplateRepository::CUSTOM_TEMPLATE_DIR;
		$cacheDir          = $config->cachedir === 'false' ? false : $config->cachedir;
		$debug             = $cacheDir === false ? true : false;                        // enable debug is no cache dir

		if (!file_exists($internalTemplates)) {
			throw new \DomainException("Internal templates directory not found: $internalTemplates");
		}
		$paths = [$internalTemplates];
		if (file_exists($customTemplates)) {
			$paths[] = $customTemplates;
		}

		$loader     = new TwigFilesystemLoader($paths);
		$this->twig = new TwigEnvironment($loader, [
			'cache'      => $cacheDir,
			'debug'      => $debug,
			'autoescape' => false,
		]);

		$this->twig->addExtension($extension);
		$this->twig->addExtension(new StringExtension());
		// $this->twig->addExtension(new IntlExtension());
		$this->twig->addExtension(new HtmlExtension());
		$this->twig->addExtension(new MarkdownExtension());

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
		try {
			$string = $this->twig->render($templateName, $data);
		} catch (\Exception $e) {
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
}
