<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Object forms with a hand-laid layout: the blog and feed forms with their
 * two columns and optional fields, the mailer with its bulk-send section,
 * the playground and data-view editors.
 *
 * Each builds an ObjectForm through the factory and arranges its fields
 * itself instead of taking the schema's order. Reached through the factory
 * (`cms.form.blog()` …), which delegates.
 */
final readonly class PresetForms
{
	private string $api;

	public function __construct(
		private TotalFormFactory $forms,
		private FormServices $services,
	) {
		$this->api = $this->services->config->api . '/api';
	}

	/** @param array<string,mixed> $options */
	public function playground(string $id = '', array $options = []): string
	{
		$options = array_merge([
			'save'        => true,
			'delete'      => true,
			'class'       => 'playground-form no-unsaved-warning',
		], $options);
		$options['id'] = $id;

		$form = $this->forms->builder('playground', $options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function dataviews(string $id = '', array $options = []): string
	{
		$this->services->dataViewLister?->ensureCollection();

		$options = array_merge([
			'save'        => true,
			'delete'      => true,
			'class'       => 'dataview-form help-on-hover help-box no-unsaved-warning',
		], $options);
		$options['id'] = $id;

		$form = $this->forms->builder('dataviews', $options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function mailer(string $id = '', array $options = []): string
	{
		$options = array_merge([
			'save'        => true,
			'delete'      => true,
			'class'       => 'help-on-hover help-box mailer-form formgrid',
			'useFormGrid' => false,
		], $options);
		$options['id'] = $id;

		$form = $this->forms->builder('mailer', $options);

		// Row: Active toggle + ID
		$content  = $form->field('active');
		$content .= $form->field('id');
		$content .= $form->field('name');
		$content .= $form->field('category');
		$content .= $form->field('description');

		$content .= HTMLUtils::inlineElement('hr', ['class' => 'form-grid-section-divider']);

		$content .= $form->field('to');
		$content .= $form->field('from');
		$content .= $form->field('toName');
		$content .= $form->field('fromName');
		$content .= $form->field('replyTo');
		$content .= $form->field('cc');
		$content .= $form->field('bcc');

		$content .= HTMLUtils::inlineElement('hr', ['class' => 'form-grid-section-divider']);

		// Subject, Body HTML, Body Text (full width)
		$content .= $form->field('subject');
		$content .= $form->field('bodyHtml');
		$content .= $form->field('bodyText');

		$bulkSection = '';

		if ($id !== '') {
			$hiddenMailerId = HTMLUtils::inlineElement('input', [
				'type' => 'hidden', 'name' => 'mailerId', 'value' => $id,
			]);

			$objectPickerScript = <<<SCRIPT
			<script>
			(function() {
				let debounceTimer = null;
				let cachedData = [];
				const pickerInput = document.querySelector('select[name="bulkObjectIds[]"]');
				const picker = pickerInput ? pickerInput.closest('.form-field') : null;
				const previewInput = document.querySelector('select[name="bulkPreviewObjectId"]');
				const previewField = previewInput ? previewInput.closest('.form-field') : null;
				if (!picker) return;

				function updateChoices(field, data) {
					if (!field || !field.totalfield || !field.totalfield.choices) return;
					const choices = field.totalfield.choices;
					choices.clearStore();
					if (data.length > 0) {
						choices.setChoices(data, 'value', 'label', true);
					}
				}

				function fetchObjects() {
					const collection = document.querySelector('[name="bulkCollection"]');
					const include = document.querySelector('[name="bulkInclude"]');
					const exclude = document.querySelector('[name="bulkExclude"]');
					if (!collection || !collection.value) return;

					const params = new URLSearchParams({ bulkCollection: collection.value });
					if (include && include.value) params.set('bulkInclude', include.value);
					if (exclude && exclude.value) params.set('bulkExclude', exclude.value);

					fetch('{$this->api}/action/mailer/bulk/objects?' + params.toString())
						.then(r => r.json())
						.then(data => {
							cachedData = data;
							updateChoices(picker, data);
							updateChoices(previewField, data);
						})
						.catch(() => {});
				}

				function debouncedFetch() {
					clearTimeout(debounceTimer);
					debounceTimer = setTimeout(fetchObjects, 500);
				}

				const collectionEl = document.querySelector('[name="bulkCollection"]');
				const includeEl = document.querySelector('[name="bulkInclude"]');
				const excludeEl = document.querySelector('[name="bulkExclude"]');

				if (collectionEl) collectionEl.addEventListener('change', fetchObjects);
				if (includeEl) includeEl.addEventListener('input', debouncedFetch);
				if (excludeEl) excludeEl.addEventListener('input', debouncedFetch);

				// When the preview accordion opens, apply cached data
				if (previewField) {
					const details = previewField.closest('details');
					if (details) {
						details.addEventListener('toggle', () => {
							if (details.open && cachedData.length > 0) {
								updateChoices(previewField, cachedData);
							}
						});
					}
				}

				if (collectionEl && collectionEl.value) fetchObjects();
			})();
			</script>
			SCRIPT;

			// Audience + Send combined accordion
			$sendAttrs = array_merge(
				HTMLUtils::htmxAttributes($this->api . '/action/mailer/bulk', 'post', [
					'target'  => '#bulk-send-output',
					'swap'    => 'innerHTML',
					'confirm' => 'Are you sure you want to queue a bulk email send? This will send one email per matching object.',
				]),
				['class' => 'dash-button accent', 'id' => 'bulk-send-btn']
			);
			$sendAttrs['hx-include'] = '[name="mailerId"],[name="bulkCollection"],[name="bulkInclude"],[name="bulkExclude"],[name="bulkOverrideTo"],[name="bulkscheduledAt"],[name="bulkObjectIds[]"]';

			$bulkSendFields = $form->field('bulkCollection') .
				$form->field('bulkInclude') .
				$form->field('bulkExclude') .
				$form->field('bulkObjectIds[]', [
					'field'       => 'list',
					'label'       => 'Specific Objects',
					'help'        => 'Select specific objects to override filters. Leave empty to use filters above.',
					'placeholder' => 'Select objects...',
					'settings'    => [
						'addChoices'       => false,
						'removeItemButton' => true,
					],
				]) .
				HTMLUtils::inlineElement('hr', ['class' => 'bulk-divider']) .
				$form->field('bulkOverrideTo', [
					'field'       => 'email',
					'label'       => 'Override To Email (for testing)',
					'placeholder' => 'test@example.com',
					'help'        => 'Override recipient email for testing. All emails will be sent to this address instead.',
				]) .
				$form->field('bulkscheduledAt', [
					'field'       => 'datetime',
					'label'       => 'Schedule',
					'placeholder' => 'Enter a date and time to schedule this email',
				]) .
				HTMLUtils::element('button', 'Queue Bulk Send', $sendAttrs) .
				'<div id="bulk-send-output" class="bulk-send-output"></div>';
			$bulkSendDetails = HTMLUtils::details('Audience & Send', $bulkSendFields);

			// Preview accordion
			$previewAttrs = array_merge(
				HTMLUtils::htmxAttributes($this->api . '/action/mailer/bulk/preview', 'post', [
					'target' => '#bulk-preview-output',
					'swap'   => 'innerHTML',
				]),
				['class' => 'dash-button', 'id' => 'bulk-preview-btn']
			);
			$previewAttrs['hx-include'] = '[name="mailerId"],[name="bulkPreviewObjectId"],[name="bulkCollection"]';

			$bulkPreviewForm = $form->field('bulkPreviewObjectId', [
				'field'       => 'list',
				'label'       => 'Preview Object',
				'placeholder' => 'Select an object to preview...',
				'settings'    => [
					'addChoices'       => false,
					'removeItemButton' => true,
					'maxItemCount'     => 1,
				],
			]) . HTMLUtils::element('button', 'Preview', $previewAttrs);
			$bulkPreviewForm    = HTMLUtils::element('div', $bulkPreviewForm, ['class' => 'bulk-preview-form']);
			$bulkPreviewOutput  = HTMLUtils::element('div', '', [
				'id'    => 'bulk-preview-output',
				'class' => 'bulk-preview-output',
			]);
			$bulkPreviewDetails = HTMLUtils::details('Preview', $bulkPreviewForm . $bulkPreviewOutput);

			$bulkSection  = $hiddenMailerId;
			$bulkSection .= HTMLUtils::element('h2', 'Bulk Send <span class="bulk-pro-badge">Pro</span>');
			$bulkSection .= HTMLUtils::element('p', 'Send this email to every matching object in a collection.');
			$bulkSection .= $bulkSendDetails . $bulkPreviewDetails . $objectPickerScript;
			$bulkSection  = HTMLUtils::element('form', $bulkSection, ['class' => 'bulk-send-section totalform custom-layout help-on-hover help-box no-save no-unsaved-warning']);
		}

		return $form->build($content, $bulkSection);
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 *
	 * @param array<string,mixed> $options
	 */
	public function blog(array $options = []): string
	{
		$options = array_merge([
			'collection' => 'blog',
			'save'       => true,
			'delete'     => true,
			'fields'     => [],
		], $options);

		$class            = trim('custom-layout ' . ($options['class'] ?? ''));
		$options['class'] = $class;

		$fields = array_merge([
			'date'       => true,
			'summary'    => true,
			'content'    => true,
			'author'     => true,
			'tags'       => true,
			'featured'   => true,
			'draft'      => true,
			'image'      => true,
			'categories' => false,
			'extra'      => false,
			'extra2'     => false,
			'media'      => false,
			'genre'      => false,
			'labels'     => false,
			'archived'   => false,
			'gallery'    => false,
		], $options['fields']);
		// remove fields from options since it's not a valid option for TotalForm
		unset($options['fields']);

		$form = $this->forms->builder($options['collection'], $options);

		$col1  = $form->field('id');
		$col1 .= $form->field('created', ['field' => 'hidden']);
		$col1 .= $form->field('updated', ['field' => 'hidden']);
		$col1 .= $form->field('title');
		if ($fields['date']) {
			$col1 .= $form->field('date');
		}
		if ($fields['media']) {
			$col1 .= $form->field('media', ['field' => 'url']);
		}
		if ($fields['summary']) {
			$col1 .= $form->field('summary', ['field' => 'styledtext']);
		}
		if ($fields['content']) {
			$col1 .= $form->field('content', ['field' => 'styledtext']);
		}
		if ($fields['extra']) {
			$col1 .= $form->field('extra', ['field' => 'styledtext']);
		}
		if ($fields['extra2']) {
			$col1 .= $form->field('extra2', ['field' => 'styledtext']);
		}

		$col2 = '';
		if ($fields['author']) {
			$col2 .= $form->field('author');
		}
		if ($fields['genre']) {
			$col2 .= $form->field('genre');
		}
		if ($fields['tags']) {
			$col2 .= $form->field('tags', ['field' => 'list']);
		}
		if ($fields['categories']) {
			$col2 .= $form->field('categories', ['field' => 'list']);
		}
		if ($fields['labels']) {
			$col2 .= $form->field('labels', ['field' => 'list']);
		}

		$inline = '';
		if ($fields['featured']) {
			$inline .= $form->field('featured', ['field' => 'toggle', 'help' => false]);
		}
		if ($fields['draft']) {
			$inline .= $form->field('draft', ['field' => 'toggle', 'help' => false]);
		}
		if ($fields['archived']) {
			$inline .= $form->field('archived', ['field' => 'toggle', 'help' => false]);
		}
		$col2 .= $form->layoutInline($inline);

		if ($fields['image']) {
			$col2 .= $form->field('image');
		}
		if ($fields['gallery']) {
			$col2 .= $form->field('gallery');
		}

		$layout = $form->layout2Columns($col1, $col2);

		return $form->build($layout);
	}

	/** @param array<string,mixed> $options */
	public function feed(array $options = []): string
	{
		$options = array_merge([
			'collection' => 'feed',
			'save'       => true,
			'delete'     => true,
		], $options);

		$class            = trim('custom-layout ' . ($options['class'] ?? ''));
		$options['class'] = $class;

		$form = $this->forms->builder($options['collection'], $options);

		$top = $form->field('id', ['class' => 'hidden-field']);
		$top .= $form->field('created', ['field' => 'hidden']);
		$top .= $form->field('updated', ['field' => 'hidden']);

		$col1  = $form->field('title');
		$col1 .= $form->field('content', ['field' => 'styledtext']);

		$col2  = $form->field('image');
		$col2 .= $form->field('featured', ['help' => false, 'field' => 'toggle']);

		$layout = $form->layout2Columns($col1, $col2);

		return $form->build($top . $layout);
	}
}
