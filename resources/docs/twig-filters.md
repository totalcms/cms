## Filters


### Text

```
humanize(string $slug, string $sep = '-'): string
titleize(string $slug, string $sep = '-'): string
basename(string $file): string
dirname(string $file): string
rtrim(string $string): string
ltrim(string $string): string
truncate(string $string, int $length, bool $keepWords = false): string
truncateWords(string $string, int $length): string
charcount(string $text): int
wordcount(string $text): int
readtime(string $text, int $wpm = 180): float
```

### Colors

```
hexToColor(string $hex): array
hex(array $color): string
rgb(array $color, int $alpha = 100, bool $wrap = true): string
hsl(array $color, int $alpha = 100, bool $wrap = true): string
oklch(array $color, int $alpha = 100, bool $wrap = true): string
lightness(array $color, string $lightness): array
chroma(array $color, string $chroma): array
hue(array $color, string $hue): array
adjustColor(array $color, ?string $lightness = null, ?string $chroma = null, ?string $hue = null): array
```

### Arrays

```
count(array $array): int
ksort(array $array): array
krsort(array $array): array
shuffle(array $array): array
```


### Developer

```
typeof(mixed $variable): string
string(mixed $variable): string
int(mixed $variable): int
float(mixed $variable): float
bool(mixed $variable): bool
array(mixed $variable): array

json_decode(mixed $variable): array

print_r(mixed $variable): string
var_dump(mixed $variable): string
```

### Filter and Sort Collections

```
{% set objects = cms.objects("blog") | filterCollection([
	{
		property : "image.size",
		operator : "lt",
		value    : getParams.size ?? ""
	},
]) | sortCollection([
	{
		shuffle  : true,
	},
]) %}
```