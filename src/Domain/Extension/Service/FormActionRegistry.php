<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\FormAction;

class FormActionRegistry
{
	/** @var array<string, FormAction> */
	private array $actions = [];

	public function register(FormAction $action): void
	{
		$this->actions[$action->name] = $action;
	}

	public function get(string $name): ?FormAction
	{
		return $this->actions[$name] ?? null;
	}

	/** @return array<string, FormAction> */
	public function all(): array
	{
		return $this->actions;
	}

	public function toJsonMap(): string
	{
		$map = [];
		foreach ($this->actions as $name => $action) {
			$map[$name] = $action->route;
		}

		return json_encode((object) $map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}
}
