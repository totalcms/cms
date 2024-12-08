<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Utils\HTMLUtils;

/**
 * Simple Form Builder.
 */
final class SimpleForm
{
	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(
		private string $api,
		private string $route,
		private string $method = 'POST',
		private string $label  = 'Submit',
		private bool $refresh  = false,
	) {
	}

	public function build(string $content = ''): string
	{
		$button = HTMLUtils::button($this->label, [
			'type'  => 'submit',
			'class' => 'dash-button',
		]);
		$buttonWrapper = HTMLUtils::element('div', $button, ['class' => 'form-inline-fields']);

		$formAttrs = [
			'class'        => 'simple-form totalform',
			'data-route'   => $this->route,
			'data-method'  => $this->method,
			'data-api'     => $this->api,
			'data-refresh' => $this->refresh ? 'true' : 'false',
		];
		$form = HTMLUtils::element('form', $content . $buttonWrapper, $formAttrs);

		return $form;
	}

	public function __toString()
	{
		return $this->build();
	}
}
