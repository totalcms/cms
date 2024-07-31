<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Twig\TotalCMSTwigAdapter;
use TotalCMS\Utils\HTMLUtils;

class ImageField extends FormField
{
	protected string $defaultFieldType = 'image';
	protected string $defaultInputType = 'image';

	public const PREVIEW_WIDTH  = 600;
	public const PREVIEW_HEIGHT = 400;

	public function init(): void
	{
		parent::init();

		$this->icon = false; // No icon for image fields
	}

	public function buildFormField(): string
	{
		$imageData = is_array($this->value) ? $this->value : []; // Image data is stored in the value field

		$api        = $this->form->api;
		$imageworks = ['w' => self::PREVIEW_WIDTH, 'h' => self::PREVIEW_HEIGHT];
		$options    = ['collection' => $this->form->collection, 'property' => $this->name];
		$id         = $this->form->id;

		$imagePath = TotalCMSTwigAdapter::buildImageworksAPI($api, $id, $imageData, $imageworks, $options);

		$previewAttrs = ['class' => 'image-preview'];
		if ($imageData['featured'] ?? false) {
			$previewAttrs['class'] .= ' featured';
		}
		$imagePreview = $this->imagePreview($imagePath, $imageData['name'] ?? '');
		$linkDialog   = $this->linkDialog();
		$imageDialog  = $this->imageDialog($imagePath, $imageData);

		$previewTemplate = HTMLUtils::element('div', $imagePreview . $imageDialog . $linkDialog, $previewAttrs);

		$input    = HTMLUtils::inlineElement('input', ['id' => 'field-' . $this->uuid, 'type' => 'hidden', 'name' => $this->name]);
		$overlay  = HTMLUtils::element('div', '', ['class' => 'dz-overlay dz-clickable']);
		$preview  = HTMLUtils::element('div', $previewTemplate, ['class' => 'total-preview']);
		$template = HTMLUtils::element('template', $previewTemplate, ['id' => 'template-' . $this->uuid]);

		return $input . $overlay . $preview . $template;
	}

	protected function imagePreview(string $imagePath, string $alt): string
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

	protected function linkDialog(?string $name = null): string
	{
		// Gallery passes the name of the image
		// The name should be null for an image field

		$query = http_build_query([
			'id'         => $this->form->id,
			'collection' => $this->form->collection,
			'property'   => $this->name,
			'name'       => $name,
			'w'          => self::PREVIEW_WIDTH,
		]);
		// 	The cms.api may have a ? because of the Stacks Preview server
		$join = strpos($this->form->api, '?') !== false ? '&' : '?';

		$iframe = HTMLUtils::iframe("{$this->form->api}/admin/imageworks{$join}{$query}");
		$dialog = HTMLUtils::dialog($iframe, 'image-link-dialog');

		return $dialog;
	}

	/** @param array<string,mixed> $imageData */
	protected function imageDialog(string $imagePath, array $imageData): string
	{
		$content = $this->imagePreviewSection($imagePath, $imageData);
		$content .= $this->imageFieldsSection($imageData);
		$content .= $this->closeSection();

		$dialog = HTMLUtils::dialog($content, 'split-view image-edit-dialog');

		return $dialog;
	}

	/** @param array<string,mixed> $imageData */
	private function imagePreviewSection(string $imagePath, array $imageData): string
	{
		$image = HTMLUtils::inlineElement('img', [
			'src'               => $imagePath,
			'oncontextmenu'     => 'return false;',
			'draggable'         => 'false',
			'data-dz-thumbnail' => '',
		]);

		$top    = $imageData['focalpoint']['y'] ?? 50;
		$left   = $imageData['focalpoint']['x'] ?? 50;
		$fpoint = HTMLUtils::element('div', '', [
			'class' => 'focal-point',
			'style' => "top:{$top}%;left:{$left}%",
		]);

		return HTMLUtils::element('section', $image . $fpoint, ['class' => 'image-preview']);
	}

