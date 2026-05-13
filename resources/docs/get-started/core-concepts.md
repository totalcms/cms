---
title: "Core Concepts"
description: "Understand the Total CMS flat-file data model with 13 collection types, 16 property types, and JSON-based object storage without a database."
related:
  - schemas/reference
  - schemas/validation
  - twig/collections
---

# Core Concepts

Total CMS uses a flat file system in order to store its data, instead of a database. All data is saved in json files.

## Collections

A collection is a set of objects that conform to a defined schema.
There are 13 out of the box pre-defined collections.

* blog
* color
* date
* depot
* feed
* file
* gallery
* image
* number
* podcast
* svg
* text
* toggle
* url

## Objects

An object is an instance of a collection that conforms to a strict schema/definition.
An object consists of an ID and any number of properties defined inside
of this schema.

## Properties

Properties contain the data associated with an object.
A property can be one of the following types.

* color
* date
* depot
* email
* file
* gallery
* id
* image
* list
* menu
* number
* rating
* set
* svg
* text
* toggle
* url

### id

Every object must have a unique ID within a collection.
This ID is used to identify the object within the Total CMS filesystem and also online.
This ID will be used as a permalink/slug in order to reference it online.

### color

Colors are always stored as hsla(). This provides the best flexibility
for manipulating colors for the web.

### date

Dates are stored as ISO8601 formatted strings.

### depot

Depots are essentially a folder of files.
The data stored for a depot will be an array of file properties.
See the file property for what details will be collected.

### email

An email is store as text. What makes this different is that
the text is verified to be a properly formatted email address
before saving it into the CMS.

### file

A file is any file that a user want to upload.
It could be a zip, svg, dmg, etc.
Additional data is stored about each file that gets saved.

* passphrase: for password protecting a file via the download API
* size: the file size in kb
* filename: the name of the file
* ext: the file's extension
* label: A user generated label
* uploadDate: the date and time the file was uploaded
* tags: tags used for finding and filtering files

### gallery

A gallery is a set of images. The data stored for
a depot will be an array of image properties.
See the image property for what details will be collected.

### image

Along with an image being saved to the CMS. The following data is stored.

* alt: The alt text to be added to the image
* exif: The following EXIF data will be extracted from the image.
	* aperture
	* caption
	* copyright
	* date
	* exposureBias
	* focalLength
	* height
	* iso
	* latitude
	* longitude
	* make
	* model
	* rating
	* shutterspeed
	* title
	* width
* featured: set a featured toggle
* focalpoint: where is the focal point of an image so that it gets cropped in the correct location
* link: a URL that can be associated with an image
* palette: a sample color palette extracted from the image
* rating: a rating property
* tags: for filtering/sorting
* type: jpg, webp, png, etc
* uploadDate: the date and time of the upload

### list

A list is simply a set of strings.
These could be used for all sort of things like tags,
categories, or a list of associated object IDs from another collection.

### menu

This will allow you to build out a multi-level navigation
menu to be used on your website.

### number

Store a number into the CMS. This is similar to just storing text,
however, the content if verified to be a number.

### rating

This give you the ability to store a rating into the CMS.
A rating can contain the following data.

* rating: the current average rating of all ratings submitted
* max: the maximum rating that is allowed to be given
* total: the total number of ratings given
* counts: array of the subtotals for each rating.
* How many 1 star, 2 star, etc.

### set

A set is a collection of custom items that can
themselves have a predefined set of properties.
For example, a product can have multiple features.
Each feature can have an SVG, title and description.

### svg

SVG data is valid to be an SVG image before being saved into the CMS.

### text

This can be any text. It could be plain text for a header,
HTML generated from a WYSIWYG editor,
copyright data added to the footer, or SEO meta data.

### toggle

This is a boolean flag that will allow you to
define something as true or false.
You can use this to enable/disable sections on your
webpage or tons of other use cases.

### url

This is text data that is verified to be a URL.