<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Exception\InvalidFilterException;
use TotalCMS\Domain\ImageWorks\Filter\FilterRecipe;
use TotalCMS\Domain\ImageWorks\Filter\FilterStep;

function emberDefinition(): array
{
	return [
		'label' => 'Ember',
		'steps' => [
			['sepia', 0.25],
			['saturate', 1.3],
			['overlay', 'ff9a3c', 'soft-light', 0.25],
			['vignette', 0.3],
		],
	];
}

describe('FilterRecipe::fromDefinition', function (): void {
	it('parses the ember example', function (): void {
		$recipe = FilterRecipe::fromDefinition('ember', emberDefinition());

		expect($recipe->label())->toBe('Ember');
		expect($recipe->group())->toBe('look');
		expect($recipe->steps())->toHaveCount(4);
		expect($recipe->steps()[0])->toBeInstanceOf(FilterStep::class);
		expect($recipe->steps()[0]->op)->toBe('sepia');
		expect($recipe->steps()[0]->isLinear())->toBeTrue();
		expect($recipe->steps()[2]->isLinear())->toBeFalse();
		expect($recipe->steps()[2]->args)->toBe(['ff9a3c', 'soft-light', 0.25]);
		expect($recipe->steps()[3]->op)->toBe('vignette');
	});

	it('falls back to the name when label is missing', function (): void {
		$recipe = FilterRecipe::fromDefinition('ember', ['steps' => [['sepia', 1]]]);

		expect($recipe->label())->toBe('ember');
	});

	it('accepts the other groups', function (string $group): void {
		$recipe = FilterRecipe::fromDefinition('x', ['group' => $group, 'steps' => [['sepia', 1]]]);

		expect($recipe->group())->toBe($group);
	})->with(['look', 'duotone', 'effect']);

	it('normalizes hex colors', function (): void {
		$recipe = FilterRecipe::fromDefinition('x', ['steps' => [['overlay', '#F90', 'multiply', 0.5], ['gradient-map', '000', 'FFFFFF']]]);

		expect($recipe->steps()[0]->args)->toBe(['ff9900', 'multiply', 0.5]);
		expect($recipe->steps()[1]->args)->toBe(['000000', 'ffffff']);
	});

	it('defaults the vignette color', function (): void {
		$recipe = FilterRecipe::fromDefinition('x', ['steps' => [['vignette', 0.4], ['vignette', 0.4, 'ff0000']]]);

		expect($recipe->steps()[0]->args)->toBe([0.4, '000000']);
		expect($recipe->steps()[1]->args)->toBe([0.4, 'ff0000']);
	});

	it('accepts grain and leak steps', function (): void {
		$recipe = FilterRecipe::fromDefinition('x', ['steps' => [
			['grain', 0.35],
			['grain', 0],
			['leak', '#FA0', 'top-right', 0.55],
			['leak', 'ff7a3c', 'left', 1],
		]]);

		expect($recipe->steps()[0]->args)->toBe([0.35]);
		expect($recipe->steps()[1]->args)->toBe([0.0]);
		expect($recipe->steps()[2]->args)->toBe(['ffaa00', 'top-right', 0.55]);
		expect($recipe->steps()[3]->args)->toBe(['ff7a3c', 'left', 1.0]);
		expect($recipe->steps()[0]->isLinear())->toBeFalse();
		expect($recipe->steps()[2]->isLinear())->toBeFalse();
	});

	it('accepts every leak position', function (string $position): void {
		$recipe = FilterRecipe::fromDefinition('x', ['steps' => [['leak', 'ff7a3c', $position, 0.5]]]);

		expect($recipe->steps()[0]->args[1])->toBe($position);
	})->with(['top-left', 'top-right', 'bottom-left', 'bottom-right', 'top', 'bottom', 'left', 'right']);

	it('refuses bad grain and leak steps', function (array $step, string $message): void {
		expect(fn () => FilterRecipe::fromDefinition('x', ['steps' => [$step]]))
			->toThrow(InvalidFilterException::class, $message);
	})->with([
		'grain no args'       => [['grain'], 'grain takes 1 argument'],
		'grain two args'      => [['grain', 0.3, 0.4], 'grain takes 1 argument'],
		'grain too high'      => [['grain', 1.5], 'grain needs a number from 0 to 1'],
		'grain negative'      => [['grain', -0.1], 'grain needs a number from 0 to 1'],
		'grain NAN'           => [['grain', NAN], 'grain needs a number from 0 to 1'],
		'grain string'        => [['grain', 'lots'], 'grain needs a number from 0 to 1'],
		'leak too few args'   => [['leak', 'ff7a3c', 'top-right'], 'leak takes color, position and amount'],
		'leak too many args'  => [['leak', 'ff7a3c', 'top-right', 0.5, 1], 'leak takes color, position and amount'],
		'leak bad hex'        => [['leak', 'orange', 'top-right', 0.5], 'leak needs a 3 or 6 digit hex color'],
		'leak bad position'   => [['leak', 'ff7a3c', 'middle', 0.5], 'leak position must be one of top-left'],
		'leak position case'  => [['leak', 'ff7a3c', 'Top-Right', 0.5], 'leak position must be one of'],
		'leak position int'   => [['leak', 'ff7a3c', 3, 0.5], 'leak position must be one of'],
		'leak amount high'    => [['leak', 'ff7a3c', 'top', 1.2], 'leak needs a number from 0 to 1'],
		'leak amount NAN'     => [['leak', 'ff7a3c', 'top', NAN], 'leak needs a number from 0 to 1'],
		'leak amount INF'     => [['leak', 'ff7a3c', 'top', INF], 'leak needs a number from 0 to 1'],
	]);

	it('refuses bad definitions', function (array $definition): void {
		expect(fn () => FilterRecipe::fromDefinition('x', $definition))->toThrow(InvalidFilterException::class);
	})->with([
		'unknown op'          => [['steps' => [['sharpen', 1]]]],
		'saturate two args'   => [['steps' => [['saturate', 1, 2]]]],
		'saturate no args'    => [['steps' => [['saturate']]]],
		'fade out of range'   => [['steps' => [['fade', 1.5]]]],
		'saturate too high'   => [['steps' => [['saturate', 10.5]]]],
		'hue out of range'    => [['steps' => [['hue-rotate', 361]]]],
		'non numeric'         => [['steps' => [['sepia', 'lots']]]],
		'bad overlay mode'    => [['steps' => [['overlay', 'ff0000', 'darken', 0.5]]]],
		'bad overlay color'   => [['steps' => [['overlay', 'red', 'normal', 0.5]]]],
		'overlay opacity'     => [['steps' => [['overlay', 'ff0000', 'normal', 2]]]],
		'vignette bad color'  => [['steps' => [['vignette', 0.3, 'nope']]]],
		'gradient one stop'   => [['steps' => [['gradient-map', 'ffffff']]]],
		'gradient nine stops' => [['steps' => [['gradient-map', '000', '111', '222', '333', '444', '555', '666', '777', '888']]]],
		'too many steps'      => [['steps' => array_fill(0, 13, ['sepia', 0.5])]],
		'bad group'           => [['group' => 'other', 'steps' => [['sepia', 1]]]],
		'no steps'            => [['steps' => []]],
		'step not a list'     => [['steps' => ['sepia']]],
	]);

	it('refuses a number that is not finite', function (float $value): void {
		expect(fn () => FilterRecipe::fromDefinition('x', ['steps' => [['saturate', $value]]]))
			->toThrow(InvalidFilterException::class);
	})->with(['NAN' => NAN, 'INF' => INF]);

	it('accepts exactly 12 steps', function (): void {
		$recipe = FilterRecipe::fromDefinition('x', ['steps' => array_fill(0, 12, ['sepia', 0.5])]);

		expect($recipe->steps())->toHaveCount(12);
	});
});

describe('FilterRecipe::version', function (): void {
	it('is 8 hex characters', function (): void {
		expect(FilterRecipe::fromDefinition('ember', emberDefinition())->version())->toMatch('/^[0-9a-f]{8}$/');
	});

	it('is the same for equal steps, whatever the label', function (): void {
		$a = FilterRecipe::fromDefinition('ember', emberDefinition());
		$b = FilterRecipe::fromDefinition('other', ['label' => 'Other'] + emberDefinition());

		expect($b->version())->toBe($a->version());
	});

	it('changes when one number changes', function (): void {
		$changed          = emberDefinition();
		$changed['steps'][1][1] = 1.4;

		expect(FilterRecipe::fromDefinition('ember', $changed)->version())
			->not->toBe(FilterRecipe::fromDefinition('ember', emberDefinition())->version());
	});
});
