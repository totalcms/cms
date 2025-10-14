<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

/**
 * Simple Form Builder.
 */
readonly class SimpleForm implements \Stringable
{
	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(
		private string $api,
		private string $route,
		private string $method = 'POST',
		private string $label  = 'Submit',
		private string $class  = '',
		private bool $refresh  = false,
		private bool $ajax     = true,
		private ?CSRFTokenManager $csrfManager = null,
	) {
	}

	public function build(string $content = ''): string
	{
		// Add CSRF token if manager is available and method requires protection
		$csrfField = '';
		if ($this->csrfManager && in_array(strtoupper($this->method), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
			$csrfField = $this->csrfManager->getTokenField();
		}

		$button = HTMLUtils::button($this->label, [
			'type'  => 'submit',
			'class' => 'dash-button',
		]);
		$buttonWrapper = HTMLUtils::element('div', $button, ['class' => 'form-inline-fields']);

		$formAttrs = [
			'class'        => 'simple-form totalform ' . $this->class,
			'data-route'   => $this->route,
			'data-method'  => $this->method,
			'data-api'     => $this->api,
			'data-refresh' => $this->refresh ? 'true' : 'false',
			'data-ajax'    => $this->ajax ? 'true' : 'false',
		];

		return HTMLUtils::element('form', $csrfField . $content . $buttonWrapper, $formAttrs);
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
