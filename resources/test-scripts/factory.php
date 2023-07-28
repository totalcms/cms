<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use TotalCMS\Utils\FakerImage;

$faker = Faker\Factory::create();
$faker->addProvider(new FakerImage($faker));

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
    'image'   => 'image|1280,720',
];

function imageGDRule(array $args): array
{
    global $faker;

    return [
        $args[0],
        intval($args[1] ?? $faker->numberBetween(600, 800)),
        intval($args[2] ?? $faker->numberBetween(400, 600)),
        empty($args[3]) ? strtoupper(substr($faker->word(), 0, rand(1, 6))) : $args[2],
        intval($args[4] ?? 100),
        $args[5] ?? $faker->hexColor,
        $args[6] ?? '#f8f8f8',
    ];
}

function imageRule(array $args): array
{
    array_unshift($args, __DIR__ . '/faker-images');

    return $args;
}

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
        if (str_starts_with($method, 'image')) {
            $args = imageRule($args);
        }
        if ($method === 'imageText') {
            $args = imageGDRule($args);
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
