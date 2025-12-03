<?php

namespace TotalCMS\Domain\Media\Generator;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

// ---------------------------------------------------------------------------------
// QRGenerator
// ---------------------------------------------------------------------------------
class QRGenerator
{
	private readonly Writer $writer;

	public function __construct(
		private readonly ?EditionFeatureService $editionFeatures = null,
		int $size = 512,
	) {
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
		// QR codes require Standard edition or higher
		if ($this->editionFeatures instanceof EditionFeatureService) {
			$this->editionFeatures->canOrFail(EditionFeature::QR_CODES);
		}

		$svg = $this->stripFirstLine($this->writer->writeString($text));

		// Add cms-qr-code class to the SVG element
		return (string)preg_replace('/<svg/', '<svg class="cms-qr-code"', $svg, 1);
	}

	private function stripFirstLine(string $text): string
	{
		return substr($text, strpos($text, "\n") + 1);
	}

	public function text(string $text): string
	{
		return $this->generateSVG($text);
	}

	public function url(string $text): string
	{
		return $this->generateSVG($text);
	}

	public function tel(string $text): string
	{
		$url  = "tel:$text";

		return $this->generateSVG($url);
	}

	public function gps(string $latitude, string $longitude): string
	{
		$url  = 'geo:' . $latitude . ',' . $longitude;

		return $this->generateSVG($url);
	}

	public function sms(string $telephone, string $message): string
	{
		$url  = 'smsto:' . $telephone . ':' . $message;

		return $this->generateSVG($url);
	}

	public function wifi(string $auth, string $ssid, string $password, string $hidden): string
	{
		$url  = 'WIFI:T:' . $auth . ';S:' . $ssid . ';P:' . $password . ';H:' . $hidden;

		return $this->generateSVG($url);
	}

	public function mailto(string $email, string $subject = '', string $body = ''): string
	{
		$url  = 'mailto:' . $email . '?subject=' . $subject . '&body=' . $body;

		return $this->generateSVG($url);
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

		return $this->generateSVG($vcard);
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

		return $this->generateSVG($vcard);
	}
}
