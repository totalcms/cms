<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\ImageWorks\Service\ImageRenderParams;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Support\Config;

// What an ImageWorks request asks for versus what Glide renders. The
// watermark rules matter most: a property's watermark is enforced over any
// URL param, except below its size limit.

/** @param array<string,mixed> $watermark */
function renderParamsWith(array $watermark = [], array $imageworks = [], Edition $edition = Edition::PRO): ImageRenderParams
{
	$meta = test()->createMock(PropertyMetaResolver::class);
	$meta->method('resolveSettings')->willReturn($watermark === [] ? [] : ['watermark' => $watermark]);

	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = $imageworks;

	return new ImageRenderParams($meta, $config, editionsFor($edition), imageFilterRegistry($config));
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

test('below Pro the layered marks are taken out, from the URL and the watermark', function (): void {
	$watermark = ['mark' => 'logo.png', 'marks' => [['markfill' => '000000']]];
	$url       = ['w' => 600, 'marks' => [['marktext' => 'Layer']], 'markfill' => '0b0f1a', 'marktext' => 'Title', 'marktextw' => '60w', 'marktextsize' => 40];

	$standard = renderParamsWith($watermark, [], Edition::STANDARD)->clean($url, renderParamsImage(), renderParamsLocation());
	$pro      = renderParamsWith($watermark)->clean($url, renderParamsImage(), renderParamsLocation());

	expect($standard)->toBe(['w' => 600, 'marktext' => 'Title', 'marktextsize' => 40, 'marksettings' => ['mark' => 'logo.png']])
		->and($pro['marks'])->toBe([['marktext' => 'Layer']])
		->and($pro['marksettings'])->toBe($watermark);
});


/**
 * @param array<string,mixed> $params
 * @param array<string,mixed> $imageworks
 * @param list<string>        $palette
 *
 * @return array<string,mixed>
 */
function cleanFiltered(array $params, array $imageworks = [], array $palette = []): array
{
	$image = new ImageData(['name' => 'photo.jpg', 'width' => 1200, 'height' => 800, 'palette' => $palette]);

	return renderParamsWith(imageworks: $imageworks)->clean($params + ['w' => 300, 'fm' => 'jpg'], $image, renderParamsLocation());
}

describe('filter params', function (): void {
	it('drops an unknown filter and matches the unfiltered params', function (): void {
		expect(cleanFiltered(['filt' => 'nosuch', 'filtamt' => 50, 'duo' => 'a,b']))->toBe(cleanFiltered([]));
	});

	it('keeps known names and adds the cache version', function (): void {
		$params = cleanFiltered(['filt' => 'nosuch,ember']);
		expect($params['filt'])->toBe('ember')
			->and($params['filtv'])->toBeString()->not->toBe('');
	});

	it('keeps the first three known names and lowercases', function (): void {
		$params = cleanFiltered(['filt' => 'noir,EMBER,vignette,duotone,sepia']);
		expect($params['filt'])->toBe('noir,ember,vignette');
		expect(cleanFiltered(['filt' => 'EMBER'])['filt'])->toBe('ember');
	});

	it('drops filtamt of 100 and clamps the rest', function (): void {
		expect(cleanFiltered(['filt' => 'ember', 'filtamt' => 100]))->not->toHaveKey('filtamt');
		expect(cleanFiltered(['filt' => 'ember', 'filtamt' => 250]))->not->toHaveKey('filtamt');
		expect(cleanFiltered(['filt' => 'ember', 'filtamt' => 60])['filtamt'])->toBe(60);
	});

	it('drops duo unless duotone is in the chain', function (): void {
		expect(cleanFiltered(['filt' => 'ember', 'duo' => '111111,222222']))->not->toHaveKey('duo');
	});

	it('fills duo for duotone from the palette', function (): void {
		expect(cleanFiltered(['filt' => 'duotone'], palette: ['336699'])['duo'])->toBe('000000,336699');
	});

	it('falls back to white with no palette', function (): void {
		expect(cleanFiltered(['filt' => 'duotone'])['duo'])->toBe('000000,ffffff');
	});

	it('replaces an incoming filtv', function (): void {
		$params = cleanFiltered(['filt' => 'ember', 'filtv' => 'x']);
		expect($params['filtv'])->not->toBe('x');
		expect(cleanFiltered(['filtv' => 'x']))->not->toHaveKey('filtv');
	});

	it('gives greyscale no version', function (): void {
		$params = cleanFiltered(['filt' => 'greyscale']);
		expect($params['filt'])->toBe('greyscale')->and($params)->not->toHaveKey('filtv');
	});

	it('applies a default filter as if it were in the URL', function (): void {
		$params = cleanFiltered([], ['defaults' => ['filt' => 'noir']]);
		expect($params['filt'])->toBe('noir')->and($params['filtv'])->toBeString();
	});

	it('falls back to the default filter when every URL name is dropped', function (): void {
		$params = cleanFiltered(['filt' => 'nosuch'], ['defaults' => ['filt' => 'noir']]);
		expect($params['filt'])->toBe('noir')->and($params['filtv'])->toBeString();
	});

	it('serves the original for an unknown-only filter on the source format', function (): void {
		expect(cleanFiltered(['filt' => 'nosuch', 'w' => 0], []))->toBe([]);
		expect(renderParamsWith()->clean(['fm' => 'jpg', 'filt' => 'nosuch'], renderParamsImage(), renderParamsLocation()))->toBe([]);
	});

	it('resolves a comma preset list before cleaning filters', function (): void {
		$imageworks = ['presets' => ['look' => ['filt' => 'ember', 'filtamt' => 100], 'thumb' => ['w' => 100]]];
		$params     = renderParamsWith(imageworks: $imageworks)->clean(['p' => 'look,thumb', 'fm' => 'jpg'], renderParamsImage(), renderParamsLocation());

		expect($params)->not->toHaveKey('p')->not->toHaveKey('filtamt')
			->and($params['filt'])->toBe('ember')
			->and($params['filtv'])->toBeString()
			->and($params['w'])->toBe(100);
	});

	it('trims duo colors and falls back when it is not a pair', function (): void {
		expect(cleanFiltered(['filt' => 'duotone', 'duo' => ' 111111 , 222222 '])['duo'])->toBe('111111,222222');
		expect(cleanFiltered(['filt' => 'duotone', 'duo' => 'red'])['duo'])->toBe('000000,ffffff');
		expect(cleanFiltered(['filt' => 'duotone', 'duo' => 'a,b,c'])['duo'])->toBe('000000,ffffff');
	});

	it('treats a strength of 0 as no filter', function (): void {
		expect(cleanFiltered(['filt' => 'ember', 'filtamt' => 0]))->toBe(cleanFiltered([]));
		expect(cleanFiltered(['filt' => 'duotone', 'filtamt' => -5, 'duo' => '111111,222222']))->toBe(cleanFiltered([]));
		// A default filter would come back with Glide's defaults: a 0 strength stays to switch it off.
		$params = cleanFiltered(['filt' => 'ember', 'filtamt' => 0], ['defaults' => ['filt' => 'noir']]);
		expect($params)->not->toHaveKeys(['filt', 'duo', 'filtv'])->and($params['filtamt'])->toBe(0);
	});

	it('normalizes duo colors so equal colors share one cache file', function (): void {
		expect(cleanFiltered(['filt' => 'duotone', 'duo' => 'fff,000'])['duo'])->toBe('ffffff,000000');
		expect(cleanFiltered(['filt' => 'duotone', 'duo' => 'FFFFFF,#000000'])['duo'])->toBe('ffffff,000000');
		expect(cleanFiltered(['filt' => 'duotone', 'duo' => 'palette0,ABC'], palette: ['#336699'])['duo'])->toBe('336699,aabbcc');
	});

	it('falls back to the default duo for a color that is not hex or paletteN', function (string $duo): void {
		expect(cleanFiltered(['filt' => 'duotone', 'duo' => $duo], palette: ['336699'])['duo'])->toBe('000000,336699');
	})->with(['red,blue', 'zzz,000', '1234,000000', 'palette,000', 'ffffff,palettex']);

	it('removes duplicate names and drops a non-numeric filtamt', function (): void {
		expect(cleanFiltered(['filt' => 'ember,ember'])['filt'])->toBe('ember');
		expect(cleanFiltered(['filt' => 'ember', 'filtamt' => 'abc']))->not->toHaveKey('filtamt');
	});
});
