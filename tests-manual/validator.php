<?php

require_once '../../vendor/autoload.php';

use Opis\JsonSchema\Validator;

$data   = file_get_contents('../../schemas/blog.json');
$schema = file_get_contents('../../schemas/schema.json');

// $data = json_decode('{"name": "opis"}');
// $schema = (object) [
//     '$id' => 'http://example.com/schema.json',
//     'type' => 'object',
//     'properties' => (object)[
//         'name' => (object)[
//             'type' => 'string',
//             'minLength' => 1,
//             'maxLength' => 100,
//         ]
//     ],
//     'required' => ['name']
// ];

// Create a new validator
$validator = new Validator();
$result    = $validator->validate($data, $schema);

if ($result->isValid()) {
    echo 'The data is valid';
} else {
    echo 'The data is invalid';
}
