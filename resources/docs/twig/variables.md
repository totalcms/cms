---
title: "GET Parameter Data"
description: "Access URL query parameters, POST request data, and session variables in Total CMS Twig templates using getData, postData, and sessionData."
---


## GET Parameter Data

You can get the values of any url paramater passed to a page.

`Example: example.com/?paramName=value`


```
{{ getData.paramName }}
```

## POST Request Data

You can get any data passed to a page through the `$_POST` php variable.

```
{{ postData.paramName }}
```

## Session Data

You can get any data passed to a page through the `$_SESSION` php variable.

```
{{ sessionData.paramName }}
```

If the variable name contains a hyphen, use bracket notation instead:

```
{{ sessionData['agent-location'] }}
```
