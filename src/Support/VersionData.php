<?php

namespace TotalCMS\Support;

/**
 * Version data object for accessing version information.
 *
 * Provides access to individual version components while maintaining
 * backwards compatibility through __toString().
 */
readonly class VersionData implements \Stringable
{
	public string $full;
	public string $number;
	public string $build;
	public ?string $date;
	public bool $valid;

	public function __construct()
	{
		$data = Version::load();

		$this->number = $data['version'] ?? 'unknown';
		$this->build = $data['build'] ?? 'unknown';
		$this->date = Version::date();
		$this->valid = Version::isValid();

		if ($this->number === 'unknown') {
			$this->full = 'unknown';
		} else {
			$this->full = $this->number . '-' . $this->build;
		}
	}

	/**
	 * Returns the full version string for backwards compatibility.
	 * e.g., "3.0.47-baee5e0e"
	 */
	public function __toString(): string
	{
		return $this->full;
	}
}
