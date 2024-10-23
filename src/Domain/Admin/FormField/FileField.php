<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class FileField extends FormField
{
	protected string $defaultFieldType = 'file';
	protected string $defaultInputType = 'file';

	public function init(): void
	{
		parent::init();

		$this->icon = false; // No icon for file fields
	}

	public function buildFormField(): string
	{
		$fileData = is_array($this->value) ? $this->value : []; // File data is stored in the value field

		$previewAttrs = ['class' => 'file-preview'];
		$mime         = $fileData['mime'] ?? '';
		$name         = $fileData['name'] ?? $fileData['filename'] ?? '';
		$filePreview  = $this->filePreview($mime, $name);
		$fileDialog   = $this->fileDialog($fileData);

		$previewTemplate = HTMLUtils::element('div', $filePreview . $fileDialog, $previewAttrs);

		$input    = HTMLUtils::inlineElement('input', ['id' => 'field-' . $this->uuid, 'type' => 'text', 'name' => $this->name]);
		$overlay  = HTMLUtils::element('div', '', ['class' => 'dz-overlay dz-clickable']);
		$preview  = HTMLUtils::element('div', $previewTemplate, ['class' => 'total-preview']);
		$template = HTMLUtils::element('template', $previewTemplate, ['id' => 'template-' . $this->uuid]);

		return $input . $overlay . $preview . $template;
	}

	protected function filePreview(string $mime = '', string $name = ''): string
	{
		$mime      = strtolower(basename($mime));
		$mimeClass = '';

		if (!empty($mime)) {
			$mimeClass = 'icon-' . $mime;
		}

		$notFound = empty($name) ? 'not-found' : '';

		return <<<HTML
		<div class="dz-preview dz-file-preview {$notFound}">
			<div class="actionbar">
				<button type="button" class="edit" title="Edit File Info"></button>
				<button type="button" class="links" title="Copy Download Link"></button>
				<button type="button" class="macro" title="Copy Download Macro"></button>
				<button type="button" class="download" title="Download File"></button>
				<button type="button" class="upload dz-clickable" title="Upload New File"></button>
				<button type="button" class="trash" title="Delete File"></button>
			</div>

			<div class="file-icon {$mimeClass}"></div>
			<p class="filename">{$name}</p>

			<div class="dz-progress">
				<span class="dz-upload" data-dz-uploadprogress></span>
				<span class="dz-upload-progress-label" data-dz-uploadprogress>0%</span>
				<div class="dz-status"></div>
			</div>
		</div>
		HTML;
	}

	/** @param array<string,mixed> $fileData */
	protected function fileDialog(array $fileData): string
	{
		$content = $this->fileFieldsSection($fileData);
		$content .= $this->closeSection();

		$dialog = HTMLUtils::dialog($content, 'file-edit-dialog');

		return $dialog;
	}

	/** @param array<string,mixed> $fileData */
	private function fileFieldsSection(array $fileData): string
	{
		$fields = $this->infoFields($fileData);
		$fields .= $this->protectionFields($fileData);
		$fields .= $this->metaFields($fileData);

		return HTMLUtils::scroller($fields);
	}

	private function closeSection(): string
	{
		$button = HTMLUtils::button('Close', ['class' => 'close']);

		return HTMLUtils::element('section', $button);
	}

	/** @param array<string,mixed> $fileData */
	private function infoFields(array $fileData): string
	{
		$content = $this->form->field('name', [
			'field' => 'text',
			'label' => 'Download Name',
			'help'  => 'The name of the file when it gets downloaded.',
			'value' => $fileData['name'] ?? $fileData['filename'] ?? '',
		]);
		$content .= $this->form->field('comments', [
			'field'       => 'textarea',
			'label'       => 'Comments',
			'help'        => 'Comments about this file',
			'value'       => $fileData['comments'] ?? '',
		]);
		$content .= $this->form->field('tags', [
			'field'       => 'list',
			'label'       => 'Tags',
			'help'        => 'Add tags to help organize your files.',
			'placeholder' => 'Add Tags',
			'value'       => $fileData['tags'] ?? [],
		]);

		return HTMLUtils::details('Info', $content);
	}

	/** @param array<string,mixed> $fileData */
	private function protectionFields(array $fileData): string
	{
		$content = $this->form->field('protected', [
			'field'       => 'checkbox',
			'label'       => 'Protected by Collection',
			'help'        => 'Access group protection is set in the Collection.',
			'value'       => $fileData['protected'] ?? false,
		]);
		$content .= $this->form->field('password', [
			'field' => 'password',
			'label' => 'Password',
			'help'  => 'Require a password to download this file. This overrides all collection level access controls.',
			'value' => $fileData['password'] ?? '',
		]);

		return HTMLUtils::details('Protection', $content);
	}

	/** @param array<string,mixed> $fileData */
	private function metaFields(array $fileData): string
	{
		$content = $this->form->field('filename', [
			'field'    => 'text',
			'label'    => 'Filename',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['filename'] ?? '',
		]);
		$content .= $this->form->field('size', [
			'field'    => 'number',
			'label'    => 'Size',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['size'] ?? '',
		]);
		$content .= $this->form->field('mime', [
			'field'    => 'text',
			'label'    => 'MIME Type',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['mime'] ?? '',
		]);
		$content .= $this->form->field('uploadDate', [
			'field'    => 'datetime',
			'label'    => 'Upload Date',
			'icon'     => false,
			'readonly' => true,
			'value'    => $fileData['uploadDate'] ?? '',
		]);

		return HTMLUtils::details('Meta (Readonly)', $content);
	}
}

// Example Rules Options
// options: {
// 	rules : {
// 		height      : {min:500,max:1000},
// 		width       : {min:500,max:1000},
// 		size        : {min:0,max:1000},
// 		orientation : 'landscape',
// 		aspectratio : '4:3',
// 		count       : {max:10},
// 		filetype    : ['image/jpeg'],
// 		filename    : ['image.jpg'],
// 	}
// }
