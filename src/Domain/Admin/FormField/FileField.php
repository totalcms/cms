<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class FileField extends UploadField
{
	protected string $defaultFieldType = 'file';

	public function buildFormField(): string
	{
		$fileData = is_array($this->value) ? $this->value : []; // File data is stored in the value field

		$name            = $fileData['download'] ?? $fileData['name'] ?? '';
		$previewTemplate = HTMLUtils::element(
			'div',
			$this->filePreview($name) . $this->fileDialog($fileData) . $this->linkDialog(),
			['class' => 'file-preview'],
		);

		return $this->dropzoneShell($previewTemplate);
	}

	protected function linkTool(): string
	{
		return 'filelinks';
	}

	protected function linkDialogClass(): string
	{
		return 'file-links-dialog';
	}

	protected function filePreview(string $name = ''): string
	{
		$ext       = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$iconClass = 'icon-' . $ext;

		$notFound = $name === '' ? 'not-found' : '';

		$edit     = $this->esc('upload.edit_file_info', 'Edit File Info');
		$links    = $this->esc('upload.download_links', 'Download Links');
		$download = $this->esc('upload.download_file', 'Download File');
		$upload   = $this->esc('file.upload_new', 'Upload New File');
		$trash    = $this->esc('upload.delete_file', 'Delete File');

		return <<<HTML
		<div class="dz-preview dz-file-preview {$notFound}">
			<div class="actionbar">
				<button type="button" class="edit" title="{$edit}"></button>
				<button type="button" class="links" title="{$links}"></button>
				<button type="button" class="download" title="{$download}"></button>
				<button type="button" class="upload dz-clickable" title="{$upload}"></button>
				<button type="button" class="trash" title="{$trash}"></button>
			</div>

			<div class="file-icon {$iconClass}"></div>
			<p class="filename">{$name}</p>

			<div class="dz-progress">
				<span class="dz-upload" data-dz-uploadprogress></span>
				<span class="dz-upload-progress-label" data-dz-uploadprogress>0%</span>
				<div class="dz-status"></div>
			</div>
		</div>
		HTML;
	}

	/** @param array<string,mixed> $data */
	protected function dialogFields(array $data): string
	{
		$passwordHelp = $this->t('file.password_help', 'Require a password to download this file. This overrides all collection level access controls.');
		$protection   = HTMLUtils::details(
			$this->t('upload.section_protection', 'Protection'),
			$this->protectionFields($data, $passwordHelp),
		);

		return $this->infoFields($data) . $protection . $this->metaFields($data);
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
