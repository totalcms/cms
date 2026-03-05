<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Report Export Form Builder.
 *
 * Builds a form that lets users select a collection, pick fields,
 * and download a CSV or JSON report.
 */
readonly class ReportForm implements \Stringable
{
	public function __construct(
		private string $api,
		private CollectionLister $collectionLister,
		private string $collection = '',
		private string $include = '',
		private string $exclude = '',
	) {
	}

	public function build(): string
	{
		$html  = '<div class="report-form">';
		$html .= $this->buildCollectionSelector();
		$html .= $this->buildFilterFields();
		$html .= $this->buildFieldsContainer();
		$html .= $this->buildActions();
		$html .= '</div>';

		return $html . $this->buildScript();
	}

	private function buildCollectionSelector(): string
	{
		if ($this->collection !== '') {
			return HTMLUtils::inlineElement('input', [
				'type'  => 'hidden',
				'name'  => 'collection',
				'value' => $this->collection,
				'class' => 'report-collection-input',
			]);
		}

		$collections = $this->collectionLister->listAllCollections();
		usort($collections, fn (CollectionData $a, CollectionData $b): int => strcasecmp(
			$a->name !== '' ? $a->name : $a->id,
			$b->name !== '' ? $b->name : $b->id,
		));

		$options = HTMLUtils::element('option', 'Select a collection...', ['value' => '']);
		foreach ($collections as $collection) {
			$label    = $collection->name !== '' ? $collection->name : $collection->id;
			$options .= HTMLUtils::element('option', htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), [
				'value' => $collection->id,
			]);
		}

		$select = HTMLUtils::element('select', $options, [
			'name'  => 'collection',
			'class' => 'report-collection-input',
		]);

		return HTMLUtils::element('div', HTMLUtils::element('label', 'Collection') . $select, [
			'class' => 'report-collection-selector',
		]);
	}

	private function buildFilterFields(): string
	{
		$includeField = HTMLUtils::inlineElement('input', [
			'type'        => 'text',
			'name'        => 'include',
			'value'       => $this->include,
			'placeholder' => 'e.g. published:true,category:news',
			'class'       => 'report-filter-input',
		]);
		$includeDiv = HTMLUtils::element(
			'div',
			HTMLUtils::element('label', 'Include Filter') . $includeField,
			['class' => 'report-filter-field']
		);

		$excludeField = HTMLUtils::inlineElement('input', [
			'type'        => 'text',
			'name'        => 'exclude',
			'value'       => $this->exclude,
			'placeholder' => 'e.g. draft:true',
			'class'       => 'report-filter-input',
		]);
		$excludeDiv = HTMLUtils::element(
			'div',
			HTMLUtils::element('label', 'Exclude Filter') . $excludeField,
			['class' => 'report-filter-field']
		);

		return HTMLUtils::element('div', $includeDiv . $excludeDiv, [
			'class' => 'report-filters',
		]);
	}

	private function buildFieldsContainer(): string
	{
		$content = '';

		// If collection is pre-set, we can load fields server-side via HTMX on page load
		if ($this->collection !== '') {
			$content = HTMLUtils::element('div', '', [
				'id'          => 'report-fields',
				'class'       => 'report-fields',
				'hx-get'      => $this->api . '/report/collections/' . $this->collection . '/fields',
				'hx-trigger'  => 'load',
				'hx-swap'     => 'innerHTML',
			]);
		} else {
			$content = HTMLUtils::element('div', '<p class="report-fields-placeholder">Select a collection to see available fields.</p>', [
				'id'    => 'report-fields',
				'class' => 'report-fields',
			]);
		}

		return $content;
	}

	private function buildActions(): string
	{
		$csvBtn = HTMLUtils::element('button', 'Download CSV', [
			'type'        => 'button',
			'class'       => 'dash-button accent report-download-btn',
			'data-format' => 'csv',
		]);

		$jsonBtn = HTMLUtils::element('button', 'Download JSON', [
			'type'        => 'button',
			'class'       => 'dash-button report-download-btn',
			'data-format' => 'json',
		]);

		return HTMLUtils::element('div', $csvBtn . $jsonBtn, [
			'class' => 'report-actions',
		]);
	}

	private function buildScript(): string
	{
		$api = htmlspecialchars($this->api, ENT_QUOTES, 'UTF-8');

		return <<<SCRIPT
		<script>
		(function() {
			const api = '{$api}';
			const container = document.querySelector('.report-form');
			if (!container) return;

			const collectionInput = container.querySelector('.report-collection-input');
			const fieldsContainer = container.querySelector('#report-fields');

			// Load fields when collection changes (for select dropdown)
			if (collectionInput && collectionInput.tagName === 'SELECT') {
				collectionInput.addEventListener('change', function() {
					const collection = this.value;
					if (!collection) {
						fieldsContainer.innerHTML = '<p class="report-fields-placeholder">Select a collection to see available fields.</p>';
						return;
					}
					fetch(api + '/report/collections/' + encodeURIComponent(collection) + '/fields')
						.then(r => r.text())
						.then(html => { fieldsContainer.innerHTML = html; })
						.catch(() => { fieldsContainer.innerHTML = '<p class="error">Failed to load fields.</p>'; });
				});
			}

			// Download buttons
			container.querySelectorAll('.report-download-btn').forEach(btn => {
				btn.addEventListener('click', function() {
					const format = this.dataset.format;
					const collection = collectionInput ? collectionInput.value : '';
					if (!collection) {
						alert('Please select a collection.');
						return;
					}

					const checked = fieldsContainer.querySelectorAll('input[name="fields[]"]:checked');
					if (checked.length === 0) {
						alert('Please select at least one field.');
						return;
					}

					const fields = Array.from(checked).map(cb => cb.value);
					const params = new URLSearchParams();
					params.set('fields', fields.join(','));

					const includeInput = container.querySelector('input[name="include"]');
					const excludeInput = container.querySelector('input[name="exclude"]');
					if (includeInput && includeInput.value) params.set('include', includeInput.value);
					if (excludeInput && excludeInput.value) params.set('exclude', excludeInput.value);

					const url = api + '/report/collections/' + encodeURIComponent(collection) + '/' + format + '?' + params.toString();
					window.location.href = url;
				});
			});
		})();
		</script>
		SCRIPT;
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
