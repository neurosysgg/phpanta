# The machine service

`/admin/machine` administers the machine a deployment runs on: what it is and what it is running, its
files, and — where a deployment allows it — changing those files and running a command. It is a
service of the admin like `health`, `capability` and `access`, so everything the admin's own document
says holds here too: every call is signed, or comes from a browser an enrolled passkey unlocked; a
caller the admin cannot verify learns the one answer at every depth, whether an address exists or not;
and every write needs a fresh tap of a passkey, or a fresh signature. See
[security.md](security.md#the-admin).

**It is the one service a deployment switches on.** Off is the default and off is closed: with no
`data/machine.json` the service offers nothing, so no listing names it and no address under it
answers — a stranger cannot even learn that a machine service exists, because to a verified caller it
is a service that is not there. A shared host is left without the file; a machine of one's own is
given one. The file is per deployment, gitignored, and excluded from `deploy.sh` like every other
credential — a copy pushed up would open the live host's disk.

## The switch file

`data/machine.json` ([`CredentialFile::Machine`](../src/CredentialFile.php)) is a JSON object read by
[`MachineConfig`](../src/Model/Machine/MachineConfig.php):

```json
{
  "roots": ["/"],
  "writes": true,
  "commands": false,
  "origin": "https://machine.example"
}
```

| Key | Means | Absent |
|---|---|---|
| `roots` | the directories the service may walk, each absolute; everything under one is reachable | the file does not read, so the service is off |
| `writes` | whether it may keep, make, rename and remove there | `false` |
| `commands` | whether it may run a command — only ever with `writes` | `false` |
| `origin` | where an app whose only job is this service is served from, for its passkeys ([`App::origin()`](../src/App.php)) — the service itself never reads it | none |

**Everything that can be wrong is off, not open.** No file, JSON that does not parse, a root that is
not a string, a switch that is not a bool, or a `roots` list of which none is a directory here — every
one collapses to null, which reads as the service not being there. A setting left out takes its
closed value, so a typo closes something rather than opening it. A root is resolved with `realpath()`
when the file loads, so one that is a link is the directory it points at; a root that is not a
directory on this machine is dropped rather than refused, so one file can name `/home` and `D:/` and
serve on either kind of machine. `capability v1 deployment` reports whether the file is present.

## Actions

Every action but `system` and `processes` acts on a place, named **after** the action —
`files/etc/hosts`, `raw/home/me/song.flac` — so the path is part of the address and a signature or a
passkey's tap covers it with the rest. That fifth address depth is
[`AdminPath::Subject`](../src/Support/AdminPath.php), whose `{subject:path}` placeholder spans slashes;
see [architecture.md](architecture.md#routing). What a deployment offers is
[`MachineAction::offered()`](../src/Http/Api/MachineAction.php), which is every read where the service
is on, the writes only where it may write, and `run` only where it may run commands — so an action
switched off is one the admin neither lists nor answers.

| `machine v1` | kind | answers |
|---|---|---|
| `system` | read | the machine at a glance — its live readings, then host, hardware, memory, load, disks, network, sensors, battery |
| `processes` | read | the processes holding the most memory |
| `files/<path>` | read | a directory's entries, or what a file holds; with no path, the one root, or the roots to choose from |
| `raw/<path>` | read | a file's bytes, shown where a browser can — an image, a recording, a film, text, a PDF |
| `download/<path>` | read | a file's bytes, always to save |
| `upload/<dir>` | write | files a browser sent, kept under the names they were sent with |
| `folder/<dir>` | write | a directory made in a directory |
| `rename/<entry>` | write | an entry given another name, where it is |
| `delete/<entry>` | write | a file, a link, or a directory with nothing in it, removed |
| `run/<dir>` | write | a command line run with the machine's shell, and what it printed |

```bash
php tools/api.php machine v1                      # what the deployment offers there
php tools/api.php machine v1 system               # the machine at a glance
php tools/api.php machine v1 files /etc           # a directory
php tools/api.php machine v1 files /etc/hostname  # a file's metadata and preview
php tools/api.php machine v1 folder /srv/http --target uploads   # a write: --dry-run to rehearse
php tools/api.php machine v1 run /srv --command 'du -sh *'        # where commands are on
```

In a browser the admin let in, a directory is a table of links — each entry opens, each file saves,
and where the deployment may write, the writes are offered as links to their forms, so a write is two
clicks and a tap, never one. A file is shown by its kind, from its own `raw` address, so a player can
seek in it by range.

## What keeps it safe

The service runs as the web server's user and has exactly that user's rights and no more; a file it
may not read is a refusal, not a fault. On top of the admin's own gate, four things bound it:

- **Nothing reaches outside a root.** The path after an action arrives decoded, so its segments may
  hold anything a segment can — `%2e%2e` decodes to `..`. Nothing trusts that:
  [`MachinePath`](../src/Model/Machine/MachinePath.php) refuses a subject with an empty segment, a `.`,
  a `..` or a NUL before resolving anything, resolves the rest with `realpath()`, and keeps it only if
  it lands under a root. A link that leads out of every root is refused for the same reason — where it
  lands is what is asked, never how it was spelled. Reading follows every link; renaming and removing
  follow the links on the way to the last segment and **not** the last segment itself, so removing a
  link removes the link and never what it points at.
- **A name a write is given is one segment.** `folder`, `rename` and `upload` take a name held to
  [`MachinePath::isName()`](../src/Model/Machine/MachinePath.php) — one segment, not a dot segment,
  with no slash, backslash or NUL — so a write can never be pointed out of the directory its address
  names. A file already there is never replaced.
- **`delete` never removes a tree.** A directory that holds anything is refused, the way
  [`Directory::remove()`](../src/Support/Directory.php) refuses to descend: emptying a disk is never
  one tap away, and clearing a directory entry by entry is a choice each tap makes again.
- **A file shown inline is shown only by kind.** `raw` lets a browser render a picture, a recording, a
  film, plain text or a PDF where it lands, each typed as what its extension says, with `nosniff`
  alongside; everything else — an HTML file or an SVG, which could carry script — goes out as
  `application/octet-stream`, to be saved. See [`MediaKind`](../src/Model/Machine/MediaKind.php).

**Running a command is the sharpest edge, and it is off unless a deployment turns it on.** `run` hands
a person's command line to `/bin/sh -c` (or `cmd /c` on Windows) whole, as one argument — pipes,
globs and all — because the whole point is a shell the person typed. Nothing is interpolated into it
and nothing is escaped, because nothing but the person's own line is in it, and only a verified caller
with a fresh tap or signature ever reaches it. It runs as the web server's user, reads nothing (its
input is closed at once), is stopped after thirty seconds, and keeps at most a mebibyte of each
stream. See [`CommandRunner`](../src/Service/Machine/CommandRunner.php).

**Uploads are the one place the browser's own name for a file is used**, since the sender is the
admin, unlocked and tapped; the name is still held to `isName()`, and a browser's upload is the one
form under `/admin` that carries files — several under one tap, read once the passkey has answered.
See [security.md](security.md#uploads) and [`AdminBrowser`](../src/Service/Passkey/AdminBrowser.php).

## Reading the machine

What a machine says about itself is a [`MachineProbe`](../src/Service/Machine/MachineProbe.php), one
per kind of machine, picked by [`HostProbe::for()`](../src/Service/Machine/HostProbe.php):

- [`LinuxProbe`](../src/Service/Machine/LinuxProbe.php) reads `/proc` and `/sys` directly — the
  processor in `/proc/cpuinfo`, memory in `/proc/meminfo`, mounts in `/proc/mounts`, the board in
  `/sys/class/dmi/`, sensors in `/sys/class/hwmon/`, a graphics card named from `pci.ids`. It **reads,
  never runs**: no program installed, no shell, and no right the web server's user lacks. Its root is
  a parameter, so its whole suite reads a `/proc` tree a test wrote.
- [`PortableProbe`](../src/Service/Machine/PortableProbe.php) asks only what PHP can ask of any machine
  — its name, its system, its load, its roots' disks, its interfaces' addresses — and is what every
  other machine gets.

**The live readings are worked out on the page.** `system` answers one moment's counters as data
([`MachineCounters`](../src/Model/Machine/MachineCounters.php)); the `<machine-stats>` element
([assets/ts/elements/MachineStats.ts](../assets/ts/elements/MachineStats.ts)) asks the same address
again every few seconds and works a processor's busyness and the network's speed out from two
moments, because one moment cannot say a rate and the server never sleeps to sample. Without its
script the table is the moment the page was made. `<machine-filter>` hides the entries whose names do
not hold what is typed; the server writes both elements, and a page with either is served only where a
deployment switched the service on.

## Turning it on locally

Write `data/machine.json` and the service appears at `/admin/machine`. Nothing else is needed — the
same passkey and signing key the rest of the admin uses reach it. To browse the whole disk with a
shell, on a machine that is yours:

```json
{ "roots": ["/"], "writes": true, "commands": true }
```

`php tools/api.php machine v1 system` prints the machine at a glance, and the admin's entrance lists
`machine` for a browser the deployment lets in.
