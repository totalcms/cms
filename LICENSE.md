# Total CMS License Agreement

Copyright (c) Aspect Services, LLC. All rights reserved.

Permission is hereby granted to any person obtaining a copy of this software (the "Software") to use, copy, modify, and merge copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

1. **Include this license.** The above copyright notice and this license shall be included in all copies or substantial portions of the Software.

2. **One license per project.** Each licensed copy of the Software shall be actively installed in no more than one production environment at a time.

3. **Do not alter licensing features.** Software features related to licensing shall not be altered or circumvented in any way, including (but not limited to) license validation, feature or edition restrictions, version authorization, and update eligibility.

4. **Do not redistribute.** The Software and the proprietary code therein, not limited to but including designs, components, classes, and patterns, may not be reused or redistributed in other projects without the express written consent of Aspect Services, LLC.

5. **Payment.** Payment shall be made in accordance with the terms presented at the time of purchase. Continued use of the Software beyond any trial period requires a valid, paid license.

6. **Follow the law.** All use of the Software shall not violate any applicable law or regulation, nor infringe the rights of any other person or entity.

Failure to comply with any of the above conditions will result in the immediate termination of all permissions granted by this license. This license does not guarantee the right to receive updates or technical support.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

## Third-Party Software

Total CMS bundles source code from the following third-party libraries that ship under their own licenses. These notices apply only to the bundled portions of those libraries and not to Total CMS itself.

### couleur

- **Location:** `src/Utils/Color/`
- **Upstream:** https://github.com/matthieumastadenis/couleur
- **License:** MIT

The bundled code is a substantially modified fork. The conversion math is preserved from upstream; the surrounding library structure has been reorganized to fit Total CMS conventions.

**Modifications by Joe Workman:**

- Enhanced OKLCH support (proper 360° hue wraparound, improved hex conversion)
- PHP 8.2+ / 8.4 compatibility
- Fixed 11 conversion bugs (divide-by-zero in HSL/HSV/HWB, undefined variables, missing parameters)
- Trimmed to 7 color spaces (HexRgb, Hsl, Rgb, LinRgb, OkLab, OkLch, XyzD65); 10 unused spaces removed
- Refactored namespaced functions into static method classes; no `autoload.files` dependency
- PSR-style PascalCase naming throughout
- Dedicated Pest test suite covering conversion paths, bug regressions, and OKLCH enhancements

```
MIT License

Copyright (c) 2022 Matthieu Masta Denis

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
