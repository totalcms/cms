<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;
use Twig\Environment as TwigEnvironment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\Runtime\EscaperRuntime;

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
		if ($debug) {
			$this->twig->addExtension(new DebugExtension());
		}

		// !BUG: this is not working: https://github.com/twigphp/Twig/issues/4113
		$this->twig->getRuntime(EscaperRuntime::class)->setSafeClasses([
			TotalForm::class           => ['html'],
			TotalCMSTwigAdapter::class => ['html'],
		]);
	}

	/** @param array<mixed> $data */
	public function render(string $templateName, array $data = []): string
	{
		try {
			$string = $this->twig->render($templateName, $data);
		} catch (\Exception $e) {
			// throw new \DomainException("Error rendering template: $templateName - " . $e->getMessage());
			$string = "<!-- Error rendering template: $templateName - " . $e->getMessage() . '-->';
		}

		return $string;
	}

	/** @param array<mixed> $data */
	public function renderString(string $template, array $data = []): string
	{
		$twig = $this->twig->createTemplate($template);

		return $twig->render($data);
	}
}
