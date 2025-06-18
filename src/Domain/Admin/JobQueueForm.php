<?php

namespace TotalCMS\Domain\Admin;

final class JobQueueForm
{
	public function __construct(
		private string $api,
		private string $collection = '',
		private string $label = 'Clear Job Queue',
	) {
	}

	private function clearQueueForm(): string
	{
		$route = empty($this->collection)
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
		$form = $this->clearQueueForm();

		return $form;
	}

	public function __toString()
	{
		return $this->build();
	}
}
