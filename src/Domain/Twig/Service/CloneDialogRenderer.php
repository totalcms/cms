<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * The admin "clone object" dialog behind `cms.render.cloneDialog()`: a form
 * that picks a target collection sharing the source's schema and a new id.
 *
 * RenderTwigAdapter is the Twig-facing entry point and delegates here; this
 * is the only render helper that needs the collection and schema services.
 */
readonly class CloneDialogRenderer
{
	public function __construct(
		private Config $config,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private CollectionLister $collectionLister,
	) {
	}

	/**
	 * Render the clone dialog for a collection.
	 */
	public function render(string $collection): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof \TotalCMS\Domain\Collection\Data\CollectionData) {
			return '';
		}

		$schemaData    = $this->schemaFetcher->fetchSchema($collectionData->schema);
		$labelSingular = $collectionData->labelSingular !== '' ? $collectionData->labelSingular : 'Object';

		$header = HTMLUtils::element('h3', 'Clone ' . $labelSingular);

		$collections = $this->collectionLister->listCollectionsWithSchema($schemaData->id);

		$options = '';
		foreach ($collections as $coll) {
			$attrs = ['value' => $coll->id];
			if ($coll->id === $collectionData->id) {
				$attrs['selected'] = '';
			}
			$options .= HTMLUtils::element('option', $coll->name, $attrs);
		}

		$label           = HTMLUtils::element('label', 'Clone into Collection', ['for' => 'clone-collection']);
		$input           = HTMLUtils::element('select', $options, ['id' => 'clone-collection', 'type' => 'text', 'name' => 'collection']);
		$collectionField = HTMLUtils::element('div', $label . $input);

		$label   = HTMLUtils::element('label', 'New ' . $labelSingular . ' ID', ['for' => 'clone-id']);
		$input   = HTMLUtils::inlineElement('input', [
			'id'             => 'clone-id',
			'type'           => 'text',
			'name'           => 'id',
			'autocapitalize' => 'off',
			'class'          => 'slugify-input',
		]);
		$idField = HTMLUtils::element('div', $label . $input);

		$form = new \TotalCMS\Domain\Admin\SimpleForm(
			api     : $this->config->api . '/api',
			route   : '',
			method  : 'POST',
			label   : 'Clone ' . $labelSingular,
			class   : 'clone-object-form',
			refresh : true,
		);
		$content = $form->build($header . $collectionField . $idField);

		return HTMLUtils::dialog($content, 'dialog-clone-object small');
	}
}
