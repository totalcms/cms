<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Utils\HTMLUtils;

class PropertyField
{
	/**
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-options attribute
	 * @param array<mixed> $options - Options for select fields and datalists
	 */
	public function __construct(
		protected string $property,
		protected string $field       = 'text',
		protected string $label       = '',
		protected string $help        = '',
		protected string $placeholder = '',
		protected array $options      = [],
		protected array $settings     = [],
	) {
	}

	private function buildDialog(): string
	{
		$content = '';
		// 	<dialog class="cms-modal small" open="">
		// 	<section class="scroller">
		// 	  <details class="cms-accordion" open="">
		// 		<summary>Form Info</summary>
		// 		<div class="form-field text-field" data-type="text">
		// 		  <label for="field-668d82d178643">Field Type</label>
		// 		  <div class="form-group">
		// 			<input
		// 			  type="text"
		// 			  readonly=""
		// 			  name="field"
		// 			  id="field-668d82d178643"
		// 			/>
		// 			<div class="form-group-icon"></div>
		// 		  </div>
		// 		  <p class="help" id="help-668d82d178643">
		// 			The type form field that this field will use
		// 		  </p>
		// 		</div>
		// 		<div class="form-field text-field" data-type="text">
		// 		  <label for="field-668d82d17866f">Label</label>
		// 		  <div class="form-group">
		// 			<input
		// 			  type="text"
		// 			  name="label"
		// 			  id="field-668d82d17866f"
		// 			  aria-describedby="help-668d82d17866f"
		// 			/>
		// 			<div class="form-group-icon"></div>
		// 		  </div>
		// 		  <p class="help" id="help-668d82d17866f">
		// 			The label that will be added to the field form
		// 		  </p>
		// 		</div>
		// 		<div class="form-field text-field" data-type="text">
		// 		  <label for="field-668d82d178699">Placeholder</label>
		// 		  <div class="form-group">
		// 			<input
		// 			  type="text"
		// 			  name="placeholder"
		// 			  id="field-668d82d178699"
		// 			  aria-describedby="help-668d82d178699"
		// 			/>
		// 			<div class="form-group-icon"></div>
		// 		  </div>
		// 		  <p class="help" id="help-668d82d178699">
		// 			The placeholder text that will be added to the field form
		// 		  </p>
		// 		</div>
		// 		<div class="form-field text-field" data-type="text">
		// 		  <label for="field-668d82d1786c1">Help</label>
		// 		  <div class="form-group">
		// 			<input
		// 			  type="text"
		// 			  name="help"
		// 			  id="field-668d82d1786c1"
		// 			  aria-describedby="help-668d82d1786c1"
		// 			/>
		// 			<div class="form-group-icon"></div>
		// 		  </div>
		// 		  <p class="help" id="help-668d82d1786c1">
		// 			The help text that will be added to the field form
		// 		  </p>
		// 		</div>
		// 	  </details>
		// 	  <details class="cms-accordion" open="">
		// 		<summary>Additional Options</summary>
		// 		<p>There are no additional options for this property.</p>
		// 	  </details>
		// 	</section>
		// 	<section>
		// 	  <button type="button" class="close button btn">Close</button>
		// 	  <button type="button" class="delete button btn">Delete</button>
		// 	</section>
		//   </dialog>

		return HTMLUtils::dialog($content, 'small');
	}

	public function build(): string
	{
		// <div class="property-field id-field">
		// 	<input autocomplete="off" type="text" name="property" placeholder="name" required>
		// 	<button type="button"></button>
		// 	<dialog class="cms-modal small" ></dialog>
		// </div>

		$inputAttributes = [
			'autocomplete' => 'off',
			'type'         => 'text',
			'name'         => 'property',
			'placeholder'  => 'name',
			'required'     => '',
			// 'disabled'     => '',
			'value'        => $this->property,
		];

		$dialog = $this->buildDialog();
		$button = HTMLUtils::element('button', '', ['type' => 'button']);
		$input  = HTMLUtils::inlineElement('input', $inputAttributes);
		$field  = HTMLUtils::element('div', $input . $button . $dialog, [
			'class' => "property-field {$this->field}-field"
		]);

		return $field;
	}
}
