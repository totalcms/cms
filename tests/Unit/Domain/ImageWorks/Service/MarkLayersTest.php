<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Service\MarkLayers;

describe('MarkLayers', function (): void {
	it('reads the flat params as a logo layer then a text layer', function (): void {
		expect(MarkLayers::fromParams(['w' => 600, 'marktext' => 'Hi', 'marktextsize' => 40, 'mark' => 'logo.png', 'markpos' => 'top-left']))->toBe([
			['kind' => MarkLayers::LOGO, 'mark' => 'logo.png', 'markpos' => 'top-left', 'markw' => '100w'],
			['kind' => MarkLayers::TEXT, 'marktext' => 'Hi', 'marktextsize' => 40],
		]);
	});

	it('adds the marks list after the flat params, in order', function (): void {
		$layers = MarkLayers::fromParams(['mark' => 'logo.png', 'marks' => [
			['marktext' => 'One'],
			['markimage' => 'team/jane/photo', 'mark' => 'ignored.png', 'markw' => '15w'],
		]]);

		expect(array_column($layers, 'kind'))->toBe([MarkLayers::LOGO, MarkLayers::TEXT, MarkLayers::IMAGE])
			->and($layers[2])->toBe(['kind' => MarkLayers::IMAGE, 'markimage' => 'team/jane/photo', 'mark' => 'ignored.png', 'markw' => '15w']);
	});

	it('stops at six layers', function (): void {
		expect(MarkLayers::fromParams(['marks' => array_fill(0, 9, ['marktext' => 'x'])]))->toHaveCount(MarkLayers::MAX);
	});

	it('puts the property watermark on top, replacing the flat mark of its kind', function (): void {
		$layers = MarkLayers::withSettings(
			['mark' => 'url.png', 'marktext' => 'URL text', 'marks' => [['mark' => 'listed.png']]],
			['marktext' => '© Studio'],
		);

		expect(array_map(fn (array $layer): string => (string)($layer['mark'] ?? $layer['marktext']), $layers))
			->toBe(['url.png', 'listed.png', '© Studio']);
	});

	it('leaves the URL layers alone when there is no property watermark', function (): void {
		expect(MarkLayers::withSettings(['marktext' => 'x'], []))->toBe(MarkLayers::fromParams(['marktext' => 'x']));
	});

	it('reads markfill as a fill layer that covers the image unless sized', function (): void {
		expect(MarkLayers::fromFlat(['markfill' => '000000', 'markalpha' => 40, 'markshape' => 'circle']))->toBe([
			['kind' => MarkLayers::FILL, 'markfill' => '000000', 'markalpha' => 40, 'markw' => '100w', 'markh' => '100h'],
		])->and(MarkLayers::fromFlat(['markfill' => '000000', 'markh' => '30h'])[0]['markh'])->toBe('30h');
	});

	it('keeps a URL fill under a property watermark', function (): void {
		$layers = MarkLayers::withSettings(['markfill' => '000000'], ['mark' => 'logo.png']);

		expect(array_column($layers, 'kind'))->toBe([MarkLayers::FILL, MarkLayers::LOGO]);
	});
});
