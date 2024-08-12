<?php

$directory = __DIR__ . '/../resources/schemas'; // Directory containing the schema JSON files
$hashFile  = __DIR__ . '/../resources/installed'; // Output JSON file

// Read the JSON file with the expected hashes
if (!file_exists($hashFile)) {
	exit("Hash file $hashFile does not exist.\n");
}

$hashes = json_decode(file_get_contents($hashFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
	exit('Error decoding JSON file: ' . json_last_error_msg() . "\n");
}

// Verify each JSON file's hash
$allMatch = true;
foreach ($hashes as $filename => $expectedHash) {
	$filePath = "$directory/$filename.json";
	if (file_exists($filePath)) {
		$actualHash = hash_file('sha256', $filePath);
		if ($actualHash === $expectedHash) {
			// echo "File $filename.json matches the expected hash.\n";
		} else {
			echo "ERROR: File $filename.json does NOT match the expected hash.\n";
			$allMatch = false;
		}
	} else {
		echo "File $filename.json does not exist.\n";
		$allMatch = false;
	}
}

// Verify all files in the directory are accounted for in the hash file
if ($dh = opendir($directory)) {
	while (($file = readdir($dh)) !== false) {
		$filePath = "$directory/$file";
		if (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'json') {
			$fileNameWithoutExt = pathinfo($file, PATHINFO_FILENAME);
			if (!isset($hashes[$fileNameWithoutExt])) {
				echo "ERROR: File $fileNameWithoutExt.json is not accounted for in the hash file.\n";
				$allMatch = false;
			}
		}
	}
	closedir($dh);
}

if ($allMatch) {
	echo "All schema files match the expected hashes.\n";
} else {
	throw new Exception('ERROR: Some schema files do not match the expected hashes.');
}
