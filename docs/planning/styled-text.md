The Styled Text editor currently uses the Froala editor. For licensing reasons we need to move off of that an over to a
new editor. I would like to build an editor that really integrates well with T3 and feels a part of the system.
However, instead of starting completely from scratch I woud like to use tiptap.dev as a starting point. it seems like
a good framework for building an editor.

# Features

We need all of the basic features that you might expect an editor to have.

* Bold, Italic, etc
* Lists
* Links
* Tables
* Images
* Video embed (auto embed for popular services + html5 video upload)
* Headers
* HTML code view
* Customizable toolbar

Other features that I would like to be configurable. These are the options from Froala that I would like to mimc.
However, they do not need to be exactly the same names/setup. I am happy to make they easier for what we need.

* charCounterCount
* charCounterMax
* wordCounterCount
* wordCounterMax
* colorsBackground
* colorsText
* fileUpload
* fontFamily ???? Or is forcing to use classes better?
* fontSize (array of 10px, 14px, 1.5em, etc)
* inlineStyles
* inlineClasses

# HTML View

One thing that I do not like about the Froala editor is that if you add HTML into the code view, the editor strips
that out. I would love our editor to allow advanced users to be able to add their own HTML and have it remain in place
This may need to be an option? I could be on board with that.

# Image

I do not think we need the custom froala API routes and we could use the standard file upload API routes.
In the settings for images, I want to be able to define a preset that will be applied to all images displayed
in the styled text. This will allow them to be updated after the fact. We do this already with Froala as well.
We defintely need some of the image alignment settings and floats. Image captions are important for customers
so we defintely need a way to add those. Either through the editor or through using the alt tag. Could we use
image info editor that we use in images and gallery that would allow users to edit exif and focal point, etc? Not sure
if that would actually be useful though. Support for all the the image upload rules that we have for image and gallery
already would be nice.

# Files

Like images, we can remove the froala specific upload point and use the standard file upload APIs.
We can use the file uploads rule sets that we already have for file and depot as well.

# Custom Elements

I would love to have the ability for customers be able to add custom snippets that could be added from the toolbar.
the ability to create a scroll anchor was requested by a customer. Maybe the tiptap ID pluign could be used for that,
if not this feature. But the ability to add buttons or other components would be welcome.

Customer would love to be able to add Columns. Not sure how or if that would be useful to implement. that feels like it
goes into the design space more than I would like T3 to be.

# Markdown

Tiptap has a markdown plugin so that I can type in "##" and it creates an H2 in the editor. I love that and defintely
want to make sure we have that.

This functionality may allow us to have a markdown field in the future?

# Backwards compatibility

I do not care for backwards compatibility at this point. Customers knew this change was coming.

# Setting Presets

It would be great to allow users to create presets of what they want their styled text setups to be like. This way
they can create a handful of styled text setups that they can reuse throughout their fields.

This may be a good generic option to have across all fields. Settings presets that can be easily reused acorss any field.
Exactly how Imageworks does it for displaying images.

# Icons

let me know what icons you need and I will get them. I have acuired all of my icons through Nucleo. I am not sure if you
can access those icons locally through the app. I can get whatever you need and we can add them into the icons css.

# SVG Field

The froala editor is also used for the SVG field. After finalizing the Styled Text field, we will work on creating a
brand new SVG field as well.
