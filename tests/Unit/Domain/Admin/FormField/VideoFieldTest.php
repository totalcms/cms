<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\ImageField;
use TotalCMS\Domain\Admin\FormField\VideoField;
use TotalCMS\Domain\Admin\TotalForm;

/**
 * VideoField renders a media box (the poster image sub-field as the one
 * dropzone, with the vendor thumbnail showing through when no poster is
 * uploaded) beside a URL row and five hidden derived inputs that round-trip
 * whatever the server last resolved. The poster is a real nested image
 * sub-field wired the same way a card's nested image is (single segment
 * `nestedPath`, `.card-fields` wrapper for card.js's subFields()).
 *
 * The `subField()` mock below deliberately builds a REAL ImageField (rather
 * than stubbing it away like CompositeFieldSubFieldTest does) so the upload
 * path assertion below sees genuine nested-upload markup, not a placeholder.
 */
beforeEach(function (): void {
    $this->form = $this->createMock(TotalForm::class);
    $this->form->id         = 'one';
    $this->form->collection = 'clips';
    $this->form->api        = '/api';
    $this->form->method('isEditMode')->willReturn(true);
    $this->form->method('baseApi')->willReturn('/base');
    // createMock() stubs every method (including t()) to return the declared
    // return type's default — '' for `t(): string` — rather than running
    // TotalForm's real "fall back to $default" logic. Mirror that logic here
    // so the placeholder/label strings this field renders are testable.
    $this->form->method('t')->willReturnCallback(
        fn (string $key, string $default = '', array $params = []): string => $default !== '' ? $default : $key
    );

    // Only the top-level `poster` call builds a real ImageField (so the
    // upload-path assertion below sees genuine nested markup). ImageField's
    // own build() fans out into a couple dozen further subField() calls of
    // its own (featured, alt, focalpoint-x/y, exif-*, palette-*, …) — letting
    // those recurse into more real ImageFields would nest infinitely, so they
    // get the same no-op stub CompositeFieldSubFieldTest uses.
    $this->form->method('subField')->willReturnCallback(
        function (string $name, array $options): string {
            if ($name !== 'poster') {
                return '';
            }

            $field = new ImageField(
                form: $this->form,
                name: $name,
                label: $options['label'] ?? '',
                value: is_array($options['value'] ?? null) ? $options['value'] : [],
                settings: is_array($options['settings'] ?? null) ? $options['settings'] : [],
                nestedPath: $options['nestedPath'] ?? null,
            );

            return $field->build();
        }
    );
});

it('renders the URL input carrying the stored value', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: [
        'url' => 'https://youtu.be/abc123XYZ_-',
    ]);

    $html = $field->build();

    expect($html)->toContain('type="url"')
        ->and($html)->toContain('class="video-url-input"')
        ->and($html)->toContain('name="promo[url]"')
        ->and($html)->toContain('value="https://youtu.be/abc123XYZ_-"');
});

it('renders the URL input inside a form group with the standard group icon', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: ['url' => 'https://youtu.be/abc123XYZ_-']);

    $html = $field->build();

    // Same shape as any text-style input: `.form-group > input + .form-group-icon`.
    // css/forms/video.scss masks that icon with `--icon-video`.
    expect($html)->toMatch('#<div class="form-group"><input type="url" class="video-url-input"[^>]*/><div class="form-group-icon"></div></div>#');

    // …and ONLY there: FormField::build() would otherwise append a second,
    // stray icon as a direct child of `.video-field`.
    expect(substr_count($html, 'form-group-icon'))->toBe(1);
});

it('drops the group icon when the form turns field icons off', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: ['url' => 'https://youtu.be/abc123XYZ_-'], icon: false);

    $html = $field->build();

    expect($html)->not->toContain('form-group-icon');
});

