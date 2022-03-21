<?php

require '../dynamics/autoload.php';
use Dynamics\Components\Fields\Color;

$logger = new Monolog\Logger('color');

$color = new Color('', $logger);
echo $color->hex() . PHP_EOL;
echo $color->rgb() . PHP_EOL;
echo $color->hsl() . PHP_EOL;
echo $color->toJson() . PHP_EOL;
echo PHP_EOL;

$color = new Color(json_decode('{"hex":"f8f8f8","alpha":0.5,"rgb":[248,248,248],"hsl":[0,0,97.2549]}', true), $logger);
echo $color->hex() . PHP_EOL;
echo $color->rgb() . PHP_EOL;
echo $color->hsl() . PHP_EOL;
echo $color->toJson() . PHP_EOL;
echo PHP_EOL;

$color = new Color('rgba(245,124,45,0.5)', $logger);
echo $color->hex() . PHP_EOL;
echo $color->rgb() . PHP_EOL;
echo $color->hsl() . PHP_EOL;
echo $color->toJson() . PHP_EOL;
echo PHP_EOL;

$color = new Color('rgba(245,124,45,0.5)', $logger);
echo $color->hex() . PHP_EOL;
echo $color->rgb() . PHP_EOL;
echo $color->hsl() . PHP_EOL;
echo $color->toJson() . PHP_EOL;
echo PHP_EOL;

$color = new Color('hsl(145, 45%, 10%)', $logger);
echo $color->hex() . PHP_EOL;
echo $color->rgb() . PHP_EOL;
echo $color->hsl() . PHP_EOL;
echo $color->toJson() . PHP_EOL;
echo PHP_EOL;

echo json_encode($color) . PHP_EOL;
echo PHP_EOL;

$color = new Color('#32a273', $logger);
echo $color->hex() . PHP_EOL;
echo $color->rgb() . PHP_EOL;
echo $color->hsl() . PHP_EOL;
echo $color->toJson() . PHP_EOL;
echo PHP_EOL;

$complement = $color->complement();
echo $complement->hex() . PHP_EOL;
echo $complement->rgb() . PHP_EOL;
echo $complement->hsl() . PHP_EOL;
echo $complement->toJson() . PHP_EOL;
echo PHP_EOL;

$invert = $color->invert();
echo $invert->hex() . PHP_EOL;
echo $invert->rgb() . PHP_EOL;
echo $invert->hsl() . PHP_EOL;
echo $invert->toJson() . PHP_EOL;
echo PHP_EOL;

$ci = $invert->complement();
echo $ci->hex() . PHP_EOL;
echo $ci->rgb() . PHP_EOL;
echo $ci->hsl() . PHP_EOL;
echo $ci->toJson() . PHP_EOL;
echo PHP_EOL;
