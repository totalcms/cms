<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use Intervention\Image\Colors\Rgb\Color;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Interfaces\ImageInterface;
use League\Flysystem\FilesystemOperator;
use League\Glide\Manipulators\BaseManipulator;
use League\Glide\Manipulators\Size;
use League\Glide\Manipulators\Watermark;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;

/**
 * Draws every mark layer over the image in one pass, in list order. Takes
 * the place of Glide's own Watermark manipulator, which draws one.
 *
 * Its `marks` param is the list WatermarkFactory::prepare() builds: a layer
 * with `src` is an image file under the data root (a logo, a record's image,
 * a tiled pattern), a layer with `marktext` is text drawn here with
 * Intervention, and a layer with `fill` is a color drawn here, flat or
 * fading out from one edge. Either is sized and placed with Glide's watermark rules, so
 * `markpos`, `markpad`, `15w` and `markalpha` mean what they always have.
 * Each layer's file version is in the list, so Glide's cache key changes
 * when a file does.
 */
final class MarkLayersManipulator extends BaseManipulator
{
	/** A text mark drawn wider than the image is scaled to this. */
	private const OVERSIZE_TEXT_WIDTH = '90w';

	public function __construct(
		private readonly FilesystemOperator $files,
		private readonly TextWatermarkFactory $textMarks,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/** @return list<string> */
	public function getApiParams(): array
	{
		return ['marks', 'dpr'];
	}

	public function run(ImageInterface $image): ImageInterface
	{
		$layers = $this->getParam('marks');
		if (!is_array($layers)) {
			return $image;
		}

		foreach ($layers as $layer) {
			if (!is_array($layer)) {
				continue;
			}

			try {
				$image = $this->drawLayer($image, $layer);
			} catch (\Throwable $e) {
				// One broken layer (a missing font, an unreadable file) must not
				// cost the whole image.
				$this->logger?->warning('ImageWorks mark layer skipped', [
					'layer' => $layer['src'] ?? $layer['marktext'] ?? '',
					'error' => $e->getMessage(),
				]);
			}
		}

		return $image;
	}

	/** @param array<array-key,mixed> $layer */
	private function drawLayer(ImageInterface $image, array $layer): ImageInterface
	{
		$rules = new Watermark();
		$rules->setParams(array_filter(
			['dpr' => $this->getParam('dpr')] + $layer,
			is_scalar(...),
		));

		$mark = match (true) {
			isset($layer['src'])  => $this->readMark($image, (string)$layer['src']),
			isset($layer['fill']) => $this->drawFill($image, $layer, $rules),
			default               => $this->drawText($image, $layer),
		};
		if (!$mark instanceof ImageInterface) {
			return $image;
		}

		if (($layer['fitwidth'] ?? false) && $mark->width() > $image->width()) {
			$rules->setParams(['markw' => self::OVERSIZE_TEXT_WIDTH] + $layer + ['dpr' => $this->getParam('dpr')]);
		}

		$markx   = $rules->getDimension($image, 'markx');
		$marky   = $rules->getDimension($image, 'marky');
		$markpad = $rules->getDimension($image, 'markpad');
		if ($markpad !== null) {
			$markx = $marky = $markpad;
		}

		// A fill is drawn at its size already. Fitting it again would shrink
		// it: 36h of a 630px card is 226.8px, and a 1200×227 fill "contained"
		// in 1200×226.8 comes out a few pixels narrow, short of both edges.
		if (!isset($layer['fill'])) {
			$size = new Size();
			$size->setParams([
				'w'   => $rules->getDimension($image, 'markw'),
				'h'   => $rules->getDimension($image, 'markh'),
				// A record's image may be cut around its focal point (crop-50-30),
				// which Glide's watermark fits do not include.
				'fit' => isset($layer['focalfit']) ? (string)$layer['focalfit'] : $rules->getFit(),
			]);
			$mark = $size->run($mark);
		}

		if (($layer['markshape'] ?? '') === 'circle') {
			$mark = $this->circle($mark);
		}

		return $image->place($mark, $rules->getPosition(), (int)$markx, (int)$marky, $rules->getAlpha());
	}

	private function readMark(ImageInterface $image, string $path): ?ImageInterface
	{
		if (!$this->files->fileExists($path)) {
			return null;
		}

		$mark = $image->driver()->handleInput($this->files->read($path));

		return $mark instanceof ImageInterface ? $mark : null;
	}

	/** @param array<array-key,mixed> $layer */
	private function drawText(ImageInterface $image, array $layer): ImageInterface
	{
		$text = array_filter(
			$layer,
			fn (mixed $value, int|string $key): bool => str_starts_with((string)$key, 'marktext') && is_scalar($value),
			ARRAY_FILTER_USE_BOTH,
		);

		if (isset($text['marktextsize'])) {
			$size                 = $this->pixels((string)$text['marktextsize'], $image);
			$text['marktextsize'] = $size === null ? TextWatermarkStyle::DEFAULT_SIZE : max(1, (int)round($size));
		}

		$wrapWidth = null;
		if (WatermarkFactory::wraps($layer)) {
			$width     = $this->pixels((string)$layer['marktextw'], $image);
			$wrapWidth = $width === null ? null : max(1, (int)floor($width));
		}

		return $this->textMarks->draw(new TextWatermarkStyle($text), $image->driver(), $wrapWidth);
	}

	/**
	 * A length in pixels on this image: a share of its width or height
	 * (`6w`, `4.5h`), or a number of pixels scaled by the device pixel ratio.
	 * Glide's own reading takes whole percentages only.
	 */
	private function pixels(string $value, ImageInterface $image): ?float
	{
		if (preg_match('/^(\d{1,3}(?:\.\d+)?)([wh])$/', $value, $match) === 1) {
			$share = min(100.0, (float)$match[1]) / 100;

			return ($match[2] === 'h' ? $image->height() : $image->width()) * $share;
		}

		if (!is_numeric($value) || (float)$value <= 0) {
			return null;
		}

		$dpr = $this->getParam('dpr');

		return (float)$value * (is_numeric($dpr) && $dpr > 0 && $dpr <= 8 ? (float)$dpr : 1.0);
	}

	/**
	 * A block of color the size the layer asks for (the whole image by
	 * default). With `gradient` it is solid at that edge and clear at the
	 * other, drawn a line at a time so it works on either driver.
	 *
	 * @param array<array-key,mixed> $layer
	 */
	private function drawFill(ImageInterface $image, array $layer, Watermark $rules): ?ImageInterface
	{
		$width  = (int)round($rules->getDimension($image, 'markw') ?? $image->width());
		$height = (int)round($rules->getDimension($image, 'markh') ?? $image->height());
		if ($width < 1 || $height < 1) {
			return null;
		}

		[$r, $g, $b] = TextWatermarkStyle::parseColor((string)$layer['fill']);
		$fill        = $image->driver()->createImage($width, $height);
		$gradient    = (string)($layer['gradient'] ?? '');

		if ($gradient === '') {
			return $fill->fill(new Color($r, $g, $b));
		}

		$vertical = $gradient === 'top' || $gradient === 'bottom';
		$length   = $vertical ? $height : $width;
		$fromEnd  = $gradient === 'bottom' || $gradient === 'right';
		$native   = $fill->core()->native();
		// Intervention hands Imagick an opacity rounded to one decimal, which
		// draws a fade as about ten visible bands; draw those rows natively.
		$imagick = $native instanceof \Imagick ? $native : null;
		$draw    = $imagick instanceof \Imagick ? new \ImagickDraw() : null;

		for ($step = 0; $step < $length - 1; $step++) {
			// 1 at the solid edge, falling to 0 at the clear one.
			$opacity = 1 - ($step / ($length - 1));
			$at      = $fromEnd ? $length - 1 - $step : $step;
			$line    = $vertical ? [0, $at, $width, 1] : [$at, 0, 1, $height];

			$draw instanceof \ImagickDraw
				? $this->imagickLine($draw, $line, [$r, $g, $b], $opacity)
				: $this->line($fill, $line, [$r, $g, $b], $opacity);
		}

		if ($imagick instanceof \Imagick && $draw instanceof \ImagickDraw) {
			$imagick->drawImage($draw);
		}

		return $fill;
	}

	/**
	 * @param array{int,int,int,int} $line x, y, width, height
	 * @param array{int,int,int} $rgb
	 */
	private function imagickLine(\ImagickDraw $draw, array $line, array $rgb, float $opacity): void
	{
		[$x, $y, $w, $h] = $line;
		$draw->setFillColor(new \ImagickPixel(sprintf('rgba(%d,%d,%d,%.4F)', ...[...$rgb, $opacity])));
		$draw->rectangle($x, $y, $x + $w - 1, $y + $h - 1);
	}

	/**
	 * @param array{int,int,int,int} $line x, y, width, height
	 * @param array{int,int,int} $rgb
	 */
	private function line(ImageInterface $fill, array $line, array $rgb, float $opacity): void
	{
		[$x, $y, $w, $h] = $line;
		$fill->drawRectangle($x, $y, function (RectangleFactory $rectangle) use ($w, $h, $rgb, $opacity): void {
			$rectangle->size($w, $h);
			$rectangle->background(new Color(...[...$rgb, (int)round(255 * $opacity)]));
		});
	}

	/**
	 * The mark cut to the largest circle it holds, with a one-pixel soft
	 * edge. Intervention 3 has no mask, so each driver does it natively.
	 */
	private function circle(ImageInterface $mark): ImageInterface
	{
		$native = $mark->core()->native();
		$width  = $mark->width();
		$height = $mark->height();

		if ($native instanceof \Imagick) {
			$shape = new \Imagick();
			$shape->newImage($width, $height, new \ImagickPixel('transparent'));
			$draw = new \ImagickDraw();
			$draw->setFillColor(new \ImagickPixel('white'));
			$draw->circle(($width - 1) / 2, ($height - 1) / 2, ($width - 1) / 2, ($height - 1) / 2 - (min($width, $height) - 1) / 2);
			$shape->drawImage($draw);
			$native->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
			$native->compositeImage($shape, \Imagick::COMPOSITE_DSTIN, 0, 0);

			return $mark;
		}

		if (!$native instanceof \GdImage) {
			return $mark;
		}

		$gd = $native;
		if (!imageistruecolor($gd)) {
			imagepalettetotruecolor($gd);
		}

		imagealphablending($gd, false);
		imagesavealpha($gd, true);
		$centerX = ($width - 1) / 2;
		$centerY = ($height - 1) / 2;
		$radius  = min($width, $height) / 2;

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$outside = sqrt((($x - $centerX) ** 2) + (($y - $centerY) ** 2)) - $radius + 0.5;
				if ($outside <= 0) {
					continue;
				}

				$rgba  = imagecolorat($gd, $x, $y);
				$alpha = ($rgba >> 24) & 0x7F;
				// GD alpha: 0 opaque to 127 clear.
				$alpha = $outside >= 1 ? 127 : (int)round($alpha + ((127 - $alpha) * $outside));
				imagesetpixel($gd, $x, $y, ($alpha << 24) | ($rgba & 0xFFFFFF));
			}
		}

		return $mark;
	}
}
