# PHP API

* Custom templates should go into tcms-data/templates
* Global templates that can be used
  * totalform.twig



Top of page

```php
require_once __DIR__ . '/../vendor/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
$totalcms->startBuffer(); // Start output buffering
```

send parts of the page to reduce TTFB

```php
// Optional: Send page head asap to reduce TTFB (Time to First Byte)
echo $totalcms->processBufferMacros();
$totalcms->startBuffer(); // Start output buffering again
```

very bottom of page
```php
// Get the output buffer and process twig template
echo $totalcms->processBufferMacros();
```

Sample twig templates.


```twig
{% set objects = cms.objects("text") %}

{% for object in objects %}
<h1>{{ object.id }}</h1>
{% endfor %}

<!-- Get index of a property from a collection. Ex: list of all categories -->
{% for id in cms.property("text", "id") %}
	<span class="label">{{ id|upper }}</span>
{% endfor %}

<!-- Get Object -->
{% set page = cms.object("page", "about") %}
<h1>{{ page.title }}</h1>
<p>{{ page.desc }}</p>

<!-- Get Text -->
<h3>{{ cms.text("mytext") }}</h3>
<h3>{{ cms.data("text", "mytext", "id") }}</h3>

<!-- Depot -->
{% for file in cms.depot("mydepot") %}
	<h1>{{ file.name }}</h1>
	<p>{{ file.uploadDate | date('c') }}</p>
{% endfor %}

<!-- Image -->
<img src="{{ cms.imagePath('myimage',{w:600,h:500}) }}" alt="{{ cms.alt('myimage') }}">
```