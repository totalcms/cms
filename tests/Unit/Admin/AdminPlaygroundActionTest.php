<?php

use TotalCMS\Action\Admin\AdminPlaygroundAction;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Renderer\TwigRenderer;

describe('AdminPlaygroundAction', function (): void {
	it('class exists and is instantiable', function (): void {
		$reflection = new ReflectionClass(AdminPlaygroundAction::class);
		expect($reflection->isInstantiable())->toBeTrue();
		expect($reflection->getName())->toBe(AdminPlaygroundAction::class);
	});

	it('has correct constructor dependencies', function (): void {
		$reflection  = new ReflectionClass(AdminPlaygroundAction::class);
		$constructor = $reflection->getConstructor();

		expect($constructor)->not()->toBeNull();

		$parameters = $constructor->getParameters();
		expect($parameters)->toHaveCount(3);

		// Check parameter types
		expect($parameters[0]->getType()->getName())->toBe(TwigRenderer::class);
		expect($parameters[1]->getType()->getName())->toBe(TwigEngine::class);
		expect($parameters[2]->getType()->getName())->toBe(RawRenderer::class);
	});

	it('has invoke method for handling requests', function (): void {
		$reflection = new ReflectionClass(AdminPlaygroundAction::class);
		expect($reflection->hasMethod('__invoke'))->toBeTrue();

		$invokeMethod = $reflection->getMethod('__invoke');
		expect($invokeMethod->isPublic())->toBeTrue();

		$parameters = $invokeMethod->getParameters();
		expect($parameters)->toHaveCount(3);
	});
});
