# SNMP walk for the Zabbix frontend

Walk a device from the Zabbix UI, read the results translated, work out what is worth
monitoring, and turn a row into an item without leaving the browser.

Zabbix has no in-UI equivalent. The existing tools in this space (SNMPWALK2ZABBIX and
friends) run on the command line against a device you can already reach, then hand you
an XML template to import. That is a different workflow: this one starts from a host
that already exists in Zabbix, uses the credentials and the proxy that host is already
configured with, and compares what it finds against what that host already polls.

Tested against Zabbix 7.0. See "Version sensitivity" below for the one part that is
not version-proof.

## What it does

**Walk from the UI.** Pick a host, pick a starting OID, run. Results stream in as they
arrive rather than appearing all at once at the end, so a walk of a large chassis shows
progress instead of a spinner, and can be stopped halfway with the values so far kept.

**Translate.** Names come from a MIB index built with `snmptranslate` and cached, so
translating twenty thousand varbinds costs nothing. Numeric OIDs are always what gets
stored; translation is a display concern, so a walk stays readable when a MIB is
missing and gains names when one is added later.

**Manage MIBs.** Zabbix has no MIB management of its own. There is a page for uploading
the vendor MIBs that never ship in a distribution package, so translation works for
everyone using the console rather than for whoever last copied a file onto the server.

**Detect tables.** Results are split into scalars and conceptual tables, rendered as
grids. With MIBs loaded this uses the INDEX clause and is exact, including
multi-component indexes. Without them it falls back to OID structure and says so.

**Coverage.** Compare the walk against the items and discovery rules on the host.
Reports what the device exposes that nothing is monitoring, rolled up to subtrees, and
separately what the host polls that the device did not answer for. The second list is
how you find a template linked to the wrong device family.

**Snapshots and diff.** Save a walk against a host, compare two of them. The use this
earns its keep on is a firmware upgrade: walk before, walk after, find out whether the
vendor moved or dropped an OID a template depends on. Counters and uptime can be
excluded so the diff is signal. Snapshots from different hosts can be compared, which
answers "why does this switch discover interfaces and its twin does not".

**Create items and discovery rules, on a template.** Tick any number of rows and turn
them into items; turn a detected table into a discovery rule with prototypes. See
"Where things get created" below, because the target matters more than the mapping
does.

**Download.** Numeric text in `snmpwalk -On` form, translated text, or JSON with types.
The numeric form is what every other SNMP tool and every template builder consumes.

## Where things get created

This is the part worth reading before clicking anything.

Select rows with the checkboxes and press **Create items from selection**. A dialog asks
where they should go, and shows exactly what would be created before anything is:

- **Template** (the default). Items are created on a template, so they inherit to every
  host linked to it. Pick an existing template, or name a new one and choose a group and
  it is created for you. The template is deliberately **not** linked to the host you
  walked. That keeps discovery separate from deployment: you can walk a device, build a
  template from what you find, review it properly, and link it when you are ready,
  rather than having a half-finished template attached to a customer's device because
  you were exploring.
- **This host only**. Items land directly on the walked host. Fine for proving an OID
  returns what you expect; wrong for anything you intend to keep, because host items do
  not inherit, never reach the other forty devices of that model, and drift from your
  templates. The dialog says so.

The same picker appears for a single row and for a discovery rule, so the two paths
cannot disagree about where things end up.

What gets proposed, and why, is shown before you commit: value type from the ASN.1 type,
Change per second on counters, a 0.01 multiplier and uptime units on Timeticks, a value
map built from a SYNTAX enumeration, units from the MIB UNITS clause, and an hourly
interval on descriptive strings. Every guess comes with its reasoning next to it.

Two things the preview does that matter in bulk:

**Keys carry the instance.** `ifDescr.1` and `ifDescr.7` become `ifDescr[1]` and
`ifDescr[7]`. Without that every row of a column reduces to the same key and the second
create fails.

