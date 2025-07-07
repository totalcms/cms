<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Utils\HTMLUtils;

final class GalleryField extends ImageField
{
	protected string $defaultFieldType = 'gallery';
	protected string $defaultInputType = 'gallery';

	public function buildFormField(): string
	{
		$imageData  = is_array($this->value) ? $this->value : []; // Image data is stored in the value field
		$api        = $this->form->api;
		$imageworks = ['w' => ImageField::PREVIEW_WIDTH, 'h' => ImageField::PREVIEW_HEIGHT];
		$options    = ['collection' => $this->form->collection, 'property' => $this->name];
		$id         = $this->form->id;

		$previews = '';
		foreach ($imageData as $image) {
			$imagePath = TotalCMSTwigAdapter::buildImageworksGalleryAPI($api, $id, $image['name'], $image, $imageworks, $options);

			$imagePreview = $this->imagePreview($imagePath, $image['name'] ?? '');
			$linkDialog   = $this->linkDialog($image['name']);
			$imageDialog  = $this->imageDialog($imagePath, $image);

			$previewAttrs = ['class' => 'image-preview'];
			if ($image['featured'] ?? false) {
				$previewAttrs['class'] .= ' featured';
			}
			$previews .= HTMLUtils::element('div', $imagePreview . $imageDialog . $linkDialog, $previewAttrs);
		}
		$previews = HTMLUtils::element('div', $previews, ['class' => 'total-preview']);

		$input = HTMLUtils::inlineElement('input', [
			'id'   => 'field-' . $this->uuid,
			'type' => 'text',
			'name' => $this->name,
		]);
		$overlay = HTMLUtils::element('div', '', ['class' => 'dz-overlay dz-clickable']);

		$imagePreview    = $this->imagePreview('', '');
		$linkDialog      = $this->linkDialog();
		$imageDialog     = $this->imageDialog('', []);
		$previewTemplate = HTMLUtils::element('div', $imagePreview . $imageDialog . $linkDialog, [
			'class' => 'image-preview',
		]);

		$template = HTMLUtils::element('template', $previewTemplate, [
			'id' => 'template-' . $this->uuid,
		]);

		$uploadButton = HTMLUtils::element('button', '', [
			'type'  => 'button',
			'title' => 'Upload New Image',
		]);
		$uploadButton = HTMLUtils::element('div', $uploadButton, [
			'class' => 'gallery-upload dz-clickable',
		]);

		return $input . $overlay . $previews . $template . $uploadButton;
	}
}
