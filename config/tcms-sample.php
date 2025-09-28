<?php

// Sample configuration file for /tcms.php
// Note: Total CMS now does deep merging, so you can override specific nested settings
// without replacing entire configuration arrays.

// Return an array for deep merging (recommended)
return [
	'sentry' => [
		'enable' => true,
	],

	'api'     => '/site-assets/stacks/ws.tcms.core/tcms/',
	'datadir' => __DIR__ . '/tcms-data',
	'timezone' => 'America/Denver', // https://www.php.net/manual/en/timezones.php

	// Cache configuration with deep merge support
	'cache' => [
		'redis' => [
			'enabled'  => true,
			'password' => 'your_redis_password_here', // Only override the password
			// Other redis settings (host, port, etc.) will remain from defaults
		],
		'memcached' => [
			'enabled' => false, // Can disable specific cache backends
		],
	],

	// ImageWorks settings
	'imageworks' => [
		'watermarksGallery' => 'watermarks',
		'presets' => [
			'small' => [
				'w' => 300,
				'h' => 200,
			],
			'small-crop' => [
				'w'   => 300,
				'h'   => 300,
				'fit' => 'crop-focalpoint',
			],
			'medium' => [
				'w' => 600,
				'h' => 400,
			],
			'medium-crop' => [
				'w'   => 600,
				'h'   => 600,
				'fit' => 'crop-focalpoint',
			],
		],
	],
];

// Legacy style still works but is not recommended:
// $settings['sentry']['enable'] = true;
// $settings['cache']['redis']['password'] = 'password';
