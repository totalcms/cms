---
title: "SVG"
description: "Configure SVG field sanitization in Total CMS to prevent XSS attacks, with the option to disable svgclean for trusted content."
---

# SVG

Default all svgs will be sanitized to help prevent from XSS attacks.
You can disable this by setting the following.

```json
{
  "svgclean" : false
}
```
