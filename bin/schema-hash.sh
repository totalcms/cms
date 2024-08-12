#!/bin/bash

# Directory containing the JSON files
DIRECTORY="resources/schemas"
# Output JSON file
OUTPUT_FILE="resources/installed"

# Initialize the JSON structure
echo "{" > $OUTPUT_FILE

# Loop through all JSON files in the directory
for FILE in "$DIRECTORY"/*.json; do
    # Get the filename without the directory path
    FILENAME=$(basename "$FILE" .json)
    # Calculate the SHA-256 hash of the file
    FILE_HASH=$(sha256sum "$FILE" | awk '{ print $1 }')
    # Append the filename and hash to the JSON structure
    echo "    \"$FILENAME\": \"$FILE_HASH\"," >> $OUTPUT_FILE
done

# Remove the last comma and close the JSON structure
sed '$ s/,$//' $OUTPUT_FILE > $OUTPUT_FILE.tmp && mv $OUTPUT_FILE.tmp $OUTPUT_FILE
echo "}" >> $OUTPUT_FILE

php bin/validate-schema.php

# Print a message indicating the script has completed
echo "Installed schema files written to $OUTPUT_FILE"