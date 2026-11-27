<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\ImageWorks\Service\ImageworksUrl;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

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
			$imagePath    = ImageworksUrl::buildImageworksGalleryAPI($api, $id, $image['name'], $image, $imageworks, $options);
			$imagePreview = $this->imagePreview($imagePath, $image['name'] ?? '');

			$previewAttrs = ['class' => 'image-preview', 'data-image-name' => $image['name'] ?? ''];
			if ($image['featured'] ?? false) {
				$previewAttrs['class'] .= ' featured';
			}
			$previews .= HTMLUtils::element('div', $imagePreview, $previewAttrs);
		}
		$previews = HTMLUtils::element('div', $previews, ['class' => 'total-preview']);

		$input   = $this->uploadInput();
		$overlay = $this->dropzoneOverlay();

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
			'title' => $this->t('image.upload_new', 'Upload New Image'),
		]);
		$uploadButton = HTMLUtils::element('div', $uploadButton, [
			'class' => 'gallery-upload dz-clickable',
		]);

		return $input . $overlay . $this->selectBar() . $previews . $template . $galleryJson . $uploadButton;
	}

	/**
	 * The Select button goes on the label's row, at its right end. It is the
	 * label's sibling rather than its child: with help on the label, the label
	 * fades out while the help shows, and a button inside it would go too.
	 */
	public function createFormField(string $content): string
	{
		return HTMLUtils::element('div', $this->createFieldLabel() . $this->selectButton() . $content . $this->createHelpText(), $this->buildFieldAttributes());
	}

	/**
	 * The multi-delete bar, shown only while the gallery is in selection mode
	 * (gallery.js). Its Delete sends every selected name in one request.
	 */
	private function selectBar(): string
	{
		$button = fn (string $class, string $label): string => HTMLUtils::element('button', '', [
			'type'       => 'button',
			'class'      => "gallery-bar-icon {$class}",
			'title'      => $label,
			'aria-label' => $label,
		]);

		$count = HTMLUtils::element('span', '', ['class' => 'gallery-select-count', 'aria-live' => 'polite']);
		$tools = $button('gallery-select-all', $this->t('image.select_all', 'Select All'))
			. $button('gallery-delete-selected', $this->t('image.delete_selected', 'Delete Selected'))
			. $button('gallery-select-done', $this->t('image.select_done', 'Done'));

		return HTMLUtils::element('div', $count . $tools, ['class' => 'gallery-select-bar']);
	}

	private function selectButton(): string
	{
		$label = $this->t('image.select', 'Select Images');

		return HTMLUtils::element('button', '', [
			'type'         => 'button',
			'class'        => 'gallery-select',
			'title'        => $label,
			'aria-label'   => $label,
			'aria-pressed' => 'false',
		]);
	}
}
