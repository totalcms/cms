<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

class ImageField extends UploadField
{
	protected string $defaultFieldType = 'image';
	protected string $defaultInputType = 'image';

	public const PREVIEW_WIDTH   = 600;
	public const PREVIEW_HEIGHT  = 600;
	public const PREVIEW_QUALITY = 60;

	public function buildFormField(): string
	{
		$imageData = is_array($this->value) ? $this->value : []; // Image data is stored in the value field

		$api          = $this->form->baseApi();
		$imageworks   = ['w' => self::PREVIEW_WIDTH, 'h' => self::PREVIEW_HEIGHT, 'q' => self::PREVIEW_QUALITY];
		$options      = ['collection' => $this->form->collection, 'property' => $this->propertyPath()];
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

		return $this->dropzoneShell($previewTemplate);
	}

	protected function linkTool(): string
	{
		return 'imageworks';
	}

	protected function linkDialogClass(): string
	{
		return 'image-link-dialog';
	}

	protected function imagePreview(string $imagePath, string $alt): string
	{
		$escapedAlt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');

		$edit     = $this->esc('image.edit_info', 'Edit Image Info');
		$links    = $this->esc('image.url', 'Image URL');
		$featured = $this->esc('image.toggle_featured', 'Toggle Featured');
		$download = $this->esc('image.download_original', 'Download Original Image');
		$move     = $this->esc('image.reorder', 'Reorder Image');
		$upload   = $this->esc('image.upload_new', 'Upload New Image');
		$clear    = $this->esc('image.clear_cache', 'Clear Cache');
		$trash    = $this->esc('image.delete', 'Delete Image');

		return <<<HTML
		<div class="dz-preview dz-file-preview not-found">
			<div class="actionbar">
				<button type="button" class="edit"     title="{$edit}"></button>
				<button type="button" class="links"    title="{$links}"></button>
				<button type="button" class="featured" title="{$featured}"></button>
				<button type="button" class="download" title="{$download}"></button>
				<button type="button" class="move"     title="{$move}"></button>
				<button type="button" class="upload dz-clickable" title="{$upload}"></button>
				<button type="button" class="clear"    title="{$clear}"></button>
				<button type="button" class="trash"    title="{$trash}"></button>
			</div>
			<img src="{$imagePath}" alt="{$escapedAlt}" onload="this.parentNode.classList.remove('not-found')" oncontextmenu="return false;" draggable="false" data-dz-thumbnail />
			<div class="dz-progress">
				<span class="dz-upload" data-dz-uploadprogress></span>
				<span class="dz-upload-progress-label" data-dz-uploadprogress>0%</span>
				<div class="dz-status"></div>
			</div>
		</div>
		HTML;
	}