**Collisions are found before anything is written.** Keys are checked against each other
and against what the target already has, and the offenders are listed in the preview as
struck-through rows. The alternative is the API rejecting the batch with a message that
does not say which of forty items caused it.

**The preview is editable.** Name, key, type of information, interval and units are
input fields, per object, before anything is written. Name and interval are trivial to
change afterwards; the key and the value type are not. Changing a key orphans history,
and changing a value type once history exists splits data across tables. Those two were
exactly what the module guessed with no way to intervene, which was backwards. Edited
keys are re-checked for collisions.

**Export YAML** is a third button next to Create. It emits a Zabbix template export
document and creates nothing. That gives you a file to read before it exists anywhere,
edit by hand, commit, review, and import into a different instance. API creation gives a
live object on one server and nothing to diff. The format matches what Zabbix itself
writes, including UUIDs, literal blocks for MIB descriptions and export tokens rather
than integers, so an exported template diffs cleanly against an official one.

**Create enabled** is a checkbox, and its default follows the target.

On a template that is not linked to anything, nothing polls until you link it, so the
link is already the review gate and the box starts ticked. On a host, creation *is*
deployment: a discovery rule with twenty prototypes against a 400-port chassis starts
polling thousands of items the moment it exists, so that combination starts unticked.
Touch the box and it stops following the target.

A discovery rule is created with the same status as its prototypes. An enabled rule
with disabled prototypes still runs on every interval and fills the database with rows
nobody asked for.

Creating anything requires write access to the target, checked against the API. A
read-only role gets the console and the analysis without the ability to change
configuration.

## Installing

```
cd /usr/share/zabbix/ui/modules      # or wherever your frontend lives
unzip snmpwalk-module.zip            # creates SnmpWalk/
```

Then *Administration* → *General* → *Modules* → *Scan directory*, and enable
*SNMP Walk*. The console appears under *Monitoring* → *SNMP walk*.

### Data directory

The module stores its MIB index, snapshots and in-progress walk buffers on disk. It
does not create Zabbix database tables: a module cannot own a schema migration across
upgrades, and a walk of a large chassis is megabytes of text that has no business in a
table the housekeeper does not know about.

```
install -d -o www-data -g www-data -m 0750 /var/lib/zabbix/snmpwalk
```

Use whichever user your web server runs as (`apache` on RHEL, `nginx` or `php-fpm`
depending on the setup). Change `data_dir` in `manifest.json` if you want it elsewhere.

### Containerised Zabbix

The module runs in the **frontend** container, so that is where `php-snmp` and
`snmptranslate` have to be, and anything installed with `apk add` there is lost the
next time the container is recreated. `contrib/docker/` has a Dockerfile that builds
the dependencies in, plus notes on mounting the module read-only from the host, giving
the data directory a named volume so snapshots survive, and the network-path gotcha
that makes the local engine time out from a container when the server polls the same
device fine.

### MIB translation

Requires `net-snmp` on the **frontend** host, which is separate from the MIBs the
Zabbix server uses for collection:

```
dnf install net-snmp-utils      # RHEL
apt install snmp snmp-mibs-downloader
```

Then *Monitoring* → *SNMP walk* → *MIBs* → *Rebuild index*.

`mib_dirs` defaults to `/var/lib/zabbix/mibs` followed by the distribution paths. The
first is where the official container images expect custom MIBs to be mounted, so in a
containerised install bind-mount your MIB tree there from the host and it is searched
without touching the configuration. Directories that do not exist are skipped silently
rather than making net-snmp warn on every call, so a path that never takes effect looks
like nothing happening. Watch the object count on the MIBs page to confirm.

net-snmp does not recurse into subdirectories. A vendor tree with `cisco/`, `dell/` and
so on needs either each directory listed in `mib_dirs`, or the tree flattened into one.

