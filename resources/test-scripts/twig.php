<?php

$passcode = <<<PASSWORDS
1234
PASSWORDS;

$request   = "MTIzNHA4ZzNzOGYzczhsdA==";
$passwords = preg_split('/\s+/', $passcode, -1, PREG_SPLIT_NO_EMPTY);
$master    = array();

foreach ($passwords as $password) {
	$master[] = base64_encode($password.'p8g3s8f3s8lt');
}

if (in_array($request, $master)) {
	echo "found";
}