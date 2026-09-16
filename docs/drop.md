# The drop service

`drop` keeps a secret text or file on a deployment behind a link. The admin makes one — the signing
key from a terminal, or a browser an enrolled passkey let in — and whoever holds its link opens it at
`/drop`, in a browser or with `curl`. Nothing else can: the deployment keeps no link, and without one a
drop is bytes nobody can read, the deployment included.

It is a service of the admin like `machine`, switched on the same way, and `/drop` is the framework's
one address outside `/admin`. Everything the admin's own document says of a caller holds for making,
listing and taking away a drop — see [security.md](security.md#the-admin); what follows is the drop
itself, and the address that opens it.

**Off is the default and off is closed.** With no `data/drop.json` the admin offers no `drop` service,
and `/drop` answers every method exactly as an address that is not there: the same status, the same
body, the same `Allow`. A stranger cannot learn that a deployment could keep a drop.

## Switching it on

Three things, each per deployment and never shipped:

| | What | Absent |
|---|---|---|
| `data/drop.json` | the switch — [`DropConfig`](../src/Model/Drop/DropConfig.php) | the service is off |
| `data/drop.key` | thirty-two random bytes, base64 — the key every drop is sealed under | the admin says how to mint one; `/drop` stays off |
| `data/throttle/` | where `/drop` counts refusals, as the admin's entrance does | a post to `/drop` is a `503`, revealing nothing |

```json
{ "maxBytes": 8388608, "maxLifetime": 604800 }
```

| Key | Means | Absent |
|---|---|---|
| `maxBytes` | the largest drop kept, in bytes | 8 MiB — [`ApiGate::MAX_BODY`](../src/Service/ApiGate.php), the most a signed call carries |
| `maxLifetime` | the longest one is kept, in seconds; at least 60 | a week |

`{}` switches it on with both. Anything that does not read — a string for a number, a zero, a
fraction, JSON that does not parse — is off, not open, as `machine.json` is.

```bash
printf '{}\n' > data/drop.json
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > data/drop.key   # on the host it serves
```

Both must be readable by the user PHP runs as, and `data/` writable by it: the first drop makes
`data/drops/`, at `0700`, and each drop in it is a file at `0600`. **Taking `data/drop.key` away
destroys every drop at once** — without it nothing opens, whoever holds a link — and minting another
leaves the old files as bytes nobody can read, which the next touch of the store does not sweep until
they expire.

What a browser may send is bounded by the host too: `upload_max_filesize` and `post_max_size` refuse a
file larger than they allow before PHP runs, with a `413`. A signed call is bounded by its body's cap.

## Making one

| `drop v1` | kind | does |
|---|---|---|
| `create` | write | keeps a text or a file, sealed, and answers the link that opens it |
| `list` | read | every drop kept: its size, since when, until when, how it opens — never its name or contents |
| `revoke/<id>` | write | takes a drop away before it expires |

`create` takes, all of them optional: `text`, or one `file` a browser sends — a signed call's bytes are
its body; `filename`, which makes the bytes a file saved under that name (a browser's file keeps its
own name where none is given); `lifetime`, `90`, `30m`, `12h` or `7d`, a day where empty and never past
`maxLifetime`; `once`, gone once it has been read; and `password`, which it needs besides its link.
Text with no name must be UTF-8, and is shown where it is revealed; everything with a name is a file.

```bash
php tools/drop.php notes.pdf                        # a file, under its own name; prints the link
php tools/drop.php notes.pdf --name q3.pdf --once   # under another, gone once it has been read
some-command | php tools/drop.php -                 # standard input — text, unless --name says a file
php tools/drop.php --text 'the wifi password' --lifetime 30m --password-file ~/.drop-password
php tools/api.php drop v1 list
php tools/api.php drop v1 revoke <id> --dry-run
```

`drop` is its own command because `create` carries a body, which `api` never sends. A password comes
from the first line of a file, never the command line, where the shell's history and every process
listing would keep it. The link is printed whole — the origin the command called, and the address the
admin answered: the deployment names no origin of its own for it, since the `Host` a request carries
is the caller's to say.

In a browser, `/admin/drop/v1/create` is a form — a box for text, a file, a name, a lifetime, a box to
tick for once, a password — and a tap. The answer's last line is the link, **shown once and kept
nowhere**: the store holds a keyed hash of it and nothing else.

## Opening one

A link is `https://host/drop#<token>`: forty-three characters of base64url after the `#`, which a
browser never sends, so the token is in no request line, no access log and no `Referer`.

- **`GET /drop` is a page, and reveals nothing.** Its `<drop-reveal>` element reads the token from the
  address, fills in the form, hides the field, and takes the token out of the address again, so it is
  not left in the browser's history. Without the script the field is there to paste it into. A chat
  app unfurling the link, or a scanner following it, gets this page and burns nothing.
- **`POST /drop` reveals**, taking `token`, `password` where the drop has one, and `page`, which the
  page's own form sends: text is then shown on a page, escaped like any text. Anything else gets the
  bytes — text as `text/plain`, and a file as `application/octet-stream` saved under its name, never
  shown whatever it claims to be — with its length stated, so what receives a drop cut short knows.

```bash
curl -F token=<token> https://host/drop                                # text, to standard output
curl -OJ -F token=<token> -F password=<password> https://host/drop      # a file, under its name
```

| Answer | When |
|---|---|
| `200` | it opened |
| `404` | nothing is here: no token, one that is none, one of no drop, one expired, one read once already — one sentence for all of them |
| `403` | it needs its password, and none was given or not that one — said only to somebody holding the link |
| `429` | ten posts from this address revealed nothing in fifteen minutes; asked before the drop is |
| `503` | there is nowhere to count refusals, so nothing is revealed |
| `405` | any method but a read or a post, naming `GET, HEAD, POST` |

Every answer is kept by no cache and asks not to be indexed. **Only a refusal is counted**, so a
machine fetching what it was sent is never held up, and a password is guessed ten times a quarter-hour
per address, each guess paying the whole stretch of it.

## How a drop is sealed

[`DropCipher`](../src/Service/Drop/DropCipher.php) holds the arithmetic;
[`DropHeader`](../src/Model/Drop/DropHeader.php) the layout.

- **The token** is thirty-two random bytes. The drop's file is named by an HMAC-SHA256 of it keyed by
  the deployment's key — thirty-two hex digits that say nothing about it.
- **Its keys** are drawn by HKDF-SHA256 from the token and, where it has one, the password stretched
  by PBKDF2-SHA256 — 600,000 rounds, with the drop's own salt — using the deployment's key as the
  salt, which makes the extraction an HMAC keyed by the deployment. Two keys come out: one for its
  description, one for its bytes.
- **Its description** — text or a file, its name, its size — is sealed with AES-256-GCM, the header
  bound in as associated data. The name is sealed because a name says what a file holds.
- **Its bytes** are sealed in chunks of 64 KiB, each under a nonce that counts it, with the header, the
  chunk's index and whether it is the last bound in.
- **The header** is plain — when the drop was made and goes, whether it opens once or needs a password,
  the rounds and the salt — because the store must know those without a link, to sweep and to ask for a
  password; every seal in the file binds it, so a byte of it changed opens nothing.

What that buys, each a test in the framework's suite:

- the deployment's disk and its key together open nothing without the link; a link opens nothing
  without the deployment's key;
- a byte changed anywhere — header, description, chunk, tag — or a file cut short, grown or reordered,
  opens nothing, or ends before a chunk that did not open is handed over;
- a drop streams out a chunk at a time, every chunk checked before it is sent.

**A password is mixed into the keys, not sealed around the rest**: as strong as sealing twice, one pass
rather than two, and every guess costs the guesser the whole stretch. `ext/sodium` would offer Argon2id
and Ed25519; the local runtimes do not have it, and a code path only production takes is one no test
reaches — the reasoning [security.md](security.md#the-credential-is-a-key-the-server-cannot-use) gives
for the signing key. The rounds are kept in each drop's header, so raising them opens every older drop
still.

## Read once, and gone when it expires

**A drop meant to be read once is claimed by the first request alone**: once its link, and its
password where it has one, have opened its description, its file is renamed to a name of its own — which
the filesystem lets exactly one request do — and unlinked at once; the winner reads through the handle
it already holds, and every other request finds nothing. A wrong password never burns a drop, and a
drop whose file is not whole is never claimed. A download cut off halfway has still spent it.

**What has expired is swept whenever the store is touched** — a drop made, opened, listed or taken
away — an enumerated delete of the files whose header says they are gone, and of any claimed name a
request left behind. There is no cron: a shared host has none to offer.

## What it is not

- **Not end-to-end.** The server sees a token while it answers the post that carries it, and the bytes
  as it seals and opens them. Somebody who holds the running server when a drop is made or opened can
  read that drop; somebody who takes its disk and its key afterwards cannot. Sealing in the browser,
  with the server keeping only ciphertext, would close that — and would leave a machine with `curl` and
  nothing else unable to fetch what it was sent, which is what this is for.
- **Not for guests.** Only the admin makes a drop. Letting anybody make one is a switch and a count
  away, and a question of what a deployment's disk is for.
- **Not large.** Making a drop holds its bytes in memory once, bounded by `maxBytes`; opening one
  streams. A deployment raising `maxBytes` raises the host's upload limits and its memory with it.
- **Counted per address.** `/drop` counts refusals against `REMOTE_ADDR`, which behind a reverse proxy
  is the proxy — [`Request::remoteAddress()`](../src/Http/Request.php) says why it is never
  `X-Forwarded-For`.
