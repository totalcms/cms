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
});
