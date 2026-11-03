<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Mailer\Service\BulkRecipientFilter;
use TotalCMS\Domain\Mailer\Service\BulkVariableProvider;
use TotalCMS\Domain\Mailer\Service\RenderedBulkHooks;

/*
 * The caller's recipient filter and variable provider, resolved from the
 * container when the job runs.
 */
function hooksMessage(?string $filter = null, ?string $variables = null): RenderedBulkMessage
{
	return new RenderedBulkMessage('kit:c1', '{{ contact.email }}', '', 'Subject', '<p>Hi</p>', '', recipientFilter: $filter, variables: $variables);
}

function hooksWith(array $services): RenderedBulkHooks
{
	$container = new class($services) implements ContainerInterface {
		public function __construct(private array $services)
		{
		}

		public function get(string $id): mixed
		{
			return $this->services[$id];
		}

		public function has(string $id): bool
		{
			return isset($this->services[$id]);
		}
	};

	return new RenderedBulkHooks($container);
}

it('accepts every recipient and adds nothing without hooks', function (): void {
	$hooks = hooksWith([]);

	expect($hooks->accepts(hooksMessage(), ['id' => 'a']))->toBeTrue()
		->and($hooks->variables(hooksMessage(), ['id' => 'a']))->toBe(['vars' => [], 'headers' => []]);
});

it('asks the recipient filter about each object', function (): void {
	$filter = new class implements BulkRecipientFilter {
		public function accepts(array $object): bool
		{
			return ($object['status'] ?? '') === 'subscribed';
		}
	};
	$hooks = hooksWith([$filter::class => $filter]);

	expect($hooks->accepts(hooksMessage($filter::class), ['status' => 'subscribed']))->toBeTrue()
		->and($hooks->accepts(hooksMessage($filter::class), ['status' => 'unsubscribed']))->toBeFalse();
});

it('returns the provider variables and headers for each object', function (): void {
	$provider = new class implements BulkVariableProvider {
		public function variables(array $object): array
		{
			return ['vars' => ['unsubscribeUrl' => 'https://kit/u/' . $object['id']], 'headers' => ['List-Unsubscribe' => '<https://kit/u/' . $object['id'] . '>']];
		}
	};
	$hooks = hooksWith([$provider::class => $provider]);

	expect($hooks->variables(hooksMessage(variables: $provider::class), ['id' => 'c9']))->toBe([
		'vars'    => ['unsubscribeUrl' => 'https://kit/u/c9'],
		'headers' => ['List-Unsubscribe' => '<https://kit/u/c9>'],
	]);
});

it('refuses a service that does not implement the hook', function (): void {
	$hooks = hooksWith([stdClass::class => new stdClass()]);

	expect(fn () => $hooks->accepts(hooksMessage(stdClass::class), []))->toThrow(LogicException::class);
});
