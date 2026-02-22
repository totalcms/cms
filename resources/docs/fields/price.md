# Price Field

Price fields are specialized number fields for monetary values. They automatically have a step of `0.01` (hardcoded) for proper decimal handling and display with a currency icon.

## Currency Icons

Price fields display with a dollar sign icon by default. You can change the currency icon using the `class` setting with one of the supported currency icon classes:

```json
{
	"class": "icon-dollar"
}
```

```json
{
	"class": "icon-euro"
}
```

```json
{
	"class": "icon-pound"
}
```

```json
{
	"class": "icon-yen"
}
```
