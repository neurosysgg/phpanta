# History

How the framework got the way it is. The documents one level up describe what is true **now** and
why; this directory keeps the stories they used to carry — the bug that was found, the argument that
was reversed, the count that moved.

Phpanta was extracted from neuro.SYS, and most of its history happened there, before it had a name.
The topics below are the framework's own: two moved with it, and the admin's has been kept here
since it became the admin; the rest — coverage, security, the API before that, hosting, the front end — stays in [neuro.SYS's history](https://github.com/neurosysgg/neurosys-webspace/blob/master/docs/history/README.md),
where it happened.

A rule that exists because of one of these stories is still stated in the current document, as one
present-tense sentence with a link back here. Nothing here is required reading to change the code
safely; if it ever becomes so, the rule it carries belongs in a current document instead.

## The format

One file per topic. Inside it, a `##` heading per subject and a `###` entry per event, oldest first,
headed with a date and, where one is cheap to find, the commit:

```markdown
## Coverage

### 2026-09-08 — the `/update` work did not close its own lines (`791fbc4`)

The text as it stood in the current document, moved rather than rewritten.
```

Moved text keeps its wording. Where a passage only makes sense next to the paragraph it was cut from,
a sentence of context is added in front of it rather than the passage being rephrased.

## Topics

| File | Covers |
|---|---|
| [types.md](types.md) | collections, exceptions, `Config` (now the app), `SitePath` (now `Path`), `File`, and the guidelines' first run |
| [markup.md](markup.md) | the markup tree: attributes, the scheme check, `RawHtml` becoming `MarkupParser` |
| [admin.md](admin.md) | `/api` becoming `/admin`, and the indistinguishability it gave up for uniformity; a browser let in by passkey |