Without `snmptranslate` everything still works, just numerically: walks run, tables are
still detected from OID structure, coverage still compares numeric OIDs. The console
says so rather than silently showing bare numbers.

## Choosing an engine

Where the SNMP packet originates is the only thing that really matters in a distributed
install, and it is the one decision this module cannot make for you.

| Engine | Packet comes from | Resumable | Needs |
|---|---|---|---|
| `local` | Frontend host | Yes | `php-snmp`, and a network path to the device |
| `server` | Zabbix server or proxy | No | Nothing extra |
| `script` | Zabbix server or proxy | No | One global script, created once |

`local` is the best experience: repeated GETNEXT gives a real cursor, which gives
progress and resumability. It is also useless for any host behind a proxy, which the
module detects and refuses rather than letting you discover through a timeout. Note
that one GETNEXT per varbind is one round trip per varbind, so over a slow WAN link a
large walk is noticeably slower than a bulk walk would be. On a LAN it does not matter.

`server` reuses the item test path, so it inherits proxy routing, server-side macro
resolution and SNMPv3 handling with no new credential path and no new firewall hole.
It returns the whole subtree in one response, so there is no progress bar.

`script` is what to standardise on for proxied customer estates. See
`contrib/global-script.md`.

`auto` prefers script, then server, then local. The walk should originate from whatever
collector actually owns the host, because the question a walk answers is "what can the
poller see". A walk that succeeds from the frontend while the assigned proxy cannot reach
the device has answered the wrong question and answered it reassuringly.

That costs the cursor, since both server-side engines return a subtree in one response.
The local engine is one click away when you want progress on a long walk, or on an
all-in-one install where the frontend and the poller are the same machine. It is refused
outright for a proxied host rather than allowed to time out, and it carries a standing
caveat in the UI even when it works: a result from the frontend does not prove collection
will work.

The console shows which engines are usable for the selected host and why the others are
not, so a failure is a sentence rather than a mystery.

## Version sensitivity

`includes/CEngineServer.php` calls `CZabbixServer::testItem()`. That is internal
frontend API, not published API, and its request shape does change between releases:
the payload here mirrors what `CControllerPopupItemTest` builds on 7.4, with `options`,
`item` and `host` at the top level and the interface nested under `host`. An earlier
version of this module sent a flat structure and got `Missing item field.` back from
the server, which is the shape of failure to expect if this moves again.

It is guarded, isolated to that one file, and `diagnose()` reports what it found, so a
break there produces a clear message and the other two engines keep working.

**Validate this engine against your own instance before relying on it.** If it does not
work, the script engine is the documented alternative and is a five-minute setup.

The payload here mirrors 7.4: `options`, `item` and `host` at the top level, the
interface nested under `host`, and a per-item `timeout` taken from the global SNMP agent
timeout. Older shapes produce `Missing item field.` or `Unsupported timeout value.` from
the server, which is what to expect if this moves again.

Everything else in the module uses published API (`host.get`, `item.create`,
`discoveryrule.create`, `template.create`, `script.execute`, `valuemap.create`) or is
self-contained.

## Security

The starting OID is the only user input that reaches a network call or a subprocess. It
is validated against a strict numeric pattern and refused if it does not match, rather
than being escaped and hoped for. `contrib/zbx-snmpwalk.sh` validates it again on the
poller, on the principle that a script reachable by anyone who can run a global script
should not trust its caller.

Community strings and SNMPv3 passphrases are resolved server-side and never sent to the
browser. Error text from net-snmp and from the Zabbix server is scrubbed before display,
because both like to echo the community string back in failure messages. Vault macros
are not readable from the frontend and are refused with an explanation rather than
being used as a literal string.

Read access is gated on user type and on the *Monitoring → Hosts* UI element, so a
read-only NOC role can be given the console without being given host configuration.
Creating items, deleting snapshots and uploading MIBs each require more: write access
to the host, or super admin for MIBs.

