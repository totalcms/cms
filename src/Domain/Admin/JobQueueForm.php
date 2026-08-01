<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

readonly class JobQueueForm implements \Stringable
{
	public function __construct(
		private string $api,
		private string $collection = '',
		private string $label = 'Clear Job Queue',
		private ?CSRFTokenManager $csrfManager = null,
		private bool $failedOnly = false,
	) {
	}

	private function clearQueueForm(): string
	{
		$route = match (true) {
			$this->failedOnly        => '/jobqueue/status/failed',
			$this->collection !== '' => "/jobqueue/{$this->collection}",
			default                  => '/jobqueue',
		};

		$clearQueueForm = new SimpleForm(
			api         : $this->api,
			route       : $route,
			method      : 'DELETE',
			label       : $this->label,
			class       : 'jobqueue-clear-form',
			refresh     : true,
			csrfManager : $this->csrfManager,
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
