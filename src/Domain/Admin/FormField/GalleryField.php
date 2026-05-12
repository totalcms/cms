<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

class GalleryField extends ImageField
{
	protected string $defaultFieldType = 'gallery';
	protected string $defaultInputType = 'gallery';

	public function buildFormField(): string
	{
		$imageData  = is_array($this->value) ? $this->value : []; // Image data is stored in the value field
		$api        = $this->form->baseApi();
		$imageworks = ['w' => ImageField::PREVIEW_WIDTH, 'h' => ImageField::PREVIEW_HEIGHT, 'q' => ImageField::PREVIEW_QUALITY];
		$options    = ['collection' => $this->form->collection, 'property' => $this->name];
		$id         = $this->form->id;

		// Render lightweight thumbnail-only previews (no dialogs per image)
		$previews = '';
		foreach ($imageData as $image) {
			$imagePath    = MediaTwigAdapter::buildImageworksGalleryAPI($api, $id, $image['name'], $image, $imageworks, $options);
			$imagePreview = $this->imagePreview($imagePath, $image['name'] ?? '');

			$previewAttrs = ['class' => 'image-preview', 'data-image-name' => $image['name'] ?? ''];
			if ($image['featured'] ?? false) {
				$previewAttrs['class'] .= ' featured';
			}
			$previews .= HTMLUtils::element('div', $imagePreview, $previewAttrs);
		}
		$previews = HTMLUtils::element('div', $previews, ['class' => 'total-preview']);

		$inputAttrs = [
			'id'       => 'field-' . $this->uuid,
			'type'     => 'text',
			'name'     => $this->name,
			'required' => $this->required ? '' : null,
		];
		$inputAttrs = array_filter($inputAttrs, fn (?string $x): bool => !is_null($x));

		$input   = HTMLUtils::inlineElement('input', $inputAttrs);
		$overlay = HTMLUtils::element('div', '', ['class' => 'dz-overlay dz-clickable']);

		// Template with full dialogs for the shared edit dialog (cloned once on first edit click)
		$imagePreview    = $this->imagePreview('', '');
		$linkDialog      = $this->linkDialog();
		$imageDialog     = $this->imageDialog('', []);
		$previewTemplate = HTMLUtils::element('div', $imagePreview . $imageDialog . $linkDialog, [
			'class' => 'image-preview',
		]);

		$template = HTMLUtils::element('template', $previewTemplate, [
			'id' => 'template-' . $this->uuid,
		]);

		// Embed all image data as JSON for client-side data store
		$galleryJson = HTMLUtils::element('script', json_encode($imageData, JSON_THROW_ON_ERROR), [
			'type' => 'application/json',
			'id'   => 'gallery-data-' . $this->uuid,
		]);

		$uploadButton = HTMLUtils::element('button', '', [
			'type'  => 'button',
			'title' => 'Upload New Image',
		]);
		$uploadButton = HTMLUtils::element('div', $uploadButton, [
			'class' => 'gallery-upload dz-clickable',
		]);

		return $input . $overlay . $previews . $template . $galleryJson . $uploadButton;
	}
}
