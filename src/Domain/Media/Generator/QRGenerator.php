<?php

namespace TotalCMS\Domain\Media\Generator;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

// ---------------------------------------------------------------------------------
// QRGenerator
// ---------------------------------------------------------------------------------
class QRGenerator
{
	private Writer $writer;

	public function __construct(int $size = 512)
	{
		// TODO: ImagickImageBackEnd and GDLibRenderer for PNG support

		$margin   = 0;
		$renderer = new ImageRenderer(
			new RendererStyle($size, $margin),
			new SvgImageBackEnd()
		);
		$this->writer = new Writer($renderer);
	}

	private function generateSVG(string $text): string
	{
		$svg = $this->stripFirstLine($this->writer->writeString($text));

		return $svg;
	}

	private function stripFirstLine(string $text): string
	{
		return substr($text, strpos($text, "\n") + 1);
	}

	public function text(string $text): string
	{
		$svg = $this->generateSVG($text);

		return $svg;
	}

	public function url(string $text): string
	{
		$svg = $this->generateSVG($text);

		return $svg;
	}

	public function tel(string $text): string
	{
		$url  = "tel:$text";
		$svg  = $this->generateSVG($url);

		return $svg;
	}

	public function gps(string $latitude, string $longitude): string
	{
		$url  = 'geo:' . $latitude . ',' . $longitude;
		$svg  = $this->generateSVG($url);

		return $svg;
	}

	public function sms(string $telephone, string $message): string
	{
		$url  = 'smsto:' . $telephone . ':' . $message;
		$svg  = $this->generateSVG($url);

		return $svg;
	}

	public function wifi(string $auth, string $ssid, string $password, string $hidden): string
	{
		$url  = 'WIFI:T:' . $auth . ';S:' . $ssid . ';P:' . $password . ';H:' . $hidden;
		$svg  = $this->generateSVG($url);

		return $svg;
	}

	public function mailto(string $email, string $subject = '', string $body = ''): string
	{
		$url  = 'mailto:' . $email . '?subject=' . $subject . '&body=' . $body;
		$svg  =  $this->generateSVG($url);

		return $svg;
	}

	/** @param array<string,string> $data */
	public function event(array $data): string
	{
		$data = array_map('htmlspecialchars', $data);
		$data = array_merge([
			'title'    => '',
			'desc'     => '',
			'location' => '',
			'start'    => '',
			'end'      => '',
		], $data);

		date_default_timezone_set('UTC');
		$start = date("Ymd\THis\Z", intval(strtotime($data['start'])));
		$end   = date("Ymd\THis\Z", intval(strtotime($data['end'])));

		$vcard = <<<EVENT
BEGIN:VEVENT
SUMMARY:{$data['title']}
DESCRIPTION:{$data['desc']}
LOCATION:{$data['location']}
DTSTART:{$start}
DTEND:{$end}
END:VEVENT
EVENT;
		$svg = $this->generateSVG($vcard);

		return $svg;
	}

	/** @param array<string,string> $data */
	public function vcf(array $data): string
	{
		$data = array_map('htmlspecialchars', $data);
		$data = array_merge([
			'first'   => '',
			'last'    => '',
			'company' => '',
			'street'  => '',
			'city'    => '',
			'state'   => '',
			'zip'     => '',
			'phone'   => '',
			'mobile'  => '',
			'email'   => '',
			'website' => '',
		], $data);

		$vcard = <<<VCF
BEGIN:VCARD
VERSION:3.0
N:{$data['last']};{$data['first']}
FN:{$data['first']} {$data['last']}
ORG:{$data['company']}
ADR:;;{$data['street']};{$data['city']};{$data['state']};{$data['zip']};
TEL;WORK;VOICE:{$data['phone']}
TEL;CELL:{$data['mobile']}
EMAIL;WORK;INTERNET:{$data['email']}
URL:{$data['website']}
END:VCARD
VCF;
		$svg = $this->generateSVG($vcard);

		return $svg;
	}
}
