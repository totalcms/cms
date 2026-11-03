<?php

declare(strict_types=1);

use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Mailer\Service\BulkRecipientFilter;
use TotalCMS\Domain\Mailer\Service\BulkVariableProvider;

/*
 * The Twig source of a rendered bulk send, stored once per batch and
 * rendered per recipient by the job.
 */
function renderedMessage(array $overrides = []): RenderedBulkMessage
{
	return RenderedBulkMessage::fromArray($overrides + [
		'sourceId'         => 'businesskit:campaign:spring',
		'toTemplate'       => '{{ contact.email }}',
		'toNameTemplate'   => '{{ contact.name }}',
		'subjectTemplate'  => 'Hi {{ contact.name }}',
		'bodyHtmlTemplate' => '<p>Hello</p>',
		'bodyTextTemplate' => 'Hello',
	]);
}

final class RenderedTestFilter implements BulkRecipientFilter
{
	public function accepts(array $object): bool
	{
		return true;
	}
}

final class RenderedTestVariables implements BulkVariableProvider
{
	public function variables(array $object): array
	{
		return ['vars' => [], 'headers' => []];
	}
}

it('survives storage unchanged', function (): void {
	$message = renderedMessage(['from' => 'news@acme.test', 'fromName' => 'Acme', 'replyTo' => 'hi@acme.test', 'recipientFilter' => RenderedTestFilter::class, 'variables' => RenderedTestVariables::class]);

	expect(RenderedBulkMessage::fromArray(json_decode((string)json_encode($message->toArray()), true)))->toEqual($message);
});

it('is ready to queue with the required parts and valid hooks', function (): void {
	expect(renderedMessage(['recipientFilter' => RenderedTestFilter::class, 'variables' => RenderedTestVariables::class])->problem())->toBeNull();
});

it('needs a source key with a prefix, so it never matches a template id', function (string $sourceId): void {
	expect(renderedMessage(['sourceId' => $sourceId])->problem())->toContain('sourceId');
})->with(['', 'newsletter', 'business kit:spring', ':spring']);

it('needs a recipient, a subject and a body', function (string $key): void {
	expect(renderedMessage([$key => ' '])->problem())->toContain($key);
})->with(['toTemplate', 'subjectTemplate', 'bodyHtmlTemplate']);

it('refuses hook classes that do not exist or implement the wrong interface', function (string $key, string $class): void {
	expect(renderedMessage([$key => $class])->problem())->toContain($key);
})->with([
	['recipientFilter', 'No\\Such\\Filter'],
	['recipientFilter', RenderedTestVariables::class],
	['variables', RenderedTestFilter::class],
]);
