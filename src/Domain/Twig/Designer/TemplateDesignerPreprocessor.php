<?php

namespace TotalCMS\Domain\Twig\Designer;

/**
 * Preprocesses Twig template source to extract {% templatedesigner %} blocks.
 *
 * Runs BEFORE Twig compilation to capture raw template content that would
 * otherwise be compiled (e.g., {{ object.title }} would be evaluated).
 */
class TemplateDesignerPreprocessor
{
	private const PATTERN = '/\{%\s*templatedesigner\s+for\s+[\'"]([^\'"]+)[\'"]\s+on\s+[\'"]([^\'"]+)[\'"](?:\s+token\s+[\'"]([^\'"]*)[\'"])?\s*%\}(.*?)\{%\s*endtemplatedesigner\s*%\}/s';

	public function __construct(private readonly TemplateDesignerRegistry $registry)
	{
	}

	/**
	 * Process template source, extracting designer blocks and replacing them
	 * with sync function calls.
	 */
	public function preprocess(string $source, string $templateName): string
	{
		$index    = 0;
		$registry = $this->registry;

		return (string)preg_replace_callback(
			self::PATTERN,
			static function (array $matches) use ($registry, $templateName, &$index): string {
				$templatePath = $matches[1];
				$url          = $matches[2];
				$token        = $matches[3];
				$content      = $matches[4];

				$key = '_designer_' . str_replace(['/', '.'], '_', $templateName) . '_' . $index;
				$index++;

				$registry->register($key, [
					'template' => $templatePath,
					'url'      => $url,
					'token'    => $token,
					'content'  => $content,
				]);

				return "{{ _tcms_designer_sync('" . $key . "') }}";
			},
			$source,
		);
	}
}
