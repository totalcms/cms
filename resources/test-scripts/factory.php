<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use TotalCMS\Utils\FakerImage;

$faker = Faker\Factory::create();
$faker->addProvider(new FakerImage($faker));

FakerImage::$dir = __DIR__ . '/faker-images';

$objects  = [];
$quantity = 3;

$def = [
    'id'      => 'slug',
    'title'   => 'words|5,1',
    'summary' => 'paragraph',
    'content' => 'paragraphs|2,1',
    'tags'    => 'words',
    'color'   => 'hexColor',
    'date'    => 'iso8601',
    'image'   => 'imageText|1280,720,,200,,000000',
];

function parseFakerRule(string $rule): array
{
    $parts  = explode('|', $rule);
    $method = $parts[0];
    $args   = [];
    if (count($parts) > 1) {
        $args = preg_split('/\s*,\s*/', $parts[1]);
        if ($args === false) {
            $args = [];
        }
    }

    return [$method, $args];
}

for ($i = 0; $i < $quantity; $i++) {
    $object = [];
    foreach ($def as $key => $value) {
        [$method, $args] = parseFakerRule($value);
        if ($key === 'id') {
            // Make sure ID is unique
            $object[$key] = $faker->unique()->$method(...$args);
        }
        $object[$key] = $faker->$method(...$args);
    }
    $objects[] = $object;
}

print_r($objects);
