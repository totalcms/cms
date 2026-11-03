<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Data;

use TotalCMS\Domain\Mailer\Service\BulkRecipientFilter;
use TotalCMS\Domain\Mailer\Service\BulkVariableProvider;

/**
 * A bulk message whose Twig source the caller supplies instead of a mailer
 * template (BusinessKit campaigns). It is stored once per batch and rendered
 * for each recipient when the job runs, with the recipient as `contact`.
 *
 * `sourceId` replaces the template id for dedupe and Send History: each
 * object receives a given source once. It must carry a prefix
 * (`businesskit:campaign:42`) so it can never match a template id.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") One argument per part of
 * an email, as the BusinessKit spec fixes them; callers use named arguments.
 */
final readonly class RenderedBulkMessage
{
	/**
	 * @param class-string<BulkRecipientFilter>|string|null  $recipientFilter
	 * @param class-string<BulkVariableProvider>|string|null $variables
	 */
	public function __construct(
		public string $sourceId,
		public string $toTemplate,
		public string $toNameTemplate,
		public string $subjectTemplate,
		public string $bodyHtmlTemplate,
		public string $bodyTextTemplate,
		public string $fromName = '',
		public string $from = '',
		public string $replyTo = '',
		public ?string $recipientFilter = null,
		public ?string $variables = null,
	) {
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self
	{
		$str      = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string)$data[$key] : '';
		$optional = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;

		return new self(
			sourceId: $str('sourceId'),
			toTemplate: $str('toTemplate'),
			toNameTemplate: $str('toNameTemplate'),
			subjectTemplate: $str('subjectTemplate'),
			bodyHtmlTemplate: $str('bodyHtmlTemplate'),
			bodyTextTemplate: $str('bodyTextTemplate'),
			fromName: $str('fromName'),
			from: $str('from'),
			replyTo: $str('replyTo'),
			recipientFilter: $optional('recipientFilter'),
			variables: $optional('variables'),
		);
	}

	/** @return array<string,string|null> */
	public function toArray(): array
	{
		return get_object_vars($this);
	}

	/**
	 * Why the message cannot be queued, or null when it can.
	 */
	public function problem(): ?string
	{
		if (preg_match('/^[^\s:]+:\S+$/', $this->sourceId) !== 1) {
			return 'sourceId needs a prefix, such as "businesskit:campaign:42"';
		}

		foreach (['toTemplate', 'subjectTemplate', 'bodyHtmlTemplate'] as $key) {
			if (trim($this->{$key}) === '') {
				return $key . ' is required';
			}
		}

		return $this->hookProblem('recipientFilter', $this->recipientFilter, BulkRecipientFilter::class)
			?? $this->hookProblem('variables', $this->variables, BulkVariableProvider::class);
	}

	private function hookProblem(string $key, ?string $class, string $interface): ?string
	{
		if ($class === null || is_a($class, $interface, true)) {
			return null;
		}

		return sprintf('%s must name a class implementing %s', $key, $interface);
	}
}
