<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Service\VideoRenderer;

/**
 * Video Form Field — an external video: an author-pasted URL plus provider
 * metadata derived on save (see `PropertyDataProcessor`'s video branch) and an
 * optional uploaded poster image.
 *
 * The stored shape is fixed by `VideoData` (not by a sub-schema): `url`,
 * `provider`, `videoId`, `thumbnail`, `title`, `aspectRatio`, and an optional
 * `poster`. Only `url` and `poster` are ever hand-edited here — the rest are
 * hidden inputs that round-trip the server-derived values and are re-blanked
 * client-side (video.js) whenever the URL changes, so a save always re-derives
 * them.
 *
 * Renders with card-like markup: a hidden proxy marker carries this field's own
 * name (so `TotalField.property` / `getUploadContext()` resolve to the video
 * property itself, not to one of the inputs below it), and the poster sub-field
 * is wrapped in a `.card-fields` container so `CardField.subFields()` (which
 * `VideoField` still extends on the JS side, purely for that sub-field
 * plumbing) finds it. That is a markup convention only — no card schema is
 * consulted on either side.
 */
class VideoField extends FormField
{
	protected string $defaultFieldType = 'video';
	protected string $defaultInputType = 'video';

	public function init(): void
	{
		$this->uuid      = uniqid();
		$this->field     = $this->defaultFieldType;
		$this->inputType = $this->defaultInputType;
		// The URL input keeps the standard group icon (`--icon-video`) like any
		// other text-style input; the form's `fieldIcons` option still turns it
		// off through the inherited `$icon` flag.

		if ($this->hide || (isset($this->settings['hide']) && $this->settings['hide'] === true)) {
			$this->class = trim($this->class . ' cms-hide');
		}
	}

	public function buildFormField(): string
	{
		$video = is_array($this->value) ? $this->value : [];

		$url         = (string)($video['url'] ?? '');
		$provider    = (string)($video['provider'] ?? '');
		$videoId     = (string)($video['videoId'] ?? '');
		$thumbnail   = (string)($video['thumbnail'] ?? '');
		$title       = (string)($video['title'] ?? '');
		$aspectRatio = (string)($video['aspectRatio'] ?? '16:9');
		$poster      = is_array($video['poster'] ?? null) ? $video['poster'] : [];

		// Hidden proxy marker — gives this composite field a plain, unbracketed
		// name so TotalField reads `promo` (not one of the inputs below) as its
		// own property. Mirrors CardField::buildFormField().
		$marker = $this->proxyInput([
			'id'    => 'field-' . $this->uuid,
			'type'  => 'hidden',
			'name'  => $this->name,
			'value' => $this->name,
		]);

		// The URL input sits in its own `.form-group` (with the group icon when
		// icons are on) so it looks like every other input; the provider badge
		// sits beside that group on the same row.
		$urlIcon  = $this->icon ? HTMLUtils::element('div', '', ['class' => 'form-group-icon']) : '';
		$urlGroup = HTMLUtils::element('div', $this->urlInput($url) . $urlIcon, ['class' => 'form-group']);
		$urlRow   = HTMLUtils::element('div', $urlGroup . $this->providerBadge($provider), [
			'class' => 'video-url',
		]);

		$hidden = $this->hiddenField('provider', $provider)
			. $this->hiddenField('videoId', $videoId)
			. $this->hiddenField('thumbnail', $thumbnail)
			. $this->hiddenField('title', $title)
			. $this->hiddenField('aspectRatio', $aspectRatio);

		// Stacked: the full-width URL row and its metadata on top, the media box
		// (poster dropzone showing the poster, else the vendor thumbnail, else a
		// placeholder) below it.
		$details = HTMLUtils::element('div', $urlRow . $this->titleBadge($title) . $hidden, ['class' => 'video-details']);
		$media   = $this->buildMedia($poster, $thumbnail, $provider, $aspectRatio);

		return $marker . HTMLUtils::element('div', $details . $media, ['class' => 'video-layout']);
	}

	private function urlInput(string $url): string
	{
		return HTMLUtils::inlineElement('input', [
			'type'           => 'url',
			'class'          => 'video-url-input',
			'name'           => "{$this->name}[url]",
			'value'          => $url,
			'autocapitalize' => 'off',
			'spellcheck'     => 'false',
			'placeholder'    => $this->t('video.url_placeholder', 'Paste a YouTube, Vimeo, Livid… URL'),
		]);
	}

	private function providerBadge(string $provider): string
	{
		return HTMLUtils::element('span', htmlspecialchars($provider, ENT_QUOTES, 'UTF-8'), ['class' => 'video-provider']);
	}

