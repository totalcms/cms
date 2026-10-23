<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Action\Extension\ExtensionRemoveAction;
use TotalCMS\Domain\Extension\Service\Install\ExtensionRemover;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Renderer\JsonRenderer;

/** @return array{status: int, body: array<string,mixed>} */
function removeInvoke(ExtensionRemover $remover, string $id): array
{
	$factory  = new Psr17Factory();
	$request  = $factory->createServerRequest('POST', "/api/extensions/{$id}/remove");
	$response = (new ExtensionRemoveAction($remover, new JsonRenderer()))($request, $factory->createResponse(), ['extension' => $id]);

	return ['status' => $response->getStatusCode(), 'body' => (array)json_decode((string)$response->getBody(), true)];
}

describe('ExtensionRemoveAction', function (): void {
	test('removes and reports the id', function (): void {
		$remover = test()->createMock(ExtensionRemover::class);
		$remover->expects(test()->once())->method('remove')->with('acme/thing');

		$out = removeInvoke($remover, 'acme/thing');

		expect($out['status'])->toBe(200)
			->and($out['body'])->toBe(['status' => 'removed', 'id' => 'acme/thing']);
	});

	test('a refusal is a 400 with the owner message and code', function (): void {
		$remover = test()->createMock(ExtensionRemover::class);
		$remover->method('remove')->willThrowException(InstallException::ownedElsewhere("Extension 'totalcms/podcast' is bundled with Total CMS and cannot be removed."));

		$out = removeInvoke($remover, 'totalcms/podcast');

		expect($out['status'])->toBe(400)
			->and($out['body']['code'])->toBe('owned_elsewhere')
			->and($out['body']['error'])->toContain('bundled');
	});

	test('an unknown id is a 404', function (): void {
		$remover = test()->createMock(ExtensionRemover::class);
		$remover->method('remove')->willThrowException(InstallException::notFound("Extension 'acme/none' not found"));

		expect(removeInvoke($remover, 'acme/none')['status'])->toBe(404);
	});
});
