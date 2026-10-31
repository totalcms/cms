<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Data;

readonly class SecurityFinding
{
	public function __construct(
		public string $id,
		public Severity $severity,
		public FindingStatus $status,
		public string $title,
		public string $detail = '',
		public string $fix = '',
		public string $docs = '',
		public bool $ignored = false,
	) {
	}

	public static function found(string $id, Severity $severity, string $title, string $detail, string $fix, string $docs): self
	{
		return new self($id, $severity, FindingStatus::Found, $title, $detail, $fix, $docs);
	}

	public static function ok(string $id, string $title): self
	{
		return new self($id, Severity::Info, FindingStatus::Ok, $title);
	}

	public static function skipped(string $id, string $title, string $reason): self
	{
		return new self($id, Severity::Info, FindingStatus::Skipped, $title, $reason);
	}

	public function withIgnored(bool $ignored = true): self
	{
		return new self($this->id, $this->severity, $this->status, $this->title, $this->detail, $this->fix, $this->docs, $ignored);
	}

	/** True when this finding should fail `tcms check` and raise the dashboard alert. */
	public function isCountable(): bool
	{
		return $this->status === FindingStatus::Found
			&& $this->severity === Severity::Critical
			&& !$this->ignored;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'id'       => $this->id,
			'severity' => $this->severity->value,
			'status'   => $this->status->value,
			'title'    => $this->title,
			'detail'   => $this->detail,
			'fix'      => $this->fix,
			'docs'     => $this->docs,
			'ignored'  => $this->ignored,
		];
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self
	{
		return new self(
			(string)($data['id'] ?? ''),
			Severity::tryFrom((string)($data['severity'] ?? '')) ?? Severity::Info,
			FindingStatus::tryFrom((string)($data['status'] ?? '')) ?? FindingStatus::Skipped,
			(string)($data['title'] ?? ''),
			(string)($data['detail'] ?? ''),
			(string)($data['fix'] ?? ''),
			(string)($data['docs'] ?? ''),
			(bool)($data['ignored'] ?? false),
		);
	}
}
