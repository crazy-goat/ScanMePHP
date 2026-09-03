# Examples — the symbology gallery

This directory is one generator rather than a shelf of scripts:

```bash
php examples/gallery.php
```

It writes the gallery from whatever the registry in this checkout supports:

- `index.md` — every supported QR / barcode, one row each
- `codes/<symbology>.md` — that symbology's page: payload, capabilities,
  and **every renderer's output** (SVG, PNG, HTML div/table, ASCII ×3),
  with a renderer that cannot draw it faithfully saying so instead of being
  skipped
- `assets/<symbology>/` — the image files the pages point at

None of those are committed: a gallery is a claim about what the library
draws *right now*, so it is regenerated the same way the tests are run —
`tests/ExamplesTest.php` executes the generator on every CI build and fails
if it does not complete, and a second test asserts the pages it wrote really
cover every registered symbology and renderer.
