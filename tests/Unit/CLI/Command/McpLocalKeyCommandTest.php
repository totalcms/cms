<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Mcp\McpLocalKeyCommand;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\ApiKey\Service\ApiKeyGrants;
use TotalCMS\TotalCMS;

/*
 * `tcms mcp:local-key` makes the admin key Total CMS Studio writes into a
 * site's AI tool configs. It works only from this computer, and running it
 * again replaces it rather than piling up keys.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
	$container        = $this->app->getContainer();
	$this->repository = $container->get(ApiKeyRepository::class);
	$this->creator    = $container->get(ApiKeyCreator::class);
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);
	$console = new Application();
	$console->addCommand(new McpLocalKeyCommand($totalcms));
	$this->tester = new CommandTester($console->find('mcp:local-key'));
});

/** @return list<ApiKeyData> */
function localKeys(ApiKeyRepository $repository): array
{
	return array_values(array_filter($repository->getAll(), fn (ApiKeyData $k): bool => $k->localOnly));
}

it('creates an unlimited local-only key', function (): void {
	expect($this->tester->execute([]))->toBe(0);
	$keys = localKeys($this->repository);
	expect($keys)->toHaveCount(1)
		->and($keys[0]->name)->toBe('Local AI tools')
		->and(ApiKeyGrants::isUnlimited($keys[0]))->toBeTrue()
		->and($this->tester->getDisplay())->toContain($keys[0]->key)
		->and($this->tester->getDisplay())->toContain('only from this computer');
});

it('json output carries the key', function (): void {
	expect($this->tester->execute(['--name' => 'Total CMS Studio', '--json' => true]))->toBe(0);
	$out = json_decode(trim($this->tester->getDisplay()), true);
	$key = localKeys($this->repository)[0];
	expect($out)->toBe(['key' => $key->key, 'id' => $key->id, 'name' => 'Total CMS Studio']);
});

it('running it again replaces only its own key', function (): void {
	$this->tester->execute(['--name' => 'Total CMS Studio']);
	$this->tester->execute(['--name' => 'Other tool']);
	$first = array_map(fn (ApiKeyData $k): string => $k->id, localKeys($this->repository));
	$this->tester->execute(['--name' => 'Total CMS Studio']);
	$keys = localKeys($this->repository);
	expect($keys)->toHaveCount(2)
		->and(array_map(fn (ApiKeyData $k): string => $k->name, $keys))->toEqualCanonicalizing(['Total CMS Studio', 'Other tool'])
		->and(in_array($first[1], array_map(fn (ApiKeyData $k): string => $k->id, $keys), true))->toBeTrue()
		->and(in_array($first[0], array_map(fn (ApiKeyData $k): string => $k->id, $keys), true))->toBeFalse();
});

it('a key made in the admin with the same name is kept', function (): void {
	$admin = $this->creator->createApiKey('Total CMS Studio', ['methods' => ['GET'], 'paths' => ['/collections']]);
	$this->tester->execute(['--name' => 'Total CMS Studio']);
	$this->tester->execute(['--name' => 'Total CMS Studio']);
	expect($this->repository->findById($admin->id))->not->toBeNull()
		->and($this->repository->getAll())->toHaveCount(2);
});