	private function titleBadge(string $title): string
	{
		return HTMLUtils::element('span', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), ['class' => 'video-title']);
	}

	private function hiddenField(string $key, string $value): string
	{
		return HTMLUtils::inlineElement('input', [
			'type'  => 'hidden',
			'class' => "video-{$key}-input",
			'name'  => "{$this->name}[{$key}]",
			'value' => $value,
		]);
	}

	/**
	 * The media box: one dropzone that IS the poster image sub-field, filling a
	 * box in the stored aspect ratio. When no poster is uploaded the vendor
	 * thumbnail shows through underneath (with the drop target's upload arrow
	 * over it), and when there is neither, the box is simply an empty image
	 * dropzone. A corner chip names what is showing — "Poster" or "YouTube
	 * thumbnail" — so an editor can tell an upload from the provider's default
	 * at a glance.
	 *
	 * Which layer is visible is decided in CSS from live DOM state (`:has()` on
	 * the poster's own `.dz-preview.not-found`), so an upload, a delete, or a
	 * URL change (video.js blanks the thumbnail) all switch the box without
	 * this markup being rebuilt.
	 *
	 * @param array<string,mixed> $poster
	 */
	private function buildMedia(array $poster, string $thumbnail, string $provider, string $aspectRatio): string
	{
		$posterLabel    = $this->t('video.poster', 'Poster');
		$thumbnailLabel = $this->t('video.thumbnail_chip', '{provider} thumbnail');

		$vendor = '';
		if ($thumbnail !== '') {
			$vendor = HTMLUtils::inlineElement('img', [
				'class'         => 'video-vendor-thumbnail',
				'src'           => $thumbnail,
				'alt'           => '',
				'oncontextmenu' => 'return false;',
				'draggable'     => 'false',
			]);
		}

		$posterField = $this->form->subField('poster', [
			'field'        => 'image',
			'label'        => $posterLabel,
			'settings'     => is_array($this->settings['poster'] ?? null) ? $this->settings['poster'] : [],
			'value'        => $poster,
			// Single-segment nestedPath: the poster's upload URL and ImageWorks
			// property path resolve to `{video-property}.poster`, exactly as a
			// card child image does — see CardField::buildSubFields(). No
			// `card_context` marker: `subField()` already sets `subfield: true`,
			// which is what stops ObjectForm::buildFieldOptions() looking up a
			// top-level schema property named `poster`.
			'nestedPath'   => $this->name,
		]);
		// `.card-fields` is the wrapper card.js's subFields() scans for; video.js
		// inherits that scan unchanged.
		$posterField = HTMLUtils::element('div', $posterField, ['class' => 'card-fields']);

		$chips = HTMLUtils::element('span', $posterLabel, ['class' => 'video-media-chip poster'])
			. HTMLUtils::element('span', self::thumbnailChipText($thumbnailLabel, $provider), ['class' => 'video-media-chip thumbnail']);

		$class = 'video-media';
		if ($thumbnail !== '') {
			$class .= ' has-thumbnail';
		}
		if ((string)($poster['name'] ?? '') !== '' && (int)($poster['size'] ?? 0) > 0) {
			$class .= ' has-poster';
		}

		return HTMLUtils::element('div', $vendor . $posterField . $chips, [
			'class'                => $class,
			'style'                => '--cms-video-ratio: ' . VideoRenderer::aspectRatioStyle($aspectRatio),
			// Carried as a data attribute (rather than baked into JS) so video.js
			// can rebuild the chip after a URL change without hard-coding English.
			'data-thumbnail-label' => $thumbnailLabel,
		]);
	}

	/** "{provider} thumbnail" with the provider id capitalised — mirrored in video.js. */
	public static function thumbnailChipText(string $template, string $provider): string
	{
		return str_replace('{provider}', ucfirst($provider), $template);
	}

	/**
	 * Composite fields render their own layout (URL row, preview, nested poster
	 * field) directly against `.form-field`, so skip the default `.form-group`
	 * wrapper the same way CardField does. The poster's `.card-fields` sits
	 * inside `.video-media`; `card.js`'s `subFields()` finds it by `closest()`,
	 * so depth does not matter. The outer
	 * `.form-field.video-field` wrapper (from `buildFieldAttributes()`) already
	 * provides the container class this field's CSS and JS key off of.
	 */
	public function createFormGroup(string $content): string
	{
		return $content;
	}

	/**
	 * FormField::build() appends the group icon after the field's own markup;
	 * with no `.form-group` wrapper here that would land a stray icon as a
	 * direct child of `.video-field`. The URL row already renders its own icon
	 * inside its `.form-group` (see buildFormField()), so build without it.
	 */
	public function build(): string
	{
		return $this->createFormField($this->createFormGroup($this->buildFormField()));
	}
}
