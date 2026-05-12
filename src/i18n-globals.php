<?php

declare(strict_types=1);

/**
 * Global i18n function shims.
 *
 * Re-exports cakephp/i18n's namespaced translation helpers as global
 * functions (__, __n, __d, etc.). Lives in this package's own src/ so the
 * `files` autoload entry resolves correctly whether totalcms/cms is the
 * root project or installed as a Composer dependency.
 *
 * Adapted from cakephp/i18n's functions_global.php (MIT license):
 *   Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *   https://github.com/cakephp/i18n/blob/master/functions_global.php
 */

// phpcs:disable PSR1.Files.SideEffects

use Cake\I18n\Date;
use Cake\I18n\DateTime;

use function Cake\I18n\__ as cake__;
use function Cake\I18n\__d as cake__d;
use function Cake\I18n\__dn as cake__dn;
use function Cake\I18n\__dx as cake__dx;
use function Cake\I18n\__dxn as cake__dxn;
use function Cake\I18n\__n as cake__n;
use function Cake\I18n\__x as cake__x;
use function Cake\I18n\__xn as cake__xn;
use function Cake\I18n\toDate as cakeToDate;
use function Cake\I18n\toDateTime as cakeToDateTime;

if (!function_exists('__')) {
	function __(string $singular, mixed ...$args): string
	{
		return cake__($singular, ...$args);
	}
}

if (!function_exists('__n')) {
	function __n(string $singular, string $plural, int $count, mixed ...$args): string
	{
		return cake__n($singular, $plural, $count, ...$args);
	}
}

if (!function_exists('__d')) {
	function __d(string $domain, string $msg, mixed ...$args): string
	{
		return cake__d($domain, $msg, ...$args);
	}
}

if (!function_exists('__dn')) {
	function __dn(string $domain, string $singular, string $plural, int $count, mixed ...$args): string
	{
		return cake__dn($domain, $singular, $plural, $count, ...$args);
	}
}

if (!function_exists('__x')) {
	function __x(string $context, string $singular, mixed ...$args): string
	{
		return cake__x($context, $singular, ...$args);
	}
}

if (!function_exists('__xn')) {
	function __xn(string $context, string $singular, string $plural, int $count, mixed ...$args): string
	{
		return cake__xn($context, $singular, $plural, $count, ...$args);
	}
}

if (!function_exists('__dx')) {
	function __dx(string $domain, string $context, string $msg, mixed ...$args): string
	{
		return cake__dx($domain, $context, $msg, ...$args);
	}
}

if (!function_exists('__dxn')) {
	function __dxn(
		string $domain,
		string $context,
		string $singular,
		string $plural,
		int $count,
		mixed ...$args,
	): string {
		return cake__dxn($domain, $context, $singular, $plural, $count, ...$args);
	}
}

if (!function_exists('toDateTime')) {
	function toDateTime(mixed $value, string $format = DateTimeInterface::ATOM): ?DateTime
	{
		return cakeToDateTime($value, $format);
	}
}

if (!function_exists('toDate')) {
	function toDate(mixed $value, string $format = 'Y-m-d'): ?Date
	{
		return cakeToDate($value, $format);
	}
}
