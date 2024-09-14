#!/bin/bash

folders=(
	"bin"
	"config"
	"css"
	"javascript"
	"resources"
	"src"
	"tests"
)

types=(
	"php"
	"js"
	"scss"
	"json"
	"twig"
	"html"
	"sh"
)

filter_lines() {
	local file=$1
	grep -vE '^\s*(#|\{#|//|/\*|\*/|\*)' "$file"
}

echo "
File Counts:"
total=0

for type in "${types[@]}"; do

	count=0

	for folder in "${folders[@]}"; do
		php_files=$(find "$folder" -type f -name "*.$type")
		for file in $php_files; do
			((count++))
			((total++))
		done
	done

	echo "	$type	: $count"

done
echo "
Total Files: $total"

echo "
Code Line Counts:"
total=0

for type in "${types[@]}"; do

	count=0

	for folder in "${folders[@]}"; do
		php_files=$(find "$folder" -type f -name "*.$type")
		for file in $php_files; do
			lines=$(filter_lines "$file" | wc -l)
			((count+=$lines))
			((total+=$lines))
		done
	done

	echo "	$type	: $count"

done
echo "
Total Lines of Code: $total"
