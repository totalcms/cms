<?php

namespace TotalCMS\Domain\Admin\Form\Builder;

use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\Form\FormServices;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateFactory;

/**
 * Total Form Builder for Templates.
 */
class TemplateForm extends TotalForm
{
	public TemplateData $templateData;

	// TODO: Refactor to only use services that it needs. May need to refactor TotalForm first.

	public function __construct(
		FormServices $services,
		FormOptions $options,
		protected TemplateRepository $templateRepository,
		public string $path = '',
	) {
		// A new template lands in the builder; nothing pre-fills the form.
		if ($options->newActions === []) {
			$options = $options->with(newActions: [['action' => 'redirect-object', 'link' => 'builder/']]);
		}

		parent::__construct($services, $options->with(data: []));
	}

	protected function init(): void
	{
		parent::init();

		$this->route = '/templates';

		// Editing existing template
		if ($this->path !== '') {
			[$folder, $templateId] = TemplatePath::parse($this->path);

			$this->route  = '/templates/' . $this->path;
			$this->method = 'PUT';
			$this->id     = $this->path; // Use full path as ID

			// Fetch the template
			$this->templateData = $this->templateRepository->fetchTemplate($templateId, $folder);
		}

		// Duplicate Template
		if ($this->path === '' && $this->data !== []) {
			$this->templateData = TemplateFactory::generateTemplate(
				$this->data['id'] ?? '',
				$this->data['template'] ?? ''
			);
		}

		$this->formType   = 'template';
		$this->schema     = 'template';
		$this->schemaData = $this->services->schemaFetcher->fetchSchema($this->schema);
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	protected function buildFieldOptions(string $name, array $options = []): array
	{
		// Get the schema settings for a property
		$defaults = $this->schemaData->properties[$name] ?? [];
		$defaults = TotalForm::filterFieldProperties($defaults);

		$options = array_merge($defaults, $options);

		// Set the name of the field
		$options['name'] = $name;

		// Setup communication between the field and the form
		$options['form'] = $this;

		// Set values from template data
		if ($name === 'category') {
			// Extract category from path when editing
			if ($this->path !== '') {
				$parts            = explode('/', $this->path);
				$options['value'] = $parts[0];
			}

			return $options;
		}

		if (isset($this->templateData)) {
			if ($name === 'id') {
				if ($this->path !== '') {
					// Strip the category prefix (e.g., "templates/test" → "test")
					$parts            = explode('/', $this->path, 2);
					$options['value'] = $parts[1] ?? $this->path;
				} else {
					$options['value'] = $this->templateData->id;
				}
			} elseif ($name === 'template') {
				$options['value'] = $this->templateData->contents;
			} elseif (in_array($name, ['designerEnabled', 'designerToken'], true)
				&& $this->templateData->designer instanceof DesignerMetadata
			) {
				$options['value'] = $this->templateData->designer->$name;
			}
		}

		return $options;
	}
}
