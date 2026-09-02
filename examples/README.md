# Examples

Each file is runnable on its own and prints what it is doing:

```bash
php examples/01_quickstart.php
```

| File | What it covers |
| --- | --- |
| `01_quickstart.php` | The one call this library is built around |
| `02_symbologies.php` | Every symbology, its payload rules, and how the family relates |
| `03_output_formats.php` | Every output format and what each is good for |
| `04_options.php` | Generator options change the symbol; render options change the picture |
| `05_files_and_web.php` | Files, data URIs, and serving a symbol over HTTP |
| `06_compatibility.php` | What happens when a symbology and a renderer do not fit |
| `07_extending.php` | Your own renderer, symbology and encoding backend |

Files written by the examples land in `generated-assets/`, which is
regenerated rather than committed — a checked-in artefact nobody regenerates
goes stale exactly the way documentation does.

`tests/ExamplesTest.php` runs all of these on every CI build and fails if any
of them exits non-zero. That is the only reason to trust the code on this page:
an example that nothing executes is a claim, not a fact.
