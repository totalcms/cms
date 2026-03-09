---
title: "Importing Data"
description: "Import data into Total CMS collections from CSV and JSON files, update existing objects, and process images and files via the job queue."
---

# Importing Data

## Update Existing Objects

By default, the import forms below will only import new objects will be created from the data.
Any data for existing objects will be ignored.

When the update checkbox is enabled, the data will only update existing objects in the collection.
You can provide as many or as little properties as you want to update objects in the collection.
For example, if you would like to only update one property in a set of objects, you only need to provide
the `id` field and that one property in the data.

## Queue Jobs for Import

If you are importing a lot of data, especially with images or large files, it is recommended to
use queue the import so that it happens in the background. This will give you the best chance for
all of your data to be imported the quickest way possible. This does mean that you will be required
to setup the below scheduled job in order to process the job queue for you in the background.

## Job Processor Scheduled Job

If you are using the queue import option, you will need to setup a scheduled job to process the queue.
Setup the following schedule job in your hosting company's management panel or crontab. We recommend
that this job be scheduled to run every 10 minutes. However, the time can be adjusted to your needs.

```
php <install_dir>/resources/bin/processJobs.php --docroot=/home/username/websites/example.com
```

You can head over the to [Job Queue Manager](/admin/utils/jobqueue) page. It has a utility that
will assist you in getting the exact command that you need to run.

## CSV Files

CSV files are a common format for importing data. The first row of the file should contain the
property names. These should match the property names of the object you are importing. Any column
names that do not match a property name will be ignored. The remaining rows should contain the data
for the objects. Each row will be imported as a new object.

### List Data

List data is a special type of data that is used to store multiple values in a single property. In
a CSV file, you can supply a comma delimtied list.

### Color Data

Total CMS stores color data in OKLCH format. However, you may supply hex color formats in your data
and Total CMS will convert them to OKLCH format for you.

## JSON Data

The main stucture of the JSON document should be an array of objects. Each object will be imported as a new
object. The properties of the object should match the property names of the object you are importing.

## Importing Images and Files

Since the supported import formats (CSV and JSON) are text based files, these cannot contain any sort of
binary file. However, Total CMS does support importing images and files. To do this, you will need to
uplaod the file to your server and place the path to that file as the value in your data. Since both
galleries and depot support many files, you will instead supply the path to a folder.

Here is an overview how all file based properties will behave.

* **Image**: Supply the full path to the image. The image will be imported into the object. All meta data
and color info will be extracted from the image just as if you were to upload the image through the
Total CMS admin interface.
* **File**: Supply the full path to the file. The file will be imported into the object. All meta data
will be extracted from the file just as if you were to upload the file through the Total CMS admin
interface.
* **Gallery**: Supply the full path to the folder. All images in that folder will be imported into the
gallery. Just like images, all meta data and color info will be extracted from the images.
* **Depot**: Supply the full path to the folder. All files in that folder will be imported into the depot.
While depot does support nested folders, only the files in the top level folder will be imported.
All meta data will be extracted from the files.
