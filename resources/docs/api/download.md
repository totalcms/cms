# Downloading Files

Total CMS provides several methods for downloading files and exporting content from your collections. This guide covers all available download options.

## File Downloads

### Direct File Downloads

For files stored in File or Depot collections, you can provide direct download links:

```twig
{# Get a file from the collection #}
{% set document = cms.file('my-document') %}

{# Create a download link #}
<a href="{{ cms.download(document.id) }}" download>Download {{ document.title }}</a>
```

### Download Tracking

The download counts for every file are tracked automatically. You can access the download count from
within the file admin view.

You can also access this data from twig.

```twig
{# Display download count #}
File Download: {{ file.count }}
```

## Image Downloads

### Original Images

Download original uploaded images:

```twig
{% set photo = api('image', 'header-photo') %}
<a href="{{ photo.image }}" download="{{ photo.title }}.jpg">
  Download Original
</a>
```

### Processed Images

Download images with specific dimensions or processing:

```twig
{# Download resized image #}
<a href="{{ cms.media.imagePath('myimage', {w:1920}) }}"
   download="wallpaper.jpg">
  Download Wallpaper (1920x1080)
</a>

{# Download watermarked image #}
<a href="{{ cms.media.imagePath('myimage', {w:600,mark:'logo.png'}) }}"
   download>
  Download with Watermark
</a>
```

## Security Considerations

### Protected Downloads

Total CMS allows you to password protect your downloads. You can set permissions for who can download files.
When the file is attempted to be downloaded, the user will be prompted for the password.

You also have the abiliy to generate donwload links with the password embeded in the URL.
