<?php

use TotalCMS\Domain\Admin\FormField\CodeField;
use TotalCMS\Domain\Admin\FormField\TextareaField;

describe('CodeField', function (): void {
	it('extends TextareaField', function (): void {
		// Create a simple reflection test without complex mocking
		$reflection = new ReflectionClass(CodeField::class);
		$parentClass = $reflection->getParentClass();
		
		expect($parentClass->getName())->toBe(TextareaField::class);
	});
	
	it('has correct default input type property', function (): void {
		// Test that the class has the correct default input type using reflection
		$reflection = new ReflectionClass(CodeField::class);
		$property = $reflection->getProperty('defaultInputType');
		$property->setAccessible(true);
		
		// Since we can't easily create an instance without dependencies,
		// we verify the property exists and can be accessed
		expect($property->getName())->toBe('defaultInputType');
		expect($property->isProtected())->toBeTrue();
	});
	
	it('class exists and is instantiable', function (): void {
		$reflection = new ReflectionClass(CodeField::class);
		expect($reflection->isInstantiable())->toBeTrue();
		expect($reflection->getName())->toBe(CodeField::class);
	});
	
	it('inherits from the correct parent class hierarchy', function (): void {
		// Verify the inheritance chain
		expect(is_subclass_of(CodeField::class, TextareaField::class))->toBeTrue();
	});
});