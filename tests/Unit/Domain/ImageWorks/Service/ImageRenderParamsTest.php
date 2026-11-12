<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\ImageWorks\Service\ImageRenderParams;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Support\Config;

// What an ImageWorks request asks for versus what Glide renders. The
// watermark rules matter most: a property's watermark is enforced over any
// URL param, except below its size limit.

/** @param array<string,mixed> $watermark */
function renderParamsWith(array $watermark = [], array $imageworks = []): ImageRenderParams
{
	$meta = test()->createMock(PropertyMetaResolver::class);
	$meta->method('resolveSettings')->willReturn($watermark === [] ? [] : ['watermark' => $watermark]);

	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = $imageworks;

	return new ImageRenderParams($meta, $config);
}

function renderParamsImage(): ImageData
{
	return new ImageData(['name' => 'photo.jpg', 'width' => 1200, 'height' => 800]);
}

function renderParamsLocation(): ImageLocation
{
	return new ImageLocation('blog', 'post-1', 'image');
}

test('only the source format means the original file', function (): void {
	expect(renderParamsWith()->clean(['fm' => 'jpg'], renderParamsImage(), renderParamsLocation()))->toBe([])
		->and(renderParamsWith()->clean(['fm' => 'webp'], renderParamsImage(), renderParamsLocation()))->toBe(['fm' => 'webp']);
});

test('sizes are capped at the source and 0 drops the size', function (): void {
	expect(renderParamsWith()->clean(['w' => 5000, 'h' => 0, 'fm' => 'jpg'], renderParamsImage(), renderParamsLocation()))
		->toBe(['w' => 1200, 'fm' => 'jpg']);
});

test('a preset fills in params and explicit params win', function (): void {
	$params = renderParamsWith(imageworks: ['presets' => ['thumb' => ['w' => 300, 'q' => 60]]])
		->clean(['p' => 'thumb', 'q' => 90], renderParamsImage(), renderParamsLocation());

	expect($params)->toBe(['w' => 300, 'q' => 90]);
});

test('the property watermark is kept apart from the URL marks', function (): void {
	$params = renderParamsWith(['marktext' => '© Studio'])
		->clean(['w' => 600, 'marktext' => 'mine now'], renderParamsImage(), renderParamsLocation());

	expect($params['marksettings'])->toBe(['marktext' => '© Studio'])
		->and($params['marktext'])->toBe('mine now');
});

test('below the watermark limit the image is left unmarked', function (): void {
	$marks = ['marktext' => '© Studio', 'limit' => 400];

	expect(renderParamsWith($marks)->clean(['w' => 300], renderParamsImage(), renderParamsLocation()))->toBe(['w' => 300])
		->and(renderParamsWith($marks)->clean(['w' => 600], renderParamsImage(), renderParamsLocation()))->toBe(['w' => 600, 'marksettings' => ['marktext' => '© Studio']]);
});

test('withoutWatermarks drops every mark param', function (): void {
	expect(ImageRenderParams::withoutWatermarks(['w' => 300, 'mark' => 'logo.png', 'markw' => '100w', 'marktext' => 'x', 'marks' => [['mark' => 'a.png']], 'marksettings' => ['marktext' => 'y']]))
		->toBe(['w' => 300]);
});