it('renders the five derived hidden inputs with their stored values', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: [
        'url'         => 'https://youtu.be/abc123XYZ_-',
        'provider'    => 'youtube',
        'videoId'     => 'abc123XYZ_-',
        'thumbnail'   => 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg',
        'title'       => 'Getting started with Total CMS',
        'aspectRatio' => '16:9',
    ]);

    $html = $field->build();

    $expected = [
        'provider'    => 'youtube',
        'videoId'     => 'abc123XYZ_-',
        'thumbnail'   => 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg',
        'title'       => 'Getting started with Total CMS',
        'aspectRatio' => '16:9',
    ];

    foreach ($expected as $key => $value) {
        expect($html)->toContain('type="hidden"')
            ->and($html)->toContain("name=\"promo[{$key}]\"")
            ->and($html)->toContain("value=\"{$value}\"");
    }
});

it('renders the poster as a real nested image field whose upload path resolves through the parent property', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: [
        'url'    => 'https://youtu.be/abc123XYZ_-',
        'poster' => ['name' => 'poster.jpg', 'size' => 500],
    ]);

    $html = $field->build();

    // The poster is wrapped in `.card-fields` — the same container class
    // CardField uses — so card.js's (inherited by video.js) subFields() scan
    // finds it.
    expect($html)->toContain('class="card-fields"');

    // ImageField's own ImageWorks preview URL is built from the dot-notation
    // property path (`nestedPath.name` = `promo.poster`), which gets
    // slash-converted for the actual URL — proving the nested upload/preview
    // path resolves through the parent `promo` property, not a bare `poster`.
    expect($html)->toContain('promo/poster');
});

it('shows the vendor thumbnail in the media box when there is no uploaded poster', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: [
        'url'       => 'https://youtu.be/abc123XYZ_-',
        'provider'  => 'youtube',
        'thumbnail' => 'https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg',
    ]);

    $html = $field->build();

    // The media box is the poster dropzone; the vendor thumbnail sits under it
    // and CSS shows it only while the poster field has no image. The chip
    // names what is showing, built from the "{provider} thumbnail" template.
    expect($html)->toContain('class="video-media has-thumbnail"')
        ->and($html)->toContain('class="video-vendor-thumbnail"')
        ->and($html)->toContain('src="https://img.youtube.com/vi/abc123XYZ_-/hqdefault.jpg"')
        ->and($html)->toContain('<span class="video-media-chip thumbnail">Youtube thumbnail</span>')
        ->and($html)->toContain('data-thumbnail-label="{provider} thumbnail"');
});

it('marks the media box has-poster and puts the poster field inside it', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: [
        'url'         => 'https://youtu.be/abc123XYZ_-',
        'aspectRatio' => '4:3',
        'poster'      => ['name' => 'poster.jpg', 'size' => 500],
    ]);

    $html = $field->build();

    expect($html)->toContain('class="video-media has-poster"')
        ->and($html)->toContain('style="--cms-video-ratio: 4 / 3"')
        ->and($html)->toContain('<span class="video-media-chip poster">Poster</span>');

    // One dropzone: the poster's `.card-fields` is a child of the media box,
    // and the box sits below the full-width URL row.
    expect(strpos($html, 'class="card-fields"'))->toBeGreaterThan(strpos($html, 'class="video-media'))
        ->and(strpos($html, 'class="video-media'))->toBeGreaterThan(strpos($html, 'class="video-details"'));
});

it('is just an empty image dropzone when there is neither a poster nor a thumbnail', function (): void {
    $field = new VideoField(form: $this->form, name: 'promo', value: [
        'url' => 'https://youtu.be/abc123XYZ_-',
    ]);

    $html = $field->build();

    expect($html)->toContain('class="video-media"')
        ->and($html)->not->toContain('video-vendor-thumbnail')
        ->and($html)->not->toContain('No preview yet')
        // The poster field's own empty dropzone markup is what shows.
        ->and($html)->toContain('class="dz-overlay dz-clickable"');
});
