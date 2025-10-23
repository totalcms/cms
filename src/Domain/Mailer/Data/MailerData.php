<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Data;

/**
 * MailerData represents an email template object.
 */
readonly class MailerData
{
	public function __construct(
		public string $id,
		public bool $active,
		public string $name,
		public string $description,
		public string $from,
		public string $fromName,
		public string $to,
		public string $toName,
		public string $replyTo,
		public string $cc,
		public string $bcc,
		public string $subject,
		public string $bodyHtml,
		public string $bodyText,
	) {
	}

	/**
	 * Create from array.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			id: $data['id'] ?? '',
			active: $data['active'] ?? true,
			name: $data['name'] ?? '',
			description: $data['description'] ?? '',
			from: $data['from'] ?? '',
			fromName: $data['fromName'] ?? '',
			to: $data['to'] ?? '',
			toName: $data['toName'] ?? '',
			replyTo: $data['replyTo'] ?? '',
			cc: $data['cc'] ?? '',
			bcc: $data['bcc'] ?? '',
			subject: $data['subject'] ?? '',
			bodyHtml: $data['bodyHtml'] ?? '',
			bodyText: $data['bodyText'] ?? '',
		);
	}

	/**
	 * Convert to array.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'id'          => $this->id,
			'active'      => $this->active,
			'name'        => $this->name,
			'description' => $this->description,
			'from'        => $this->from,
			'fromName'    => $this->fromName,
			'to'          => $this->to,
			'toName'      => $this->toName,
			'replyTo'     => $this->replyTo,
			'cc'          => $this->cc,
			'bcc'         => $this->bcc,
			'subject'     => $this->subject,
			'bodyHtml'    => $this->bodyHtml,
			'bodyText'    => $this->bodyText,
		];
	}
}
