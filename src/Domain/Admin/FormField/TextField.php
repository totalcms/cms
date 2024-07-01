<?php

namespace TotalCMS\Domain\Admin\FormField;

/**
 * Total Form Field Builder.
 */
final class TextField extends FormField
{
	public function __construct()
	{
	}

	public function build(): string
	{
		$attributes = [
			// 'class'           => "totalform {$this->class}",
			// 'data-schema'     => $this->collectionData->schema,
			// 'data-collection' => $this->collection,
			// 'data-method'     => $this->method,
			// 'data-api'        => $this->api,
			// 'data-route'      => $this->route,
		];

		return self::createHTMLElement('form', $this->fieldContent(), $attributes);
	}

	public static function fieldTemplate(string $tag, string $content, array $attributes = []): string
	{
		$template = <<<HTML
<div class="form-field {{ field }}-field {{ args.class }}"
	data-type="{{ field }}"
	{% if args.options %}data-options="{{ args.options|json_encode }}"{% endif %}
	>
	<label for="field-{{ uuid }}">{{ args.label }}</label>
	<div class="form-group">
		<input
			id="field-{{ uuid }}"
			type="{{ args.type }}"
			name="{{ property }}"
			{% if args.minlength %}minlength="{{ args.minlength }}"{% endif %}
			{% if args.pattern %}pattern="{{ args.pattern }}"{% endif %}
			{% if args.placeholder %}placeholder="{{ args.placeholder|e }}"{% endif %}
			{% if args.help %}aria-describedby="help-{{ uuid }}"{% endif %}
			{% if args.required %}required{% endif %}
			{% if args.disabled %}disabled{% endif %}
			{% if args.readonly %}readonly{% endif %}
			{% if value is not empty %}value="{{ value|e }}"{% endif %}
		>
	{% if args.icon %}<div class="form-group-icon"></div>{% endif %}
	</div>
	{% if args.help %}
	<p class="help" id="help-{{uuid}}">{{ args.help }}</p>
	{% endif %}
</div>

HTML;
	}
}