	/** @param array<string,mixed> $imageData */
	protected function imageDialog(string $imagePath, array $imageData): string
	{
		$content = $this->imagePreviewSection($imagePath, $imageData);
		$content .= HTMLUtils::scroller($this->dialogFields($imageData));
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

	/** @param array<string,mixed> $data */
	protected function dialogFields(array $data): string
	{
		return $this->infoFields($data)
			. $this->focalFields($data)
			. $this->exifFields($data)
			. $this->cameraFields($data)
			. $this->gpsFields($data)
			. $this->paletteFields($data)
			. $this->metaFields($data);
	}

	/** Save + Discard rather than the plain Close the file dialogs use. */
	protected function closeSection(): string
	{
		// Save keeps the edits (the field autosaves on close); Discard puts
		// every dialog field back to what it held when the dialog opened, so
		// nothing is left unsaved for a later form save to sweep up. Escape
		// discards too (the preview's onDismiss hook); a backdrop click
		// saves, like Save.
		$save    = HTMLUtils::button($this->t('btn.save', 'Save'), ['class' => 'close cms-button no-icon']);
		$discard = HTMLUtils::button($this->t('btn.discard', 'Discard Changes'), ['class' => 'cancel cms-button transparent no-icon']);

		return HTMLUtils::element('section', $save . $discard, ['class' => 'dialog-actions']);
	}

	/** @param array<string,mixed> $imageData */
	protected function infoFields(array $imageData): string
	{
		$content = $this->form->subField('featured', [
			'field' => 'checkbox',
			'label' => $this->t('image.featured_label', 'Featured'),
			'help'  => $this->t('image.featured_help', 'Mark this image as featured.'),
			'value' => $imageData['featured'] ?? false,
		]);
		$content .= $this->form->subField('alt', [
			'field'       => 'text',
			'label'       => $this->t('image.alt_label', 'Alt Text'),
			'help'        => $this->t('image.alt_help', 'Alt text is used by screen readers and search engines to describe the image.'),
			'placeholder' => $this->t('image.alt_placeholder', 'Enter Alt Text'),
			'value'       => $imageData['alt'] ?? '',
		]);
		$content .= $this->form->subField('link', [
			'field'       => 'url',
			'label'       => $this->t('image.link_label', 'Link'),
			'help'        => $this->t('image.link_help', 'Enter a URL to link the image to.'),
			'placeholder' => 'https://example.com',
			'value'       => $imageData['link'] ?? '',
		]);
		$content .= $this->form->subField('tags', $this->tagFieldSettings($imageData));

		return HTMLUtils::details($this->t('upload.section_info', 'Info'), $content);
	}

	protected function tagsHelp(): string
	{
		return $this->t('image.tags_help', 'Add tags to help organize your images.');
	}

	/** @param array<string,mixed> $imageData */
	private function focalFields(array $imageData): string
	{
		$content = $this->form->subField('focalpoint-x', [
			'field'    => 'range',
			'label'    => $this->t('image.focal_x_label', 'Focal Point X'),
			'help'     => $this->t('image.focal_x_help', 'Set the horizontal focal point coordinate of the image.'),
			'value'    => $imageData['focalpoint']['x'] ?? 50,
			'required' => false,
		]);
		$content .= $this->form->subField('focalpoint-y', [
			'field'    => 'range',
			'label'    => $this->t('image.focal_y_label', 'Focal Point Y'),
			'help'     => $this->t('image.focal_y_help', 'Set the vertical focal point coordinate of the image.'),
			'value'    => $imageData['focalpoint']['y'] ?? 50,
			'required' => false,
		]);

		return HTMLUtils::details($this->t('image.section_focal', 'Focal Point'), $content);
	}

	/** @param array<string,mixed> $imageData */
	private function exifFields(array $imageData): string
	{
		$content = $this->form->subField('exif-date', [
			'field'    => 'datetime',
			'label'    => $this->t('image.exif_date', 'Date'),
			'value'    => $imageData['exif']['date'] ?? '',
			'required' => false,
		]);
		$content .= $this->form->subField('exif-title', [
			'field'       => 'text',
			'label'       => $this->t('image.exif_title', 'Title'),
			'placeholder' => $this->t('image.exif_title_placeholder', 'No Title Found'),
			'value'       => $imageData['exif']['title'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-author', [
			'field'       => 'text',
			'label'       => $this->t('image.exif_author', 'Author'),
			'placeholder' => $this->t('image.exif_author_placeholder', 'No Author Found'),
			'class'       => 'icon-user',
			'value'       => $imageData['exif']['author'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-copyright', [
			'field'       => 'text',
			'label'       => $this->t('image.exif_copyright', 'Copyright'),
			'placeholder' => $this->t('image.exif_copyright_placeholder', 'No Copyright Found'),
			'class'       => 'icon-copyright',
			'value'       => $imageData['exif']['copyright'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-description', [
			'field'       => 'textarea',
			'label'       => $this->t('image.exif_description', 'Description'),
			'placeholder' => $this->t('image.exif_description_placeholder', 'No Description Found'),
			'value'       => $imageData['exif']['description'] ?? '',
			'rows'        => 3,
			'required'    => false,
		]);

		return HTMLUtils::details($this->t('image.section_exif', 'EXIF - Info'), $content);
	}

	/** @param array<string,mixed> $imageData */
	private function cameraFields(array $imageData): string
	{
		$content = $this->form->subField('exif-make', [
			'field'       => 'text',
			'label'       => $this->t('image.camera_make', 'Make'),
			'class'       => 'icon-camera',
			'placeholder' => $this->t('image.camera_make_placeholder', 'Camera Make Not Found'),
			'value'       => $imageData['exif']['make'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-camera', [
			'field'       => 'text',
			'label'       => $this->t('image.camera_model', 'Model'),
			'placeholder' => $this->t('image.camera_model_placeholder', 'Camera Model Not Found'),
			'class'       => 'icon-camera',
			'value'       => $imageData['exif']['camera'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-lens', [
			'field'       => 'text',
			'label'       => $this->t('image.camera_lens', 'Lens'),
			'placeholder' => $this->t('image.camera_lens_placeholder', 'Lens Not Found'),
			'class'       => 'icon-camera',
			'value'       => $imageData['exif']['lens'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-focalLength', [
			'field'       => 'number',
			'label'       => $this->t('image.camera_focal_length', 'Focal Length'),
			'placeholder' => $this->t('image.camera_focal_length_placeholder', 'Focal Length Not Found'),
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['focalLength'] ?? '',
			// Whatever the camera reported: f/2.69, 4.25mm. A fixed step makes
			// the browser refuse the value EXIF just wrote, on every save.
			'step'        => 'any',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-aperture', [
			'field'       => 'number',
			'label'       => $this->t('image.camera_aperture', 'Aperture'),
			'placeholder' => $this->t('image.camera_aperture_placeholder', 'Aperture Not Found'),
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['aperture'] ?? '',
			// Whatever the camera reported: f/2.69, 4.25mm. A fixed step makes
			// the browser refuse the value EXIF just wrote, on every save.
			'step'        => 'any',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-iso', [
			'field'       => 'number',
			'label'       => $this->t('image.camera_iso', 'ISO'),
			'placeholder' => $this->t('image.camera_iso_placeholder', 'ISO Not Found'),
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['iso'] ?? '',
			// Whatever the camera reported: f/2.69, 4.25mm. A fixed step makes
			// the browser refuse the value EXIF just wrote, on every save.
			'step'        => 'any',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-shutterSpeed', [
			'field'       => 'text',
			'label'       => $this->t('image.camera_shutter', 'Shutter Speed'),
			'placeholder' => $this->t('image.camera_shutter_placeholder', 'Shutter Speed Not Found'),
			'class'       => 'icon-shutter',
			'value'       => $imageData['exif']['shutterSpeed'] ?? '',
			'required'    => false,
		]);

		return HTMLUtils::details($this->t('image.section_camera', 'EXIF - Camera'), $content);
	}

	/** @param array<string,mixed> $imageData */
	private function gpsFields(array $imageData): string
	{
		$content = $this->form->subField('exif-country', [
			'field'       => 'text',
			'label'       => $this->t('image.location_country', 'Country'),
			'class'       => 'icon-gps',
			'placeholder' => $this->t('image.location_country_placeholder', 'Country Not Found'),
			'value'       => $imageData['exif']['country'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-state', [
			'field'       => 'text',
			'label'       => $this->t('image.location_state', 'State/Province'),
			'class'       => 'icon-gps',
			'placeholder' => $this->t('image.location_state_placeholder', 'State or Province Not Found'),
			'value'       => $imageData['exif']['state'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-city', [
			'field'       => 'text',
			'label'       => $this->t('image.location_city', 'City'),
			'class'       => 'icon-gps',
			'placeholder' => $this->t('image.location_city_placeholder', 'City Not Found'),
			'value'       => $imageData['exif']['city'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-sublocation', [
			'field'       => 'text',
			'label'       => $this->t('image.location_sublocation', 'Sub-Location'),
			'class'       => 'icon-gps',
			'placeholder' => $this->t('image.location_sublocation_placeholder', 'Sub-Location Not Found'),
			'value'       => $imageData['exif']['sublocation'] ?? '',
			'required'    => false,
		]);

		$content .= HTMLUtils::inlineElement('hr');

		$content .= $this->form->subField('exif-longitude', [
			'field'       => 'text',
			'label'       => $this->t('image.location_longitude', 'Longitude'),
			'class'       => 'icon-gps',
			'placeholder' => $this->t('image.location_longitude_placeholder', 'Longitude Not Found'),
			'value'       => $imageData['exif']['longitude'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-latitude', [
			'field'       => 'text',
			'label'       => $this->t('image.location_latitude', 'Latitude'),
			'class'       => 'icon-gps',
			'placeholder' => $this->t('image.location_latitude_placeholder', 'Latitude Not Found'),
			'value'       => $imageData['exif']['latitude'] ?? '',
			'required'    => false,
		]);
		$content .= $this->form->subField('exif-altitude', [
			'field'       => 'text',
			'label'       => $this->t('image.location_altitude', 'Altitude'),
			'class'       => 'icon-gps',
			'placeholder' => $this->t('image.location_altitude_placeholder', 'Altitude Not Found'),
			'value'       => $imageData['exif']['altitude'] ?? '',
			'required'    => false,
		]);

		return HTMLUtils::details($this->t('image.section_location', 'EXIF - Location'), $content);
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

		return HTMLUtils::details($this->t('image.section_palette', 'Color Palette'), $palette);
	}

	/** Image meta: dimensions first, no extension or download count. @param array<string,mixed> $data */
	protected function metaFields(array $data): string
	{
		$content = $this->readonlyField('height', $this->t('image.height_label', 'Height'), $data['height'] ?? '', 'number');
		$content .= $this->readonlyField('width', $this->t('image.width_label', 'Width'), $data['width'] ?? '', 'number');
		$content .= $this->readonlyField('size', $this->t('upload.size', 'Size'), $data['size'] ?? '', 'number');
		$content .= $this->readonlyField('name', $this->t('upload.filename_label', 'Filename'), $data['name'] ?? '');
		$content .= $this->readonlyField('mime', $this->t('upload.mime_label', 'MIME Type'), $data['mime'] ?? '');
		$content .= $this->readonlyField('uploadDate', $this->t('upload.upload_date_label', 'Upload Date'), $data['uploadDate'] ?? '', 'datetime');

		return HTMLUtils::details($this->t('upload.section_meta', 'Meta (Readonly)'), $content);
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
