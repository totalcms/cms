<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class DepotDropField extends FormField
{
	protected string $defaultFieldType = 'depotDrop';
	protected string $defaultInputType = 'file';

	public function init(): void
	{
		parent::init();

		$this->icon = false;
	}

	public function buildFormField(): string
	{
		$input    = HTMLUtils::inlineElement('input', ['id' => 'field-' . $this->uuid, 'type' => 'text', 'name' => $this->name]);
		$dropzone = $this->dropzone();
		$template = $this->fileTemplate();

		return $input . $dropzone . $template;
	}

	private function dropzone(): string
	{
		$overlay = HTMLUtils::element('div', '', ['class' => 'dz-overlay']);
		$preview = HTMLUtils::element('ul', '', ['class' => 'total-preview']);
		$upload  = $this->uploadButton();

		return HTMLUtils::element('div', $overlay . $preview . $upload, ['class' => 'depot-drop-zone dz-clickable']);
	}

	private function uploadButton(): string
	{
		$button = HTMLUtils::element('button', '', [
			'type'  => 'button',
			'title' => 'Upload Files',
		]);

		return HTMLUtils::element('div', $button, ['class' => 'depot-drop-upload dz-clickable']);
	}

	private function fileTemplate(): string
	{
		// Card structure: icon on top, filename below, progress bar added by JS
		$icon    = HTMLUtils::element('div', '', ['class' => 'file-icon']);
		$name    = HTMLUtils::element('p', '', ['class' => 'filename']);
		$preview = HTMLUtils::element('div', $icon . $name, ['class' => 'dz-preview']);
		$card    = HTMLUtils::element('li', $preview, ['class' => 'depot-drop-card']);

		return HTMLUtils::element('template', $card, ['class' => 'file-template']);
	}
}
