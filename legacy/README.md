# Legacy Versions

The code in this folder contains older versions of the Total CMS applications that have been managed in a completely different repository.


## v1

This version of Total CMS is actually the current production version that shipped to customers. This version of the application is very old. The code here is defintely not up to current standards. It does not use any sort of framework at all.

### Endpoints

Here is an overview of all of the various endpoints for this version of Total CMS.

#### totalapi.php

This is the REST API component of Total CMS. As you will see, it uses no frameworks. All of the data is parsed from `$_POST`. It's pretty horrible to be honest. But I thought it was super awesome when I developed it 6 years ago.

#### totalcms.php

This file provided access to all of the classes of the application.

#### totaldownload.php

This is an API endpoint that allows you to download a file saled inside Total CMS.

#### totalexport.php

This is a basic tool that allows you to export a blog to a CSV file.

#### totalimport.php

Basic tool that allows to export a blog to a CSV file.

#### totalpreview.php

You can safely ignore this. This is a special file that allows many aspects of this version of Total CMS to work inside RapidWeaver. Nothing like this will be needed in the new version.

### TotalCMS Application

Here is a brief overview of the classes stored in the `TotalCMS` folder.

#### Passport.php

This classes manages the licenses for Total CMS.

#### ReplaceText.php

With the absence of any sort of tempalting system in this version, I designed a system of macros that users could place anywhere inside of their HTML in order to insert content from within Total CMS. This class uses PHP buffers in order to process these macros within the HTML before sending data to the browser. This is a pretty successful system. I am currently unsure if these will be required in v3. Here are a few examples of macros...

* `%cmsData(cmsid)%` : Gets the raw contents from a Text component
* `%cmsTextFormat(cmsid)%` : Outputs a Text component and processes as Markdown
* `%cmsImage(cmsid)%` : The path to the full resolution image
* `%cmsImageThumb(cmsid)%` : The path to the thumbnail for an image
* `%blogTitle()%` : The title of the currently loaded blog post
* `%blogImageFeatured()%` : The path to the first featured image inside a blog post's gallery

### Components

There is a class for each type of dataset stored inside of Total CMS. All of them extend the base `Component.php` class. Each component has its own implemtation for how data gets stored into the CMS.

One nice feature is that every time data gets saved into the CMS, it will also get saved into a backup folder. The past 10 versions of every CMS object gets perserved. I never built any way of restoring this data. It has been a nice feature to have though.

### tests

There are phpunit tests build for this. Sadly, I have to admit that I have not maintained them over the years and I don't even know if they will pass any longer.




## v2

This verion of Total CMS is what currently powers most of the data behind <https://www.weavers.space/>. This version was never shipped to customers and is not feature complete. I felt that the achitecture was not right and this brings us to where we are now.

This version does not have most of the features found in v1. For example, you cannot manage a single piece of text or a single image. It only support custom objects that was not even possible in v1.

This version uses Slim 3. You will notice that things are called Dynamics. I was thinking of renaming Total CMS to Dynamics. I have now decided against that since Microsoft has a large product already called Dynamics and want any legal conflicts with names.

### Endpoints

There are 2 ways to access the CMS data.

#### api.php

This obvioulsy manages the REST API

#### local.php

This provides access to the PHP classes so that you can use them in your PHP code.

### Total CMS Application

The `Dynamics` folder is a traditional Slim3 app in how its structured.

### Components

This contains the logic for an Object. The `Fields` folder contains special classes for more complex fields.

### Controllers

#### BufferController

This controller manages the PHP buffer for the macros. The macros have an upgraded syntax in v2. They support options now. Not all macros were fully implemented.

```
// Global Text
%cmsText(cmsId)%
%cmsText(cmsId,{options:true})% // With optional options JSON dictionary

// Object Property as Text
%cmsText(collection,property)%
%cmsText(collection,property,{options:true})% // With optional options JSON dictionary

// Object Property + ID as Text
%cmsText(collection,id,property)%
%cmsText(collection,id,property,{options:true})% // With optional options JSON dictionary
```

#### CollectionsController

As you may assumed, this controller manages the collections of objects.

#### ImageWorksController

The Image Works API is an image API that sits on top of Glide in order for provide dynamic image resizing and filters for any image stored inside of Total CMS.

#### ImportController

This handles importing of data. Currently only the CSV import is functional.

#### SchemaController

This controller manages schemas for collections.

#### TemplatesController

This is a simple controller to get premade templates made in the CMS. This was mostly just a concept and not fully implemented. The concept was mostly for the Admin side of the cms. It would be able to provide HTML templates for forms to quickly create and update CMS object data.

