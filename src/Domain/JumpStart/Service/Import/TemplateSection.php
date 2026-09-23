<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service\Import;

use TotalCMS\Domain\JumpStart\Data\ImportReport;
use TotalCMS\Domain\Template\Service\TemplateSaver;

/** The `templates` section of a JumpStart definition. */
readonly class TemplateSection
{
	public function __construct(private TemplateSaver $templateSaver)
	{
	}

	/** @param array<int,array<string,string>> $templates */
	public function import(array $templates, ImportRun $run): void
	{
		foreach ($templates as $template) {
			$templateId = $template['id'] ?? 'unknown';
			try {
				$this->templateSaver->saveTemplate($templateId, $template['template'] ?? '');
				$run->report->result(ImportReport::TEMPLATES, sprintf('Template %s: created', $templateId));
			} catch (\Exception $e) {
				$run->report->error(sprintf('Template %s: %s', $templateId, $e->getMessage()));
			}
		}
	}
}
