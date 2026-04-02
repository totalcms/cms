<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Renders object/property selects and a download button for deck export.
 * JS updates the button href as selections change.
 */
readonly class ExportDeckForm implements \Stringable
{
	/**
	 * @param array<array{value:string,label:string}> $objects        Object options for select
	 * @param array<array{value:string,label:string}> $deckProperties Deck property options for select
	 */
	public function __construct(
		private string $api,
		private string $collection,
		private array $objects = [],
		private array $deckProperties = [],
		private string $output = 'csv',
	) {
	}

	public function build(): string
	{
		$objectOptions = HTMLUtils::element('option', 'Select an object...', ['value' => '']);
		foreach ($this->objects as $object) {
			$objectOptions .= HTMLUtils::element('option', htmlspecialchars($object['label']), ['value' => $object['value']]);
		}

		$propOptions = HTMLUtils::element('option', 'Select a deck property...', ['value' => '']);
		foreach ($this->deckProperties as $prop) {
			$propOptions .= HTMLUtils::element('option', htmlspecialchars($prop['label']), ['value' => $prop['value']]);
		}

		$objectLabel  = HTMLUtils::element('label', 'Object', ['for' => 'deck-export-object']);
		$objectSelect = HTMLUtils::element('select', $objectOptions, ['id' => 'deck-export-object']);

		$propLabel  = HTMLUtils::element('label', 'Deck Property', ['for' => 'deck-export-property']);
		$propSelect = HTMLUtils::element('select', $propOptions, ['id' => 'deck-export-property']);

		$fields = HTMLUtils::element('div', $objectLabel . $objectSelect)
			. HTMLUtils::element('div', $propLabel . $propSelect);

		$buttonId    = 'deck-export-btn';
		$buttonLabel = 'Export Deck ' . strtoupper($this->output);
		$button      = HTMLUtils::element('a', $buttonLabel, [
			'id'       => $buttonId,
			'class'    => 'dash-button',
			'download' => '',
			'style'    => 'pointer-events:none;opacity:0.5',
		]);
		$buttonWrapper = HTMLUtils::element('div', $button, ['class' => 'form-inline-fields']);

		$form = HTMLUtils::element('div', $fields . $buttonWrapper, [
			'class' => 'simple-form totalform import-form',
		]);

		$apiUrl     = htmlspecialchars($this->api);
		$collection = htmlspecialchars($this->collection);
		$suffix     = $this->output === 'json' ? '' : '/' . htmlspecialchars($this->output);

		$script = <<<JS
		<script>
		(function() {
			var obj  = document.getElementById('deck-export-object');
			var prop = document.getElementById('deck-export-property');
			var btn  = document.getElementById('{$buttonId}');
			function update() {
				if (obj.value && prop.value) {
					btn.href = '{$apiUrl}/export/collections/{$collection}/' + encodeURIComponent(obj.value) + '/' + encodeURIComponent(prop.value) + '/deck{$suffix}';
					btn.style.pointerEvents = '';
					btn.style.opacity = '';
				} else {
					btn.removeAttribute('href');
					btn.style.pointerEvents = 'none';
					btn.style.opacity = '0.5';
				}
			}
			obj.addEventListener('change', update);
			prop.addEventListener('change', update);
		})();
		</script>
		JS;

		return $form . $script;
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
