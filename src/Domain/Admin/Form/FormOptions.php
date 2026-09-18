<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

use TotalCMS\Domain\Extension\Service\FormActionRegistry;

/**
 * Everything that varies per form: identity, presentation, behaviour flags.
 *
 * The factory builds one from the option array a Twig call or an admin page
 * passes in (`fromArray()`), a test names its arguments. A key nobody
 * declared is an InvalidArgumentException that names the key, where the old
 * `new ObjectForm(...$options)` spread gave PHP's "unknown named parameter"
 * from three frames down, and the positional `parent::__construct()` calls
 * in the subclasses silently shifted their arguments when a parameter was
 * added in the wrong place.
 *
 * Readonly: a subclass that wants a different default (a collection form's
 * redirect action, a deck item form's form type) takes a copy through
 * `with()`. The form itself copies the values onto its own properties, since
 * `init()` adjusts several of them.
 */
final readonly class FormOptions
{
	/**
	 * @param array<int,array<string,mixed>> $newActions
	 * @param array<int,array<string,mixed>> $editActions
	 * @param array<int,array<string,mixed>> $deleteActions
	 * @param array<string,mixed>            $data
	 */
	public function __construct(
		public string $api,
		public string $collection = '',
		public string $id = '',
		public string $method = 'POST',
		public string $class = '',
		public string $buildError = '',
		public string $helpStyle = '',
		public string $save = '',
		public string $delete = '',
		public string $formType = '',
		public string $schema = '',
		public string $route = '',
		public array $newActions = [],
		public array $editActions = [],
		public array $deleteActions = [],
		public array $data = [],
		public bool $autosave = false,
		public bool $helpOnHover = false,
		public bool $helpOnFocus = false,
		public bool $hideID = false,
		public bool $useFormGrid = true,
		public bool $addOnly = false,
		// Public-registration form mode. When true, the form's action is
		// retargeted to POST /admin/register/{collection} — the allow-listed
		// registration endpoint that creates the user AND auto-logs them in.
		// Implies `addOnly` because the registration route only handles POST.
		public bool $register = false,
		public ?FormActionRegistry $formActionRegistry = null,
		// Admin-catalog translator, supplied by TotalFormFactory as
		// TranslationService::trans(...). Optional: a form built directly
		// (tests, extensions) falls back to the English default at each call.
		public ?\Closure $translator = null,
		// Formgrid for a form built from pre-rendered field HTML rather than a
		// SchemaData — settings sections are the case. Ignored when the form
		// has a SchemaData, which stays the source of truth for its layout.
		public string $formgrid = '',
		// Render the icon slot (`.form-group-icon`) beside each field. Off for
		// forms whose fields live somewhere the icon has no room — one field
		// swapped into a table cell. A field's own `icon` option still wins.
		public bool $fieldIcons = true,
	) {
	}

	/**
	 * Build from an option array. Every key must be a constructor parameter.
	 *
	 * @param array<string,mixed> $options
	 */
	public static function fromArray(array $options): self
	{
		$unknown = array_diff(array_keys($options), self::parameterNames());
		if ($unknown !== []) {
			throw new \InvalidArgumentException(sprintf(
				'Unknown form option%s: %s. Known options: %s',
				count($unknown) === 1 ? '' : 's',
				implode(', ', array_map(strval(...), $unknown)),
				implode(', ', self::parameterNames()),
			));
		}

		return new self(...$options);
	}

	/** A copy with the named options changed. */
	public function with(mixed ...$changes): self
	{
		return new self(...array_merge(get_object_vars($this), $changes));
	}

	/** @return list<string> */
	private static function parameterNames(): array
	{
		static $names = null;

		if ($names === null) {
			$names = [];
			foreach ((new \ReflectionMethod(self::class, '__construct'))->getParameters() as $parameter) {
				$names[] = $parameter->getName();
			}
		}

		return $names;
	}
}
