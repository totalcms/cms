<?php

namespace TotalCMS\Utils;

class MimeLookup
{
	public static function getMimeType(string $file): string
	{
		$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$mimeTypes = [
			'css'   => 'text/css',
			'js'    => 'application/javascript',
			'html'  => 'text/html',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'png'   => 'image/png',
			'gif'   => 'image/gif',
			'svg'   => 'image/svg+xml',
			'json'  => 'application/json',
			'xml'   => 'application/xml',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',
			'map'   => 'application/json',
		];

		return $mimeTypes[$extension] ?? mime_content_type($file) ?: 'application/octet-stream';
	}
}
