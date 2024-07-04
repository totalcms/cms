<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;
use TotalCMS\Domain\Twig\TotalCMSTwigAdapter;

final class ImageField extends FormField
{
	protected string $defaultFieldType = 'image';
	protected string $defaultInputType = 'image';

	private string $imagePath;
	/** @var array<string,mixed> */
	private array $imageData;

	public function init(): void
	{
		parent::init();

		$this->icon = false; // No icon for image field
		$this->imageData = $this->value; // Image data is stored in the value field

		$api        = $this->form->api;
		$imageworks = ['w' => 600, 'h' => 400];
		$options    = ['collection' => $this->form->collection, 'property' => $this->name];
		$id         = $this->form->id;

		$this->imagePath = TotalCMSTwigAdapter::buildImageworksAPI($api, $id, $this->imageData, $imageworks, $options);
	}

	public function buildFormField(): string
	{
		$previewAttrs = ['class' => 'image-preview'];
		if ($this->imageData['featured'] ?? false) {
			$previewAttrs['class'] .= " featured";
		}
		$imagePreview = $this->imagePreview($this->imagePath, $this->imageData['name'] ?? '');
		$linkDialog   = $this->linkDialog();
		$imageDialog  = $this->imageDialog();

		$previewTemplate = HTMLUtils::createHTMLElement('div', $imagePreview . $imageDialog . $linkDialog, $previewAttrs);

		$input    = HTMLUtils::createInlineHTMLElement('input', ['id' => 'field-' . $this->uuid, 'type' => 'hidden', 'name' => $this->name]);
		$overlay  = HTMLUtils::createHTMLElement('div', '', ['class' => 'dz-overlay dz-clickable']);
		$preview  = HTMLUtils::createHTMLElement('div', $previewTemplate, ['class' => 'total-preview']);
		$template = HTMLUtils::createHTMLElement('template', $previewTemplate, ['id' => 'template-' . $this->uuid]);

		return $input . $overlay . $preview . $template;
	}

	private function imagePreview(string $imagePath, string $alt): string
	{
		return <<<HTML
		<div class="dz-preview dz-file-preview not-found">
			<div class="actionbar">
				<button type="button" class="edit"     title="Edit Image Info"></button>
				<button type="button" class="links"    title="Image URL"></button>
				<button type="button" class="featured" title="Toggle Featured"></button>
				<button type="button" class="download" title="Download Original Image"></button>
				<button type="button" class="move"     title="Reorder Image"></button>
				<button type="button" class="upload dz-clickable" title="Upload New Image"></button>
				<button type="button" class="clear"    title="Clear Cache"></button>
				<button type="button" class="trash"    title="Delete Image"></button>
			</div>
			<img src="{$imagePath}" alt="{$alt}" onload="this.parentNode.classList.remove('not-found')" oncontextmenu="return false;" draggable="false" data-dz-thumbnail />
			<div class="dz-progress">
				<span class="dz-upload" data-dz-uploadprogress></span>
				<span class="dz-upload-progress-label" data-dz-uploadprogress>0%</span>
				<div class="dz-status"></div>
			</div>
		</div>
		HTML;
	}

	private function linkDialog(): string
	{
		return '';
	}

	private function imageDialog(): string
	{
		return '';
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
