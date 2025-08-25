<?php

namespace TotalCMS\Domain\Admin;

final readonly class JobQueueForm implements \Stringable
{
	public function __construct(
		private string $api,
		private string $collection = '',
		private string $label = 'Clear Job Queue',
	) {
	}

	private function clearQueueForm(): string
	{
		$route = $this->collection === ''
			? '/jobqueue'
			: "/jobqueue/{$this->collection}";

		$clearQueueForm = new SimpleForm(
			api     : $this->api,
			route   : $route,
			method  : 'DELETE',
			label   : $this->label,
			class   : 'jobqueue-clear-form',
			refresh : true,
		);

		return $clearQueueForm->build();
	}

	public function build(): string
	{
		return $this->clearQueueForm();
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
