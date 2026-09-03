# Examples — the symbology gallery

This directory is a gallery rather than a shelf of scripts, and it is built
by one generator:

```bash
php examples/gallery.php
```

It writes, from whatever the registry in this checkout supports:

- `index.md` — every supported QR / barcode, one row each
- `codes/<symbology>.md` — that symbology's page: payload, capabilities,
  and **every renderer's output** (SVG, PNG, HTML div/table, ASCII ×3),
  with a renderer that cannot draw it faithfully saying so instead of being
  skipped
- `assets/<symbology>/` — the image files the pages point at

The generated pages are committed, so a reader browsing the repository sees
the gallery without running anything. That convenience is also a hazard: a
committed gallery nobody regenerates goes stale exactly the way
documentation does, quietly showing last month's library. So
`tests/ExamplesTest.php` runs the generator on every CI build and fails if
the committed files differ from what it just wrote — a symbology added to
`Defaults` turns the build red until its page is regenerated and committed.
