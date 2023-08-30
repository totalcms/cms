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

* **name**: The name of the collection
* **schema**: The schema that this collection conforms
* **url**: An optional URL that is the root URL for the object on the web.
  * This can be used to generate RSS feeds for blogs/podcasts or Sitemap files.
* **forms:defaults**: These are the default form labels, help text and field type
* **forms:custom**: This allows you to override the default form values for a specific object ID.

For built-in schemas the defaults are imported from the schema file

```json
{
    "name"   : "text",
    "schema" : "text",
    "url"    : "",
    "form"  : {
        "defaults": {
            "id" : {
                "label" : "Text ID",
                "help"  : "A unique ID for this text. No spaces or special characters.",
                "type"  : "permalink"
            },
            "text" : {
                "label" : "Text",
                "help"  : "Edit the text here.",
                "type"  : "text"
            }
        },
        "custom" : {
            "myoptions" : {
                "text" : {
                    "label"   : "Selectable Text Options",
                    "help"    : "Select one of the options.",
                    "type"    : "select",
                    "settings" : {
                        "options" : [
                            "Option 1",
                            "Option 2",
                            "Option 3",
                        ]
                    }
                }
            },
            "aboutme" : {
                "text" : {
                    "label" : "About Me",
                    "help"  : "Write a little bit about yourself.",
                    "type"  : "styledtext"
                }
            }
        }
    },
    "factory" : {
        "id"   : "slug",
        "text" : "paragraph"
    }
}
```

### .index.json

Just like in a database, Total CMS will allow you to
define specific properties that can be index for
every object in a collection.
You can define the properties to be indexed inside of the collection's schema.

This file will contain a summary of every
object that is within the collection.
This is useful for performance so that it is quicker
to retrieve an entire collection.

Not every property is added to the summary for each object.
This is to keep performance as streamlined as possible.
The schema for a collection defines what properties
are to be added to the summary.

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