Module actions cannot write to the Zabbix audit log, so walks and configuration changes
are logged to the web server error log instead. It is not a substitute for audit, but
"who walked the customer's core switch at 3am" has an answer.

## Logging and troubleshooting

Every walk, item creation, snapshot change and MIB upload is written with `error_log()`,
prefixed `snmpwalk`. Where that lands depends on the SAPI:

| Setup | Look in |
|---|---|
| nginx + PHP-FPM | the FPM pool's `error_log`, often `/var/log/php-fpm/www-error.log` or `/var/log/php8.3-fpm.log` |
| Apache + mod_php | the vhost `ErrorLog`, usually `/var/log/httpd/error_log` or `/var/log/apache2/error.log` |
| systemd-managed FPM with no log file | `journalctl -u php-fpm -f` |

```
grep snmpwalk /var/log/php8.3-fpm.log
```

Browser-side, the console logs its build number on load and dumps the full failure
object to the JavaScript console when a walk fails, which carries more than the
single-line message shown on the page.

If a walk fails, the message on the page is the engine's own error. If it comes back
with no values and no explanation, that is a bug in this module rather than a device
problem; the error log is the next place to look.

## Configuration

In `manifest.json`, under `config`:

| Key | Default | Notes |
|---|---|---|
| `data_dir` | `/var/lib/zabbix/snmpwalk` | Must be writable by the web server user |
| `mib_dirs` | distribution MIB paths | Searched in addition to uploaded MIBs |
| `snmptranslate` | `snmptranslate` | Full path if it is not on `PATH` |
| `engine` | `auto` | `auto`, `local`, `server`, `script` |
| `chunk_size` | `300` | Varbinds per request for resumable engines |
| `max_varbinds` | `200000` | Hard stop for a runaway walk |
| `local_timeout` | `3` | Seconds, per GETNEXT |
| `local_retries` | `2` | |
| `script_id` | `null` | Required for the script engine |
| `min_user_type` | `2` | 2 = Admin, 3 = Super admin |
| `template_limit` | `300` | Templates the target picker lists before telling you to search. Clamped 25–2000 |
| `snapshot_retention_days` | `365` | Pruned when a new snapshot is saved |

## Tests

`tests/run.php` exercises the logic that has no Zabbix dependency: OID validation and
ordering, the walk parser, diff semantics, type mapping, table detection and monitored
OID extraction.

```
php tests/run.php
php tests/constants.php
```

`constants.php` is a static check that every Zabbix constant the module names actually
exists, against a vendored list of the names defined in `defines.inc.php`. It exists
because `CTypeMapper` referenced `ZBX_PREPROC_CHANGE_PER_SECOND`, which is the label
shown in the UI rather than the constant (`ZBX_PREPROC_DELTA_SPEED`). PHP resolves
constants at runtime, so that was a fatal error waiting on the one code path that
reached it, and `run.php` hid it: the test stubs defined the made-up name, so everything
passed. Regenerate `tests/zabbix-constants.txt` when targeting a new Zabbix release.

These are the parts where a quiet bug produces plausible wrong answers rather than an
error, which is why they are the parts with tests. They caught two real ones during
development: a `continue 3` in a non-loop, and PHP coercing numeric-string table
indexes back to integers so that single-component and multi-component indexes had
different types.

## Design

`docs/DESIGN.md` covers the architecture: why there are three engines, the item test
payload and where it breaks, the two-tier MIB cache, table detection strategies, the
security model, and a list of every failure mode hit during development with its cause.
Read it before changing an engine or the analysis code.

## Layout

```
manifest.json           module metadata, action registry, configuration
Module.php              menu entries
actions/                controllers, one per registered action
includes/               engines, MIB index, analysis, storage
views/                  page shells
views/js/               the console and MIB pages
assets/css/             thin styling over Zabbix's own classes
contrib/                poller-side wrapper, global script setup, container build
docs/                   technical design
tests/                  logic tests and the constant checker
```
