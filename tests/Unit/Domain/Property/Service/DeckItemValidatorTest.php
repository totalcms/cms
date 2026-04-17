<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Service\DeckItemValidator;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaValidator;

describe('DeckItemValidator', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher   = $this->createMock(SchemaFetcher::class);
		$this->schemaValidator = $this->createMock(SchemaValidator::class);

		$this->validator = new DeckItemValidator(
			$this->schemaFetcher,
			$this->schemaValidator,
		);

		$this->schema             = new SchemaData();
		$this->schema->properties = [
			'comments' => [
				'field'   => 'deck',
				'deckref' => 'https://www.totalcms.co/schemas/deck/comment.json',
			],
			'legacyDeck' => [
				'field'    => 'deck',
				'settings' => ['deckref' => 'https://www.totalcms.co/schemas/deck/legacy.json'],
			],
			'notADeck' => ['field' => 'text'],
		];
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->schema);
	});

	test('validate reads deckref from the top-level property config', function (): void {
		$this->schemaValidator
			->expects($this->once())
			->method('validateSchema')
			->with(['body' => 'hi'], 'comment');

		$this->validator->validate('blog', 'comments', ['body' => 'hi']);
	});

	test('validate reads deckref from settings when not at the top level', function (): void {
		$this->schemaValidator
			->expects($this->once())
			->method('validateSchema')
			->with($this->anything(), 'legacy');

		$this->validator->validate('blog', 'legacyDeck', ['body' => 'hi']);
	});

	test('validate is a no-op when the property does not exist on the schema', function (): void {
		$this->schemaValidator->expects($this->never())->method('validateSchema');

		$this->validator->validate('blog', 'unknownProperty', ['body' => 'hi']);
	});

	test('validate is a no-op when the property has no deckref', function (): void {
		$this->schemaValidator->expects($this->never())->method('validateSchema');

		$this->validator->validate('blog', 'notADeck', ['body' => 'hi']);
	});

	test('validate re-throws DomainException as InvalidArgumentException', function (): void {
		$this->schemaValidator
			->method('validateSchema')
			->willThrowException(new \DomainException('property "title" is required'));

		expect(fn () => $this->validator->validate('blog', 'comments', []))
			->toThrow(\InvalidArgumentException::class, 'property "title" is required');
	});
});
