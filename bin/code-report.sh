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
	"tests"
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

	if [ "$type" = "tests" ]; then
		# Only look in tests folder for tests
		files=$(find "tests" -type f -name "*.php" 2>/dev/null)
		for file in $files; do
			((count++))
			((total++))
		done
	else
		# Look in all folders except tests for regular types
		for folder in "${folders[@]}"; do
			if [ "$folder" != "tests" ]; then
				files=$(find "$folder" -type f -name "*.$type" 2>/dev/null)
				for file in $files; do
					((count++))
					((total++))
				done
			fi
		done
	fi

	printf "\t%-8s: %s\n" "$type" "$count"

done
echo "
Total Files: $total"

echo "
Code Line Counts:"
total=0

for type in "${types[@]}"; do

	count=0

	if [ "$type" = "tests" ]; then
		# Only look in tests folder for tests
		files=$(find "tests" -type f -name "*.php" 2>/dev/null)
		for file in $files; do
			lines=$(filter_lines "$file" | wc -l)
			((count+=$lines))
			((total+=$lines))
		done
	else
		# Look in all folders except tests for regular types
		for folder in "${folders[@]}"; do
			if [ "$folder" != "tests" ]; then
				files=$(find "$folder" -type f -name "*.$type" 2>/dev/null)
				for file in $files; do
					lines=$(filter_lines "$file" | wc -l)
					((count+=$lines))
					((total+=$lines))
				done
			fi
		done
	fi

	printf "\t%-8s: %s\n" "$type" "$count"

done
echo "
Total Lines of Code: $total"

echo "
Distribution Stats:"
if [ -d "dist" ]; then
	dist_files=$(find "dist" -type f | wc -l)
	printf "\tDist Files: %s\n" "$dist_files"
else
	printf "\tDist Files: N/A (dist folder not found)\n"
fi
