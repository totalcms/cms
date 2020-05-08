<?php

require_once 'dynamics.php';

$data = json_decode(file_get_contents("products.json"));
$schema = json_decode(file_get_contents("schema.json"));
$validator = new \League\JsonGuard\Validator($data, $schema);
