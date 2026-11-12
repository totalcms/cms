<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Service\BasicMarks;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;

// Below Pro, ImageWorks draws only the marks every edition has always had:
// one logo and one line of text. The layered marks are taken out, not refused.

it('makes layered image marks a Pro feature', function (): void {
	expect(EditionFeature::IMAGE_LAYERS->requiredEdition())->toBe(Edition::PRO)
		->and(EditionFeature::IMAGE_LAYERS->value)->toBe('image_layers');
});

it('takes out the layer list, other records\' images and color fills', function (): void {
	$params = BasicMarks::only([
		'w'            => 1200,
		'marks'        => [['marktext' => 'Layered']],
		'markimage'    => 'authors/jane/photo',
		'markshape'    => 'circle',
		'markfill'     => '0b0f1a',
		'markgradient' => 'bottom',
	]);

	expect($params)->toBe(['w' => 1200]);
});

it('keeps the one logo and line of text every edition has, tiled logos included', function (): void {
	$basic = ['mark' => 'logo.png', 'markpos' => 'tile', 'markrepeat' => 4, 'marktext' => 'Copyright', 'marktextsize' => 24, 'marktextpos' => 'bottom-right'];

	expect(BasicMarks::only($basic))->toBe($basic);
});

it('draws wrapped text on one line at its size, as before wrapping', function (): void {
	$params = BasicMarks::only(['marktext' => 'A long title', 'marktextw' => '60w', 'marktextsize' => '6w', 'marktextlines' => 2]);

	expect($params)->toBe(['marktext' => 'A long title', 'marktextsize' => '6w']);
});

it('keeps a text width on its own: that scales one line to fit', function (): void {
	expect(BasicMarks::only(['marktext' => 'Title', 'marktextw' => '25w']))->toBe(['marktext' => 'Title', 'marktextw' => '25w']);
});

it('takes the same out of a schema\'s watermark settings', function (): void {
	$params = BasicMarks::only(['w' => 800, 'marksettings' => ['mark' => 'logo.png', 'marks' => [['markfill' => '000000']], 'markfill' => '000000']]);

	expect($params)->toBe(['w' => 800, 'marksettings' => ['mark' => 'logo.png']]);
});
