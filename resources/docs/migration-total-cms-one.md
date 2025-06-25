# Migration of Total CMS v1 Data

If you look at the filesystem of Total CMS 1, you will see that it's quite different. The data is always stored at
DOCROOT/cms-data. Below we will look at all of the folders that need to be imported and how they are to be migrated
into Total CMS 3.

Every import job for this should be added to the job import queue.

Its possible that not all of these folders will exist. It depends on what features the user was using.

TODO: Update the image and gallery upload process to add alt tags from .cms or .txt files.

## blog

The blog folder contains blogs. This is the closest that we have to a collection in Total CMS 3.
Inside the "blog" folder there can be multiple blog folders. Each of these sub-folders will be imported as a collection
into Total CMS 3. These collections need to have a schema of "blog-legacy". The blog-legacy schema is a special schema
that is designed for backwards support for Total CMS 1 blogs.

The collection id should be the name of the folder inside "blog". So if there is a folder "blog/myblog". Then the
collection id of that blog should be "myblog".

From now on, when I reference the "blog folder", I am referring to the subfolder that contains the blog content that
needs to be migrated.

There is a special file inside of the blog folder called "myblog.posturl" where the myblog is the name of
the collection. This file is a text file that contains a URL. This URL should be saved as the "url" of the blog
collection. If this url does not contain "?permalink=" then you need to also set the "prettyUrl" attribute of the
collection to true.

Now inside the blog folder, there are many *.cms files. These files are the blog posts. They are JSON files. The data
should map almost exactly to the blog-legacy schema. This is the data that needs to be imported as the blog post.
There are a few exceptions.

* The permalink field should be renamed to be the id.
* We may want to clear out the image and gallery fields as this data will get reprocessed when the files upload.

At this point the blog post can be added to the import queue.

Lets talk about where the image and gallery files are that will need to be uplaoded for this blog post.

Inside the blog folder, you will see a folder for the ID of the blog post that was just processed. Inside of that
folder, that should be an image folder. This folder contains the image for the blog post. The image should have
a base file name equal to the blog post id. You can add an import job to import the path to this image as the blog post
"image" property.

The gallery images for this blog post should be contained in a completely different location. They can be found at
`cms-data/gallery/blog/myblog/id`, where `myblog` is the name of the collection and `id` is the id of the blog post.
You can add the path of this folder to the import queue as the "gallery" of this blog post.

## date

If this folder exists, then there will be a cms files inside. The basename of these files will be the ID of objects
added to the "date" collection. If the "date" collection does not exist, we can create it and assign that to the "date"
schema.

These data `.cms` files will contain the date that will be stored into the "date" property of the new object. However,
the date will need to be converted since the date is stored as a unix timestamp. It will need to be converted to
ISO8601 formatted date.

## depot

The depot folder contains depots. Inside the "depot" folder there can be multiple depot object folders.
Each of these sub-folders will be imported as a depot object inside the "depot" collection. If the "depot"
collection does not exist, we can create it and assign that to the "depot" schema.

The depot object id should be the name of the folder inside "depot". So if there is a folder "depot/mydepot".
Then the depot object id should be "mydepot".

You can add the path of this folder to the import queue as the "depot" of this depot object.


## feed

The feed folder contains feeds. Inside the "feed" folder there can be multiple feed folders.
Each of these sub-folders will be imported as a collection into Total CMS 3. These collections need to have a schema
of "feed" and the id of the collection should be the name of the subfolder.

From now on, when I reference the "feed folder", I am referring to the subfolder that contains the feed content.

Inside the individual feed folder, you will find many `.cms` files. These are just text files. The basename of the
`.cms` file will be the ID of the object added to the new feed collection. It should also be added to the "title"
property as well. The contents of the `.cms` file should be added to the "content" property of the object.

The image for each feed object can be found at `cms-data/gallery/feed-{id}/feed-{id}.jpg` where `{id}` is the ID of
the object.

## file

The file folder will contain files. Each file inside this folder should be added to the "file" collection. If the
"file" collection does not exist, then it should be created and assigned to the "file" schema.

Each file should be uploaded to the file collection. The basename of the file should be used as the ID of the object.
The file should be uploaded to the "file" property of the object.

## gallery

The gallery folder contains galleries. Inside the "gallery" folder there can be multiple gallery folders. However,
you can ignore the "blog" folder and any folder starting with "feed-". These are not galleries and used for other
purposes already descirbed. All of the other folders should be added as a new gallery object to the "gallery"
collection. If the "gallery" collection does not exist, then it should be created and assigned to the "gallery"
schema.

The id of the gallery object should be the name of the folder inside "gallery". So if there is a folder "gallery/mygallery".
Then the gallery object id should be "mygallery". You can add the path of this folder to the import queue as the
"gallery" property of this object.

## image

The image folder will contain images. Each image inside this folder should be added to the "image" collection. If the
"image" collection does not exist, then it should be created and assigned to the "image" schema.

Each image should be uploaded to the image collection. The basename of the image should be used as the ID of the object.
The image should be uploaded to the "image" property of the object.

## text

If this folder exists, then there will be a cms files inside. The basename of these files will be the ID of objects
added to the "text" collection. If the "text" collection does not exist, we can create it and assign that to the "text"
schema.

The contents of the text file should be added to the "text" property of the object.

## video

The video folder will contain videos. Each video inside this folder should be added to the "url" collection. If the
"url" collection does not exist, then it should be created and assigned to the "url" schema.

The contents of the file should be added to the "url" property of the object.
