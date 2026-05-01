<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

class ImageField extends FormField
{
	protected string $defaultFieldType = 'image';
	protected string $defaultInputType = 'image';

	public const PREVIEW_WIDTH   = 600;
	public const PREVIEW_HEIGHT  = 600;
	public const PREVIEW_QUALITY = 60;

	public function init(): void
	{
		parent::init();

		$this->icon = false; // No icon for image fields
	}

	public function buildFormField(): string
	{
		$imageData = is_array($this->value) ? $this->value : []; // Image data is stored in the value field

		$api        = $this->form->baseApi();
		$imageworks = ['w' => self::PREVIEW_WIDTH, 'h' => self::PREVIEW_HEIGHT, 'q' => self::PREVIEW_QUALITY];
		// Dot-notation path: `mycard.image` for a card child, `mydeck.item-3.image`
		// for a deck child, `image` for top-level. `nestedPath` is the prefix.
		$propertyPath = $this->nestedPath !== null ? "{$this->nestedPath}.{$this->name}" : $this->name;
		$options      = ['collection' => $this->form->collection, 'property' => $propertyPath];
		$id           = $this->form->id;

		$imagePath = MediaTwigAdapter::buildImageworksAPI($api, $id, $imageData, $imageworks, $options);

		$previewAttrs = ['class' => 'image-preview'];
		if ($imageData['featured'] ?? false) {
			$previewAttrs['class'] .= ' featured';
		}
		$imagePreview = $this->imagePreview($imagePath, $imageData['name'] ?? '');
		$linkDialog   = $this->linkDialog();
		$imageDialog  = $this->imageDialog($imagePath, $imageData);

		$previewTemplate = HTMLUtils::element('div', $imagePreview . $imageDialog . $linkDialog, $previewAttrs);

		$inputAttrs = [
			'id'       => 'field-' . $this->uuid,
			'type'     => 'text',
			'name'     => $this->name,
			'required' => $this->required ? '' : null,
		];
		$inputAttrs = array_filter($inputAttrs, fn (?string $x): bool => !is_null($x));

		$input    = HTMLUtils::inlineElement('input', $inputAttrs);
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
		//
		// For nested images, `property` is a dot-notation path (e.g. `mycard.image`
		// or `mydeck.item-3.image`) so the imageworks utility can resolve the
		// nested image and the macro builder emits the correct Twig syntax.
		$propertyPath = $this->nestedPath !== null ? "{$this->nestedPath}.{$this->name}" : $this->name;
		$query = http_build_query(array_filter([
			'id'         => $this->form->id,
			'collection' => $this->form->collection,
			'property'   => $propertyPath,
			'name'       => $name,
		], fn ($v): bool => $v !== null && $v !== ''));
		// 	The cms.api may have a ? because of the Stacks Preview server
		$join = str_contains($this->form->api, '?') ? '&' : '?';

		$iframe = HTMLUtils::iframe("{$this->form->baseApi()}/admin/imageworks{$join}{$query}");

		return HTMLUtils::dialog($iframe, 'image-link-dialog');
	}

