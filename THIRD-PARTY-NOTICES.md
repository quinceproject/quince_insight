# Third-party notices

Quince Insight ships the files listed below. They are not covered by the module's Open
Software License 3.0. Each stays under its own terms, given here.

| Component | Version | Files in this module | Terms |
|-----------|---------|----------------------|-------|
| [Alpine.js](https://alpinejs.dev/) | 3.15.12 | `views/js/alpine.min.js` | MIT |
| [Google product taxonomy](https://support.google.com/merchants/answer/6324436) | as published by Google | `data/google_taxonomy/taxonomy-with-ids.*.txt` | provided by Google for merchants; © Google |
| [Lucide](https://lucide.dev/), 2 icons (heart, arrow-right) | | `views/templates/admin/_macros/icons.html.twig` | ISC |
| [Instrument Sans](https://github.com/Instrument/instrument-sans), Copyright 2022 The Instrument Sans Project Authors | | `views/fonts/instrument-sans-*.woff2` | SIL Open Font License 1.1, full text in `views/fonts/OFL-instrument-sans.txt` |

Alpine.js is used only on the module's back office screens. The taxonomy files are the
category lists Google publishes for product data; the module reads them to let the
merchant pick a Google product category.

---

## Alpine.js

```
MIT License

Copyright © 2019-2025 Caleb Porzio and contributors

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

## Lucide

```
ISC License

Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as part of Feather (MIT). All other copyright (c) for Lucide are held by Lucide Contributors 2022.

Permission to use, copy, modify, and/or distribute this software for any
purpose with or without fee is hereby granted, provided that the above
copyright notice and this permission notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
```
