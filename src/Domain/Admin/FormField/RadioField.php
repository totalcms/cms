<?php

namespace TotalCMS\Domain\Admin\FormField;

final class RadioField extends FormField
{
}

// <div class="form-field radio-field {{ class }}">
// 	{% if args.label %}
// 	<label>{{ args.label }}</label>
// 	{% endif %}
// 	{% for option in options %}
// 	<div class="radio">
// 		<input
// 			id="field-{{ uuid }}-{{ loop.index }}"
// 			name="{{ property }}"
// 			type="radio"
// 			value="{{ option.value }}"
// 			{% if args.help %}aria-describedby="help-{{ uuid }}"{% endif %}
// 			{% if args.required %}required{% endif %}
// 			{% if option.selected %}checked{% endif %}
// 		>
// 		<label for="field-{{ uuid }}-{{ loop.index }}" class="radio-label">{{ option.label }}</label>
// 	</div>
// 	{% endfor %}
// 	{% if args.help %}
// 	<p class="help" id="help-{{uuid}}">{{ args.help }}</p>
// 	{% endif %}
// </div>