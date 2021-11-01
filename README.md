# Total CMS Overview

Total CMS is at its core an object data store. Objects are stored in a Collection. There are many built in collections with pre-defined schemas. However, there is the ability to define your own custom Business Object.

Objects can be anything from a simple piece of text or image all the way to something most complex like a blog post, product or podcast episode. You will be able to apply that data from a single object or an entire collection to your layout templates.

Total CMS will be installed on your server in order to provide a dynamic site experience. It will support a full REST API so that you can integrate with any other web service, including Zapier.

# Data Model

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


# Filesystem

By default all data for Total CMS is stored in 
a folder called `tcms-data` in DOCUMENT_ROOT. 
The location of this folder can be customized via the Total CMS configuration.

At the top level of this folder contains folders, 
one for each collection. 
The collection folder will contain a json file and
folder containing assets for each object.

JSON files are used to store all Total CMS data. 
JSON supports complex data structures and can be 
manually edited if there was a need.

## Collection Data

The following are the files and folder structures 
used to store the data for a collection

### .meta.json

This file is used to store some meta data about the collection.

* The name of the collection
* The schema that this collection conforms
* An optional URL that is the root URL for the object on the web. 
* This can be used to generate RSS feeds for blogs/podcasts or Sitemap files.

### .digest.json

This file will contain a digest (summary) of every 
object that is within the collection. 
This is useful for performance so that it is quicker 
to retrieve an entire collection.

Not every property is added to the digest for each object. 
This is to keep performance as streamlined as possible. 
The schema for a collection defines what properties 
are to be added to the digest.

### .index.json

Just like in a database, Total CMS will allow you to 
define specific properties that can be index for 
every object in a collection. 
You can define the properties to be indexed inside of the collection's schema.

Indexes are most useful when used with something 
like tags or categories. 

The index will store quick access to all objects 
that are associated with each object. 

For example, this will allow you to quickly access 
all blog posts that have a particular author or tag.

### Object json

All of an object's data is store in a JSON file 
that is named with the object's ID: `{id}.json`

This JSON file will contain all of the data for an object.

### Object Assets

All assets related to an object will be stored in 
sub-folders within the collection. 
There will be a folder named with the ID of the object. 
Within that folder, there will be a folder for the property 
of that object that stored the asset.

For example, if there was a blog post with a gallery property, 
the image would be stored at `my-blog-post/gallery/image.jpg`.

## Schemas

Schemas allow us to verify the integrity of the data 
before it's saved into the CMS. There are default schemas 
that ship with Total CMS. However, you can also define your own custom schemas.

Schemas are stored as JSON files using the standard JSON Schema format.

### Default Schemas

All schemas that ship with Total CMS are stored 
along with the source code in a schemas folder. 
These schemas cannot be changed via the API. 
You cannot create a custom collection with the 
same name as any of the default schemas.

### Custom Schemas

You can create a custom schema when you want 
to define a custom business object. 
These custom schemas can have as many of the 
supported properties as you want. 
You get to define your own indexed and which 
fields get added to the collection digest.

Custom schemas will be stored inside of a 
`.schemas` folder inside the `tams-data` directory.

# Licenses

Total CMS is licensed on a per domain basis. 
There are 3 different pricing tiers that will 
control what collections you will have access to.

Below list will list out the collections that particular license has access to.

## Lite

* date
* email
* id
* image
* number
* svg
* text
* toggle
* url

## Standard

Everything in Lite including:

* blog
* feed
* color
* depot
* file
* gallery
* list
* rating

## Professional

Everything in Standard including:

* podcast
* menu
* custom collection schemas

## Free Trial

You can install Total CMS and use it for 45days for free on any domain.

# Configuration

Configuration is done via a hierarchy of PHP files.

Config files load order....

1. config/defaults.php
2. env.php
3. config/{{env}}.php -> production.php, development.php, etc.
4. DOCUMENT_ROOT/env.php

# API

There are 2 ways to access data from Total CMS.

## REST

See the REST API documentation on all of the endpoints that are available.

### POST/GET

There are many APIs for getting, saving and updating objects, collections and schemas.

### ImageWorks

The original images are saved in side Total CMS. 
You can use the ImageWorks API to dynamically fetch 
an altered version of an image. 
With the ImageWorks API, you will be able to...

* Resize and image
* Crop an image
* Apply filters (b&w, sepia, etc)
* Apply a watermark

### Download

The download API will allow you to download any 
file uploaded into Total CMS. If a file is set to be protected, 
a passphrase will be required to access the file.

### Import

The ability to import data into Total CMS is vital. 
This will allow you to easily import data from existing sources into your website.

## PHP

You can include the Total CMS PHP classes and directly access the data.


