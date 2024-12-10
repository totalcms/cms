# Docs for Field Options


## Simple list of options

```json
["Option 1", "Option 2", "Option 3"]
```

## Options with values

```json
[
	{"value" : "1", "label" : "Option 1"},
	{"value" : "2", "label" : "Option 2"},
	{"value" : "3", "label" : "Option 3"}
]
```

## Grouped options

```json
{
	"Group 1" : ["Option 1", "Option 2"],
	"Group 2" : ["Option 3", "Option 4"]
}
```

## Grouped options with values

```json
{
	"Group 1" : [
		{"value" : "1", "label" : "Option 1"},
		{"value" : "2", "label" : "Option 2"}
	],
	"Group 2" : [
		{"value" : "3", "label" : "Option 3"},
		{"value" : "4", "label" : "Option 4"}
	]
}
```
