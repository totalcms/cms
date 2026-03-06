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
	/** @var \Closure(string, array<string,string>, string): string */
	private \Closure $translator;

	/**
	 *  @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 *  @param \Closure(string, array<string,string>, string): string $translator
	 *  @param array<string|array{value: string, label: string}> $includeOptions
	 *  @param array<string|array{value: string, label: string}> $excludeOptions
	 */
	public function __construct(
		private string $api,
		private CollectionLister $collectionLister,
		\Closure $translator,
		private string $collection = '',
		private string $include = '',
		private string $exclude = '',
		private array $includeOptions = [],
		private array $excludeOptions = [],
		private bool $includeSelect = false,
		private bool $excludeSelect = false,
	) {
		$this->translator = $translator;
	}

	/**
	 * Translate a key from the admin domain.
	 */
	private function t(string $key): string
	{
		return ($this->translator)($key, [], 'admin');
	}

	public function build(): string
	{
		$content = $this->buildCollectionSelector()
			. $this->buildFilterFields()
			. $this->buildFieldsContainer()
			. $this->buildActions();

		return HTMLUtils::element('div', $content, ['class' => 'report-form'])
			. $this->buildScript();
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

		$options = array_map(fn (CollectionData $c): array => [
			'value' => $c->id,
			'label' => $c->name !== '' ? $c->name : $c->id,
		], $collections);

		$select = HTMLUtils::select($options, '', $this->t('report.collection_placeholder'), [
			'name'  => 'collection',
			'class' => 'report-collection-input',
		]);

		return HTMLUtils::element('div', HTMLUtils::element('label', $this->t('report.collection_label')) . $select, [
			'class' => 'report-collection-selector',
		]);
	}

	private function buildFilterFields(): string
	{
		$includeDiv = $this->buildFilterField(
			'include',
			$this->t('report.include_label'),
			$this->include,
			$this->t('report.include_placeholder'),
			$this->includeOptions,
			$this->includeSelect,
		);

		$excludeDiv = $this->buildFilterField(
			'exclude',
			$this->t('report.exclude_label'),
			$this->exclude,
			$this->t('report.exclude_placeholder'),
			$this->excludeOptions,
			$this->excludeSelect,
		);

		return HTMLUtils::element('div', $includeDiv . $excludeDiv, [
			'class' => 'report-filters',
		]);
	}

	/**
	 * Build a single filter field as either a select or text input with optional datalist.
	 *
	 * @param array<string|array{value: string, label: string}> $options
	 */
	private function buildFilterField(
		string $name,
		string $label,
		string $value,
		string $placeholder,
		array $options,
		bool $useSelect,
	): string {
		if ($useSelect && $options !== []) {
			$field = $this->buildSelectFilter($name, $value, $placeholder, $options);
		} else {
			$field = $this->buildInputFilter($name, $value, $placeholder, $options);
		}

		$labelHtml = HTMLUtils::element('label', $label);

		return HTMLUtils::element('div', $labelHtml . $field, [
			'class' => "report-filter-field {$name}-filter-field",
		]);
	}

	/**
	 * Build a select element for a filter field.
	 *
	 * @param array<string|array{value: string, label: string}> $options
	 */
	private function buildSelectFilter(string $name, string $value, string $placeholder, array $options): string
	{
		return HTMLUtils::select($options, $value, $placeholder, [
			'name'  => $name,
			'class' => 'report-filter-input',
		]);
	}

	/**
	 * Build a text input with optional datalist for a filter field.
	 *
	 * @param array<string|array{value: string, label: string}> $options
	 */
	private function buildInputFilter(string $name, string $value, string $placeholder, array $options): string
	{
		$attrs = [
			'type'        => 'text',
			'name'        => $name,
			'value'       => $value,
			'placeholder' => $placeholder,
			'class'       => 'report-filter-input',
		];

		$datalist = '';
		if ($options !== []) {
			$datalistId     = "report-{$name}-datalist";
			$attrs['list']  = $datalistId;
			$datalist       = HTMLUtils::datalist($datalistId, $options);
		}

		return HTMLUtils::inlineElement('input', $attrs) . $datalist;
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
			$content = HTMLUtils::element('div', '<p class="report-fields-placeholder">' . $this->t('report.fields_placeholder') . '</p>', [
				'id'    => 'report-fields',
				'class' => 'report-fields',
			]);
		}

		return $content;
	}

	private function buildActions(): string
	{
		$csvBtn = HTMLUtils::element('button', $this->t('report.download_csv'), [
			'type'        => 'button',
			'class'       => 'dash-button button btn accent report-download-btn report-download-csv',
			'data-format' => 'csv',
		]);

		$jsonBtn = HTMLUtils::element('button', $this->t('report.download_json'), [
			'type'        => 'button',
			'class'       => 'dash-button button btn report-download-btn report-download-json',
			'data-format' => 'json',
		]);

		return HTMLUtils::element('div', $csvBtn . $jsonBtn, [
			'class' => 'report-actions',
		]);
	}

	private function buildScript(): string
	{
		$api                  = htmlspecialchars($this->api, ENT_QUOTES, 'UTF-8');
		$alertSelectCollection = htmlspecialchars($this->t('report.alert_select_collection'), ENT_QUOTES, 'UTF-8');
		$alertSelectField      = htmlspecialchars($this->t('report.alert_select_field'), ENT_QUOTES, 'UTF-8');
		$fieldsPlaceholder     = htmlspecialchars($this->t('report.fields_placeholder'), ENT_QUOTES, 'UTF-8');
		$fieldsError           = htmlspecialchars($this->t('report.fields_error'), ENT_QUOTES, 'UTF-8');

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
						fieldsContainer.innerHTML = '<p class="report-fields-placeholder">{$fieldsPlaceholder}</p>';
						return;
					}
					fetch(api + '/report/collections/' + encodeURIComponent(collection) + '/fields')
						.then(r => r.text())
						.then(html => { fieldsContainer.innerHTML = html; })
						.catch(() => { fieldsContainer.innerHTML = '<p class="error">{$fieldsError}</p>'; });
				});
			}

			// Download buttons
			container.querySelectorAll('.report-download-btn').forEach(btn => {
				btn.addEventListener('click', function() {
					const format = this.dataset.format;
					const collection = collectionInput ? collectionInput.value : '';
					if (!collection) {
						alert('{$alertSelectCollection}');
						return;
					}

					const checked = fieldsContainer.querySelectorAll('input[name="fields[]"]:checked');
					if (checked.length === 0) {
						alert('{$alertSelectField}');
						return;
					}

					const fields = Array.from(checked).map(cb => cb.value);
					const params = new URLSearchParams();
					params.set('fields', fields.join(','));

					const includeInput = container.querySelector('[name="include"]');
					const excludeInput = container.querySelector('[name="exclude"]');
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