	/** @param array<string,mixed> $imageData */
	private function imageFieldsSection(array $imageData): string
	{
		$fields = $this->infoFields($imageData);
		$fields .= $this->focalFields($imageData);
		$fields .= $this->exifFields($imageData);
		$fields .= $this->cameraFields($imageData);
		$fields .= $this->gpsFields($imageData);
		$fields .= $this->paletteFields($imageData);
		$fields .= $this->metaFields($imageData);

		return HTMLUtils::scroller($fields);
	}

	private function closeSection(): string
	{
		$button = HTMLUtils::button('Close', ['class' => 'close']);

		return HTMLUtils::element('section', $button);
	}

	/** @param array<string,mixed> $imageData */
	private function infoFields(array $imageData): string
	{
		$content = $this->form->field('featured', [
			'field' => 'checkbox',
			'label' => 'Featured',
			'help'  => 'Mark this image as featured.',
			'value' => $imageData['featured'] ?? false,
		]);
		$content .= $this->form->field('alt', [
			'field'       => 'text',
			'label'       => 'Alt Text',
			'help'        => 'Alt text is used by screen readers and search engines to describe the image.',
			'placeholder' => 'Enter Alt Text',
			'value'       => $imageData['alt'] ?? '',
		]);
		$content .= $this->form->field('link', [
			'field'       => 'url',
			'label'       => 'Link',
			'help'        => 'Enter a URL to link the image to.',
			'placeholder' => 'https://example.com',
			'value'       => $imageData['link'] ?? '',
		]);
		$content .= $this->form->field('tags', [
			'field'       => 'list',
			'label'       => 'Tags',
			'help'        => 'Add tags to help organize your images.',
			'placeholder' => 'Add Tags',
			'value'       => $imageData['tags'] ?? [],
		]);

		return HTMLUtils::details('Info', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function focalFields(array $imageData): string
	{
		$content = $this->form->field('focalpoint-x', [
			'field' => 'range',
			'label' => 'Focal Point X',
			'help'  => 'Set the horizontal focal point coordinate of the image.',
			'value' => $imageData['focalpoint']['x'] ?? 50,
		]);
		$content .= $this->form->field('focalpoint-y', [
			'field' => 'range',
			'label' => 'Focal Point Y',
			'help'  => 'Set the vertical focal point coordinate of the image.',
			'value' => $imageData['focalpoint']['y'] ?? 50,
		]);

		return HTMLUtils::details('Focal Point', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function exifFields(array $imageData): string
	{
		$content = $this->form->field('exif-date', [
			'field' => 'datetime',
			'label' => 'Date',
			'value' => $imageData['exif']['date'] ?? '',
		]);
		$content .= $this->form->field('exif-title', [
			'field'       => 'text',
			'label'       => 'Title',
			'placeholder' => 'No Title Found',
			'value'       => $imageData['exif']['title'] ?? '',
		]);
		$content .= $this->form->field('exif-author', [
			'field'       => 'text',
			'label'       => 'Author',
			'placeholder' => 'No Autor Found',
			'class'       => 'icon-user',
			'value'       => $imageData['exif']['author'] ?? '',
		]);
		$content .= $this->form->field('exif-copyright', [
			'field'       => 'text',
			'label'       => 'Copyright',
			'placeholder' => 'No Copyright Found',
			'class'       => 'icon-copyright',
			'value'       => $imageData['exif']['copyright'] ?? '',
		]);
		$content .= $this->form->field('exif-description', [
			'field'       => 'textarea',
			'label'       => 'Description',
			'placeholder' => 'No Description Found',
			'value'       => $imageData['exif']['description'] ?? '',
			'rows'        => 3,
		]);

		return HTMLUtils::details('EXIF - Info', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function cameraFields(array $imageData): string
	{
		$content = $this->form->field('exif-make', [
			'field'       => 'text',
			'label'       => 'Make',
			'class'       => 'icon-camera',
			'placeholder' => 'Camera Make Not Found',
			'value'       => $imageData['exif']['make'] ?? '',
		]);
		$content .= $this->form->field('exif-camera', [
			'field'       => 'text',
			'label'       => 'Model',
			'placeholder' => 'Camera Model Not Found',
			'class'       => 'icon-camera',
			'value'       => $imageData['exif']['camera'] ?? '',
		]);
		$content .= $this->form->field('exif-lens', [
			'field'       => 'text',
			'label'       => 'Lens',
			'placeholder' => 'Lens Not Found',
			'class'       => 'icon-camera',
			'value'       => $imageData['exif']['lens'] ?? '',
		]);
		$content .= $this->form->field('exif-focalLength', [
			'field'       => 'number',
			'label'       => 'Focal Length',
			'placeholder' => 'Focal Length Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['focalLength'] ?? '',
		]);
		$content .= $this->form->field('exif-aperture', [
			'field'       => 'number',
			'label'       => 'Aperture',
			'placeholder' => 'Aperture Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['aperture'] ?? '',
		]);
		$content .= $this->form->field('exif-iso', [
			'field'       => 'number',
			'label'       => 'ISO',
			'placeholder' => 'ISO Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['iso'] ?? '',
		]);
		$content .= $this->form->field('exif-shutterSpeed', [
			'field'       => 'text',
			'label'       => 'Shutter Speed',
			'placeholder' => 'Shutter Speed Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['shutterSpeed'] ?? '',
		]);

		return HTMLUtils::details('EXIF - Camera', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function gpsFields(array $imageData): string
	{
		$content = $this->form->field('exif-longitude', [
			'field'       => 'text',
			'label'       => 'Longitude',
			'class'       => 'icon-gps',
			'placeholder' => 'Longitude Not Found',
			'value'       => $imageData['exif']['longitude'] ?? '',
		]);
		$content .= $this->form->field('exif-latitude', [
			'field'       => 'text',
			'label'       => 'Latitude',
			'class'       => 'icon-gps',
			'placeholder' => 'Latitude Not Found',
			'value'       => $imageData['exif']['latitude'] ?? '',
		]);
		$content .= $this->form->field('exif-altitude', [
			'field'       => 'text',
			'label'       => 'Altitude',
			'class'       => 'icon-gps',
			'placeholder' => 'Altitude Not Found',
			'value'       => $imageData['exif']['altitude'] ?? '',
		]);

		return HTMLUtils::details('EXIF - GPS', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function paletteFields(array $imageData): string
	{
		$content = $this->form->field('palette-0', [
			'field' => 'color',
			'value' => $imageData['palette'][0] ?? '',
		]);
		$content .= $this->form->field('palette-1', [
			'field' => 'color',
			'value' => $imageData['palette'][1] ?? '',
		]);
		$content .= $this->form->field('palette-2', [
			'field' => 'color',
			'value' => $imageData['palette'][2] ?? '',
		]);
		$content .= $this->form->field('palette-3', [
			'field' => 'color',
			'value' => $imageData['palette'][3] ?? '',
		]);
		$content .= $this->form->field('palette-4', [
			'field' => 'color',
			'value' => $imageData['palette'][4] ?? '',
		]);

		$palette = HTMLUtils::element('div', $content, ['class' => 'palette']);

		return HTMLUtils::details('Color Palette', $palette);
	}

	/** @param array<string,mixed> $imageData */
	private function metaFields(array $imageData): string
	{
		$content = $this->form->field('height', [
			'field'    => 'number',
			'label'    => 'Height',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['height'] ?? '',
		]);
		$content .= $this->form->field('width', [
			'field'    => 'number',
			'label'    => 'Width',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['width'] ?? '',
		]);
		$content .= $this->form->field('size', [
			'field'    => 'number',
			'label'    => 'Size',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['size'] ?? '',
		]);
		$content .= $this->form->field('name', [
			'field'    => 'text',
			'label'    => 'Filename',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['name'] ?? '',
		]);
		$content .= $this->form->field('mime', [
			'field'    => 'text',
			'label'    => 'MIME Type',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['mime'] ?? '',
		]);
		$content .= $this->form->field('uploadDate', [
			'field'    => 'datetime',
			'label'    => 'Upload Date',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['uploadDate'] ?? '',
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