	/** @param array<string,mixed> $imageData */
	protected function imageDialog(string $imagePath, array $imageData): string
	{
		$content = $this->imagePreviewSection($imagePath, $imageData);
		$content .= $this->imageFieldsSection($imageData);
		$content .= $this->closeSection();

		return HTMLUtils::dialog($content, 'split-view image-edit-dialog');
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

		// Lock the section's aspect ratio to the image's so any height clamp
		// (e.g., max-height on mobile) shrinks width proportionally — keeping
		// section dimensions = image dimensions, which is required for the
		// focal-point overlay's percentage coordinates to land accurately.
		$sectionAttrs = ['class' => 'image-preview'];
		$width        = (int)($imageData['width'] ?? 0);
		$height       = (int)($imageData['height'] ?? 0);
		if ($width > 0 && $height > 0) {
			$sectionAttrs['style'] = "aspect-ratio: {$width}/{$height};";
		}

		return HTMLUtils::element('section', $image . $fpoint, $sectionAttrs);
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
		$content = $this->form->subField('featured', [
			'field'    => 'checkbox',
			'label'    => 'Featured',
			'help'     => 'Mark this image as featured.',
			'value'    => $imageData['featured'] ?? false,
			'required' => false,
		]);
		$content .= $this->form->subField('alt', [
			'field'       => 'text',
			'label'       => 'Alt Text',
			'help'        => 'Alt text is used by screen readers and search engines to describe the image.',
			'placeholder' => 'Enter Alt Text',
			'value'       => $imageData['alt'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('link', [
			'field'       => 'url',
			'label'       => 'Link',
			'help'        => 'Enter a URL to link the image to.',
			'placeholder' => 'https://example.com',
			'value'       => $imageData['link'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('tags', [
			'field'       => 'list',
			'label'       => 'Tags',
			'help'        => 'Add tags to help organize your images.',
			'placeholder' => 'Add Tags',
			'value'       => $imageData['tags'] ?? [],
			'required'    => false,
		]);

		return HTMLUtils::details('Info', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function focalFields(array $imageData): string
	{
		$content = $this->form->subField('focalpoint-x', [
			'field'    => 'range',
			'label'    => 'Focal Point X',
			'help'     => 'Set the horizontal focal point coordinate of the image.',
			'value'    => $imageData['focalpoint']['x'] ?? 50,
			'required' => false,
		]);
		$content .= $this->form->subField('focalpoint-y', [
			'field'    => 'range',
			'label'    => 'Focal Point Y',
			'help'     => 'Set the vertical focal point coordinate of the image.',
			'value'    => $imageData['focalpoint']['y'] ?? 50,
			'required' => false,
		]);

		return HTMLUtils::details('Focal Point', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function exifFields(array $imageData): string
	{
		$content = $this->form->subField('exif-date', [
			'field'    => 'datetime',
			'label'    => 'Date',
			'value'    => $imageData['exif']['date'] ?? '',
			'required' => false,
		]);
		$content .= $this->form->subField('exif-title', [
			'field'       => 'text',
			'label'       => 'Title',
			'placeholder' => 'No Title Found',
			'value'       => $imageData['exif']['title'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-author', [
			'field'       => 'text',
			'label'       => 'Author',
			'placeholder' => 'No Autor Found',
			'class'       => 'icon-user',
			'value'       => $imageData['exif']['author'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-copyright', [
			'field'       => 'text',
			'label'       => 'Copyright',
			'placeholder' => 'No Copyright Found',
			'class'       => 'icon-copyright',
			'value'       => $imageData['exif']['copyright'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-description', [
			'field'       => 'textarea',
			'label'       => 'Description',
			'placeholder' => 'No Description Found',
			'value'       => $imageData['exif']['description'] ?? '',
			'rows'        => 3,
			'required'    => false,
		]);

		return HTMLUtils::details('EXIF - Info', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function cameraFields(array $imageData): string
	{
		$content = $this->form->subField('exif-make', [
			'field'       => 'text',
			'label'       => 'Make',
			'class'       => 'icon-camera',
			'placeholder' => 'Camera Make Not Found',
			'value'       => $imageData['exif']['make'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-camera', [
			'field'       => 'text',
			'label'       => 'Model',
			'placeholder' => 'Camera Model Not Found',
			'class'       => 'icon-camera',
			'value'       => $imageData['exif']['camera'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-lens', [
			'field'       => 'text',
			'label'       => 'Lens',
			'placeholder' => 'Lens Not Found',
			'class'       => 'icon-camera',
			'value'       => $imageData['exif']['lens'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-focalLength', [
			'field'       => 'number',
			'label'       => 'Focal Length',
			'placeholder' => 'Focal Length Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['focalLength'] ?? '',
			'step'        => '0.1',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-aperture', [
			'field'       => 'number',
			'label'       => 'Aperture',
			'placeholder' => 'Aperture Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['aperture'] ?? '',
			'step'        => '0.1',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-iso', [
			'field'       => 'number',
			'label'       => 'ISO',
			'placeholder' => 'ISO Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['iso'] ?? '',
			'step'        => '0.1',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-shutterSpeed', [
			'field'       => 'text',
			'label'       => 'Shutter Speed',
			'placeholder' => 'Shutter Speed Not Found',
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['shutterSpeed'] ?? '',
			'required'    => false,
		]);

		return HTMLUtils::details('EXIF - Camera', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function gpsFields(array $imageData): string
	{
		$content = $this->form->subField('exif-country', [
			'field'       => 'text',
			'label'       => 'Country',
			'class'       => 'icon-gps',
			'placeholder' => 'Country Not Found',
			'value'       => $imageData['exif']['country'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-state', [
			'field'       => 'text',
			'label'       => 'State/Province',
			'class'       => 'icon-gps',
			'placeholder' => 'State or Province Not Found',
			'value'       => $imageData['exif']['state'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-city', [
			'field'       => 'text',
			'label'       => 'City',
			'class'       => 'icon-gps',
			'placeholder' => 'City Not Found',
			'value'       => $imageData['exif']['city'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-sublocation', [
			'field'       => 'text',
			'label'       => 'Sub-Location',
			'class'       => 'icon-gps',
			'placeholder' => 'Sub-Location Not Found',
			'value'       => $imageData['exif']['sublocation'] ?? '',
			'required'    => false,
		]);

		$content .= HTMLUtils::inlineElement('hr');

		$content .= $this->form->subField('exif-longitude', [
			'field'       => 'text',
			'label'       => 'Longitude',
			'class'       => 'icon-gps',
			'placeholder' => 'Longitude Not Found',
			'value'       => $imageData['exif']['longitude'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-latitude', [
			'field'       => 'text',
			'label'       => 'Latitude',
			'class'       => 'icon-gps',
			'placeholder' => 'Latitude Not Found',
			'value'       => $imageData['exif']['latitude'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-altitude', [
			'field'       => 'text',
			'label'       => 'Altitude',
			'class'       => 'icon-gps',
			'placeholder' => 'Altitude Not Found',
			'value'       => $imageData['exif']['altitude'] ?? '',
			'required'    => false,
		]);

		return HTMLUtils::details('EXIF - Location', $content);
	}

	/** @param array<string,mixed> $imageData */
	private function paletteFields(array $imageData): string
	{
		$content = $this->form->subField('palette-0', [
			'field'    => 'color',
			'value'    => $imageData['palette'][0] ?? '',
			'required' => false,
		]);
		$content .= $this->form->subField('palette-1', [
			'field'    => 'color',
			'value'    => $imageData['palette'][1] ?? '',
			'required' => false,
		]);
		$content .= $this->form->subField('palette-2', [
			'field'    => 'color',
			'value'    => $imageData['palette'][2] ?? '',
			'required' => false,
		]);
		$content .= $this->form->subField('palette-3', [
			'field'    => 'color',
			'value'    => $imageData['palette'][3] ?? '',
			'required' => false,
		]);
		$content .= $this->form->subField('palette-4', [
			'field'    => 'color',
			'value'    => $imageData['palette'][4] ?? '',
			'required' => false,
		]);

		$palette = HTMLUtils::element('div', $content, ['class' => 'palette']);

		return HTMLUtils::details('Color Palette', $palette);
	}

	/** @param array<string,mixed> $imageData */
	private function metaFields(array $imageData): string
	{
		$content = $this->form->subField('height', [
			'field'    => 'number',
			'label'    => 'Height',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['height'] ?? '',
		]);
		$content .= $this->form->subField('width', [
			'field'    => 'number',
			'label'    => 'Width',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['width'] ?? '',
		]);
		$content .= $this->form->subField('size', [
			'field'    => 'number',
			'label'    => 'Size',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['size'] ?? '',
		]);
		$content .= $this->form->subField('name', [
			'field'    => 'text',
			'label'    => 'Filename',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['name'] ?? '',
		]);
		$content .= $this->form->subField('mime', [
			'field'    => 'text',
			'label'    => 'MIME Type',
			'icon'     => false,
			'readonly' => true,
			'value'    => $imageData['mime'] ?? '',
		]);
		$content .= $this->form->subField('uploadDate', [
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
