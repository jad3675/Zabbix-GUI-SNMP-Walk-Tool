# SNMP walk for Zabbix: technical design

Module `snmp-walk`, version 1.2.4. Targets Zabbix 7.0 and 7.4.

This document explains why the module is shaped the way it is. It assumes you have read
the README, which covers what it does and how to install it.

---

## 1. Problem

Zabbix knows the address, SNMP version, community or v3 credentials, interface, owning
proxy, and complete item configuration for every host it monitors. net-snmp knows the
protocol. The two never speak, so a human retypes facts from one into the other every
time a device needs exploring.

The consequences are not ergonomic:

- **Walk output is write-only.** It scrolls off a terminal. The most valuable artifact
  in the exercise, what a device exposed on a given date, is discarded by default, so
  there is nothing to diff against after a firmware upgrade.
- **Coverage is uncomputable in practice.** "What does this device expose that we are
  not monitoring" is a join between walk output and configured items. At a shell that
  join is manual, so it is not done, so nobody knows the answer.
- **Walking requires shell access on the poller.** In an MSP that gates the operation on
  a person rather than a permission.

The module closes the loop: walk from the UI using the credentials Zabbix already holds,
keep the result, compare it against what is configured, and turn findings into template
objects.

### Non-goals

- Replacing `snmpwalk` at the CLI. Composability is real and a table in a browser does
  not replace a pipeline. The download emits `snmpwalk -On` format for that reason.
- Becoming a MIB browser. There is no tree navigator. You walk a subtree and read what
  came back.
- Generating portable templates. Objects are created through the API against a live
  template. Emitting importable YAML is discussed in section 12.
- Trap handling, polling, or anything that runs on a schedule. Every operation is
  user-initiated and synchronous.

---

## 2. Constraints

These shaped nearly every decision. Most were discovered rather than anticipated.

| Constraint | Consequence |
|---|---|
| The frontend has no network path to a proxied device | Three execution engines instead of one |
| A module cannot own database tables | Filesystem storage for snapshots, buffers, MIB caches |
| Zabbix has no MIB management, and daemons must restart after MIB changes | The module ships its own MIB index and upload path, frontend-side only |
| No audit API reachable from a module action | Logging via `error_log()` to the SAPI log |
| `CZabbixServer::testItem()` is internal, undocumented, and moves between releases | One engine is a maintained seam, isolated and diagnosable |
| PHP `max_execution_time` and FPM request timeouts | Chunked walking with a resumable cursor |
| Frontend containers may lack `php-snmp`, `snmptranslate`, or a PHP CLI | Every dependency is optional and degrades to something usable |

The first constraint is the important one. It is why this feature does not already exist
in Zabbix: anyone who starts building it discovers within an hour that the frontend
cannot reach the device, and reasonably concludes the frontend is not supposed to.

---

## 3. Architecture

```
  Browser
    │  chunked POST loop, JSON
    ▼
  actions/            controllers, one per registered action
    │                 CWalkAction: permissions, JSON envelope, guard, logging
    ▼
  includes/
    CWalkService      configuration, engine selection, service locator
    CHostContext      host, interface, resolved credentials, proxy
    CEngine ──────────┬── CEngineLocal    php-snmp from the frontend
                      ├── CEngineServer   item.test via the trapper
                      └── CEngineScript   script.execute on server or proxy
    CWalkParser       net-snmp text to varbinds, and back
    CMibLookup ───────┬── CMibIndex       snmptranslate, two-tier cache
                      └── CMibNull        no-op when translation is unavailable
    CWalkBuffer       per-user scratch for a walk in progress
    CSnapshotStore    durable gzipped walks per host
    CTableAnalyzer    scalars and conceptual tables
    CCoverage         walk vs configured items
    CWalkDiff         two walks
    CTypeMapper       ASN.1 to a proposed Zabbix item
```

### Request lifecycle for a walk

1. Browser posts `snmpwalk.run` with hostid, interfaceid, OID, engine, and on
   subsequent calls a cursor and buffer token.
2. `WalkRun::checkInput()` validates through `CWalkAction::validate()`, which converts a
   validation failure into a JSON error carrying the reason.
3. The OID is resolved (symbolic names go through the MIB index) and validated against a
   strict numeric pattern. Depth below 2 is refused.
4. `CHostContext::load()` resolves the host, picks the interface, expands user macros in
   the interface details, and detects the owning proxy.
5. `CWalkService::engine()` selects an engine.
6. On the first chunk the buffer is created and the walk is logged. Each chunk appends to
   the buffer and returns its rows plus the next cursor.
7. Varbinds are annotated from the MIB index before returning. The index is an in-memory
   array lookup, so annotating twenty thousand varbinds costs nothing.
8. The browser accumulates rows for rendering and download, and loops until `done`.

Analysis actions (`analyze`, `coverage`, `diff`, `create`) read from the buffer or a
snapshot rather than accepting posted varbinds, so a multi-megabyte walk is never sent
back to the server.

---

## 4. Engines

### 4.1 The contract

```php
interface CEngine {
    public function walk(string $root, ?string $cursor, int $limit): array;
    public function isResumable(): bool;
    public function name(): string;
    public function origin(): string;
}
```

`walk()` returns `varbinds`, `cursor`, `done`, `skipped`, `notices`. Engines that cannot
resume ignore the cursor and always return `done => true`. `origin()` is surfaced in the
UI so the user knows where the packet came from, which matters when a walk succeeds from
one place and fails from another.

### 4.2 CEngineLocal (php-snmp)

Uses the `SNMP` class with `valueretrieval = SNMP_VALUE_OBJECT` and repeated `getnext()`.

**Why not `SNMP::walk()`.** `walk()` fetches an entire subtree before returning. That is
precisely the behaviour that blows through `max_execution_time` on a large chassis, and
it yields no intermediate state, so a walk that dies at 90% returns nothing. One
`getnext()` per varbind gives a real cursor. The cursor gives resumability, a progress
indicator, and a Stop button that keeps what it collected.

**The cost is explicit.** One GETNEXT is one round trip. On a LAN that is invisible. Over
a slow WAN a large walk is materially slower than a GETBULK walk would be. PHP does not
expose per-PDU GETBULK, so there is no way to have both a cursor and bulk retrieval. The
README says so rather than hiding it.

**Safeguards.** A non-increasing OID from the agent stops the walk with a notice, because
some agents loop at the end of a MIB view. Leaving the root subtree ends it. Octet
strings that are not valid UTF-8, or contain control characters, are rendered as
`Hex-STRING` to match what `snmpwalk -On` would have produced, because the downstream
type mapping depends on that distinction.

**SNMPv3** maps Zabbix's integer protocol fields onto net-snmp's names via ordered
arrays. A macro that failed to resolve is refused before the engine is built, by
`CWalkService::engine()`, rather than being used as a literal passphrase.

### 4.3 CEngineServer (item.test)

Runs an unsaved `walk[]` item through the Zabbix server's item test, the same path the
Test button on the item form uses. This inherits proxy routing, server-side macro
resolution, and SNMPv3 handling with no new credential path and no new firewall rule.

**This is the maintained seam.** `CZabbixServer::testItem()` is internal frontend API.
Its request shape is not published and does change. Two failures were hit during
development against 7.4:

| Symptom | Cause |
|---|---|
| `Missing item field.` | A flat payload. The server wants `options`, `item` and `host`, with the interface nested under `host` |
| `Unsupported timeout value.` | Since 7.0 an SNMP item carries its own `timeout`. Absent is invalid |

The payload now mirrors `CControllerPopupItemTest::getItemTestProperties()`:

```php
[
  'options' => ['single' => true, 'state' => 0],
  'item'    => ['type', 'value_type', 'flags', 'snmp_oid' => 'walk[<oid>]', 'timeout'],
  'host'    => ['host', 'proxyid', 'interface' => ['interfaceid','address','port','details']]
]
```

The timeout comes from `CSettingsHelper::TIMEOUT_SNMP_AGENT` so a walk waits exactly as
long as ordinary collection against the same device, rather than a number invented here.

Interface details are pruned by SNMP version the same way the frontend prunes them: v3
security fields are stripped from a v1/v2c interface, and the community is stripped from
a v3 one. The server rejects mismatched sets, and it also means a v3 host never has a
community string sent alongside its credentials.

The device-side error arrives inside `$result['item']['error']`, not as a transport
error. Reading only the transport layer, as an earlier version did, turns a refused
community string into an apparently empty subtree.

**Containment.** `diagnose()` reports whether `CZabbixServer` exists, whether the method
exists, and whether a server address is configured, so a break produces a sentence rather
than a white page. Everything version-sensitive lives in this one file. The other two
engines keep working.

### 4.4 CEngineScript (script.execute)

A global script with scope "manual host action", executed on the Zabbix server or proxy,
taking the OID through `{MANUALINPUT}`. Entirely published API, so it does not break when
frontend internals move. This is the engine to standardise on for proxied estates.

`contrib/zbx-snmpwalk.sh` takes credentials through the environment rather than argv,
because process arguments are world-readable through `ps`. It re-validates the OID,
address and port on the poller, on the principle that a script reachable by anyone who
can run a global script should not trust its caller.

### 4.5 Selection

```
auto:
  script configured and usable              -> script
  server engine usable                      -> server
  host is not proxied and php-snmp present  -> local
  otherwise                                 -> server (and fail with its diagnosis)
```

**Route through the collector that owns the host.** A walk answers the question "what can
the poller see". Running it from the frontend answers a different question, and answers it
reassuringly: the walk succeeds, the operator concludes the device is fine, and collection
still fails because the assigned proxy has no route or the device's community ACL names
the poller rather than the web container. Reaching the device from the frontend only
coincides with the truth on an all-in-one install.

The cost is the cursor. Both server-side engines return a whole subtree in one response,
so preferring them means no progress indicator and no useful stop button by default. That
is a real regression in feel and it is still the right default, because a fast wrong
answer is worse than a slow right one. The local engine stays one selection away.

`local` is refused outright for a proxied host rather than allowed to time out, because a
timeout is a confusing way to learn about a routing problem. When it is usable it carries
a standing caveat in the UI rather than presenting as equivalent to the others.

`engineStatus()` reports per-engine usability and the reason for each unusable one, shown
in the form. The point is that "the walk failed" should never be the whole story.

## 5. Data

### 5.1 Canonical form

OIDs are stored numerically, without a leading dot, everywhere: `1.3.6.1.2.1.1.1.0`.
Translation happens at display time only. A walk therefore stays readable when a MIB is
missing and gains names when one is added later, and a snapshot taken before a MIB was
loaded diffs correctly against one taken after.

`COid` is deliberately narrow. Two behaviours that a naive implementation gets wrong:

- `isChildOf()` is component-aware. String prefix matching makes `1.3.6.1.2.1.11` a child
  of `1.3.6.1.2.1.1`, which silently corrupts subtree filtering and coverage.
- `compare()` is component-wise numeric. String sorting puts index 10 before index 2,
  which makes every table unreadable.

Both are covered by tests.

### 5.2 Why the filesystem, not the database

A module cannot add tables without owning a migration story across Zabbix upgrades, and a
walk of a large chassis is megabytes of text that has no business in a table the
housekeeper does not know about.

| Store | Path | Format | Lifetime |
|---|---|---|---|
| MIB index | `<data_dir>/mib-index.json` | JSON, `oid -> name` and `name -> oid` | Until reindex |
| MIB detail | `<data_dir>/mib-detail.json` | JSON, lazily accumulated | Cleared on reindex |
| Walk buffer | `<data_dir>/scratch/<userid>/<token>.json.gz` | gzip level 1 | 2 hours, pruned on new walk |
| Snapshots | `<data_dir>/snapshots/<hostid>/<id>.json.gz` | gzip level 6 | `snapshot_retention_days` |
| Snapshot index | `<data_dir>/snapshots/<hostid>/index.json` | JSON | With the host's snapshots |
| Uploaded MIBs | `<data_dir>/mibs/<MODULE-NAME>` | raw | Until deleted |

All writes are atomic (temp file plus rename). Buffers use gzip level 1 because they are
written on every chunk and read on every analysis; snapshots use level 6 because they are
written once and kept for a year.

Path components taken from a URL are validated against patterns, not sanitised.

### 5.3 The buffer

A chunked walk arrives over many requests, and analysis, coverage and snapshot creation
all need the whole thing. Buffering server-side means the browser never posts megabytes
back for each operation. The browser keeps its own copy for rendering and for the
client-side download, so nothing is fetched twice.

Buffers are per user, keyed by an opaque 16-hex token, and pruned by age. Losing one
costs a re-walk, not data.

---

## 6. MIB layer

### 6.1 Two tiers, because the costs differ by orders of magnitude

**Index.** One `snmptranslate -Tz -m ALL -M <dirs>` run produces every `name -> oid` pair
in the tree. Cached to disk. After that, resolving a name or finding the longest matching
prefix for an OID is an array lookup. Annotating a twenty thousand varbind walk is
therefore free.

**Detail.** `SYNTAX`, `UNITS`, `MAX-ACCESS`, `STATUS`, `DESCRIPTION`, enumerations and the
`INDEX`/`AUGMENTS` clause require `snmptranslate -Td -On` per object, which is one
subprocess each. Fetched lazily and memoised to disk.

The split matters because detail is only ever needed per column, per expanded row, or at
item creation. A 48-port `ifTable` has around 22 columns, so table analysis costs 22
subprocesses on first encounter and zero thereafter. Doing it per row would be over a
thousand.

### 6.2 Degradation

`CMibLookup` is the interface the analysis code depends on. `CMibNull` implements it as a
no-op and is returned when `proc_open()` is unavailable. Walks still run, tables are still
detected structurally, coverage still compares numeric OIDs. Only the names are missing,
and the UI says so.

This interface exists because `CMibIndex` was originally `final` and therefore untestable.
Extracting it fixed both the test problem and the production degradation path.

### 6.3 Uploads

Zabbix has no MIB management, and its own documentation notes that daemons must be
restarted after MIB changes. The module's MIB library is frontend-side only and does not
affect collection. It exists so translation works for everyone using the console rather
than for whoever last copied a file onto the server.

Uploads are treated as hostile: the filename is rebuilt from the module's own
`DEFINITIONS ::= BEGIN` line rather than sanitised from the browser-supplied name, content
must look like an ASN.1 module, there is an 8 MB cap, and the operation is super-admin
only.

---

## 7. Analysis

### 7.1 Table detection

Two strategies, in confidence order.

**With MIBs.** An object whose parent carries `INDEX` or `AUGMENTS` is a table column by
definition, and the clause tells us how many trailing components form the index. Exact,
including multi-component indexes such as `ipNetToMediaEntry { ifIndex, ipAddress }`
where the index is five components.

**Without MIBs.** Group by the OID with its last component removed. A group with more than
one distinct trailing component is a column; sibling columns under a shared parent form a
table. This gets `ifTable` and `entPhysicalTable` right and gets multi-component indexes
wrong, so the result is reported as low confidence and the UI says why.

Anything ending in `.0`, or alone under its parent, is a scalar. A single column with a
single row is not promoted to a table.

**A bug worth recording.** PHP coerces numeric-string array keys back to integers, so
`array_keys()` on an index set returned `int(7)` while a multi-component index like
`2.10.0.0.1` stayed a string. The two shapes then disagreed through JSON serialisation.
Fixed by casting on the way out, and covered by a test.

### 7.2 Coverage

Extracts numeric OIDs from `snmp_oid` on both items and discovery rules. The grammar is
wider than it looks:

```
1.3.6.1.2.1.1.3.0                          plain
IF-MIB::ifHCInOctets.1                     symbolic, resolved via the index
get[1.3.6.1.2.1.1.1.0]                     unwrapped
walk[1.3.6.1.2.1.2.2,1.3.6.1.2.1.31.1.1]   unwrapped, multiple
discovery[{#IFDESCR},1.3.6.1.2.1.2.2.1.2]  macro dropped, column kept
1.3.6.1.2.1.2.2.1.10.{#SNMPINDEX}          prototype, macro dropped
```

A varbind is covered when a monitored OID is the varbind itself or an ancestor of it. An
item on `ifDescr.1` covers only that instance. A discovery rule on the `ifDescr` column
covers every row. Uncovered varbinds are rolled up to their defining object so the output
is a short list of subtrees rather than four thousand OIDs.

The reverse direction is also reported: OIDs the host polls that the walk did not return.
That is how you find a template linked to the wrong device family, and it is the check
that is impossible to perform at a shell.

`extract()` is public specifically so it can be tested. It is the piece most likely to
quietly stop recognising a syntax after a Zabbix release.

### 7.3 Diff

Value comparison as strings, by OID. Added, removed, changed, retyped, unchanged.
Counters and Timeticks are excluded by default because a counter that moved is not
information. A type change is reported separately from a value change, because a vendor
switching an object from `Counter32` to `Counter64` breaks preprocessing in a way that a
value diff would hide.

Snapshots from different hosts can be compared, which answers "why does this switch
discover interfaces and its twin does not".

### 7.4 Type mapping

| ASN.1 | Value type | Preprocessing | Notes |
|---|---|---|---|
| Counter32, Counter64 | UINT64 | Change per second (`ZBX_PREPROC_DELTA_SPEED`) | Octet counters get `Bps` |
| Gauge32, UInteger32 | UINT64 | none | |
| Timeticks | FLOAT | Multiplier 0.01 | Units `uptime` |
| INTEGER | UINT64 | none | FLOAT if signed; value map if the SYNTAX enumerates |
| STRING | STR, or TEXT over 255 chars | none | 1h interval |
| Hex-STRING, BITS, IpAddress, OID | STR | none | |

Units come from the MIB `UNITS` clause through a small mapping table, and unrecognised
units are left empty rather than guessed. Every decision carries a human-readable reason
which is displayed in the preview, so the guess can be argued with rather than trusted.

The constant is `ZBX_PREPROC_DELTA_SPEED`. "Change per second" is the UI label. Using the
label as a constant name produced a runtime fatal that the unit tests hid, because the
stubs defined the invented name. See section 11.

---

## 8. Object creation

### 8.1 Target resolution

Three targets, resolved in `ObjectCreate::resolveTarget()`:

- **Existing template.** Items carry no `interfaceid`, which templates require.
- **New template.** Created with `template.create` and a chosen group, then used.
- **The walked host.** Items carry the selected interface.

Template is the default. The created or selected template is deliberately **not** linked
to the walked host. That separates discovery from deployment: walk a device, build a
template from what is there, review it, link it when ready. Nothing half-finished ends up
attached to a customer's device because someone was exploring.

Write permission is checked against the resolved target, not against the host. A user may
be able to edit a template while having read-only access to the device they walked.

### 8.2 Key derivation and collisions

The key must carry the instance. Without it, every row of a column reduces to the same
key: `ifDescr.1` and `ifDescr.7` both become `ifDescr`, and the second create fails. The
index comes from the MIB lookup remainder, falling back to the last OID component.
Scalars ending in `.0` keep no suffix.

Collisions are detected before anything is written, both within the selection and against
what the target already holds. Offenders appear in the preview as struck-through rows with
the reason. The alternative is the API rejecting a batch of forty with a message that does
not identify the culprit.

### 8.3 Enabled semantics

A checkbox whose default follows the target:

| Target | Kind | Default | Reason |
|---|---|---|---|
| Template | either | enabled | Nothing polls until you link it; the link is the review gate |
| Host | item | enabled | You clicked it to check an OID; you want the value |
| Host | discovery | disabled | Twenty prototypes on a 400-port chassis is 8,000 items polling immediately |

Touching the checkbox stops it following the target.

A discovery rule takes the same status as its prototypes. An enabled rule with disabled
prototypes still runs on every interval and fills the database with rows nobody asked for,
which is the worst of both.

### 8.4 Value maps

An enumerated `SYNTAX` becomes a value map on the target, reusing an existing map with the
same name rather than creating duplicates for every item built from the same enumeration.

---

## 9. Security

### 9.1 The single gate

The starting OID is the only user input that reaches a network call or a subprocess. It is
validated against `^\.?\d+(\.\d+)*$` and refused if it does not match, rather than escaped
and hoped for. Symbolic input is resolved through the MIB index, which is an array lookup,
and never passed to a shell. `contrib/zbx-snmpwalk.sh` validates it again on the poller.

### 9.2 Credentials

Resolved server-side from interface details with user macros expanded (global, then
inherited, then host). Never sent to the browser: `redacted()` reports `(set)` or
`(empty)` rather than a masked string, so the length does not leak either.

Secret text and Vault macro values are not readable through the API, for anyone. Vault
values live outside the database entirely; `usermacro.get` omits the `value` field for
Secret text. Neither can be resolved by the frontend at any permission level.

`macroMap()` therefore admits only `ZBX_MACRO_TYPE_TEXT`, and a non-text macro at a higher
precedence removes any readable value inherited from below it. Anything left unexpanded is
reported by `unresolvedCredentials()`, and `CWalkService` refuses the local and server
engines with the macro named rather than letting them run.

Filtering on Vault alone was the original bug. Secret text passed the filter, and because
the API omits the value, `{$SNMP_COMMUNITY}` expanded to an empty string: the walk
authenticated as nothing and timed out, with `(empty)` shown next to a host that plainly
had a community configured. An unexpanded macro reaching net-snmp as a literal is the
better failure, and refusing the walk outright is better still.

Only `CEngineScript` can walk such a host. Global script commands are one of the locations
where Zabbix server unmasks secret macro values, and `script.execute` takes only a
scriptid, a hostid and manual input, so the credential never passes through PHP at all.

`scrub()` is applied to engine error text before display, because net-snmp and the Zabbix
server both echo the community string back in failure messages.

### 9.3 Permissions

| Operation | Requires |
|---|---|
| View the console, run a walk | `min_user_type` (default Admin) and the Monitoring → Hosts UI element |
| Create items or discovery rules | Write access to the resolved target |
| Delete a snapshot | Write access to the host |
| Upload, reindex or delete MIBs | Super admin |

Gating reads on the UI element rather than the user type alone lets a read-only NOC role
have the console and the analysis without configuration rights.

### 9.4 Audit

Module actions cannot write to the Zabbix audit log. Walks, creations, input rejections,
exceptions and MIB changes are written with `error_log()` prefixed `snmpwalk`, which lands
in the SAPI log (the FPM pool log, the Apache vhost log, or the container's stderr). Not a
substitute for audit, but "who walked the customer's core switch at 3am" has an answer.

---

## 10. Frontend

### 10.1 Script delivery

`CView::includeJsFile()` echoes the partial's output directly into the page and does not
wrap it, so a `*.js.php` partial must supply its own `<script>` tags. Omitting them
renders the entire source as body text.

The module does not rely on that. The view calls
`(new CScriptTag($this->readJsFile('snmpwalk.view.js.php')))->show()`, which builds the
element with Zabbix's own class and passes the body through `CObject` unescaped. The
element cannot be missing.

### 10.2 Form controls

The interface picker is a native `<select>`, not `CSelect`. `CSelect` renders `<z-select>`,
a custom element that owns its option list; appending plain `<option>` children from
JavaScript populates the display but leaves `.value` empty. The symptom was a posted
`interfaceid=''` failing the `db` validation rule.

The filter form is not wrapped in `CFilter`, because `CFilter` renders its own `<form>` and
nesting forms is invalid HTML. The browser discards the inner one, which breaks the
multiselect's `dstfrm` reference.

### 10.3 Error envelope

Errors arrive in two shapes. This module's actions return `{error: "string"}`. Zabbix
returns `{error: {title, messages}}` when a request is rejected before the action runs.
Concatenating the second produces `[object Object]`. The client renders both, logs the raw
payload, and handles a non-JSON response (a PHP fatal or a login redirect returns HTML) by
showing the first 300 characters rather than a JSON parse error.

### 10.4 Chunk loop

The browser posts, appends rows, renders, and repeats until `done`, carrying the cursor and
buffer token. Stop sets a flag checked between chunks, so partial results survive. Progress
shows the running count and elapsed time.

---

## 11. Failure modes

Every one of these was hit during development. They are recorded because the diagnosis was
not obvious from the symptom.

| Symptom | Cause |
|---|---|
| JavaScript source rendered as page text | `*.js.php` partial without `<script>` tags |
| `Unexpected response for action X` | `checkInput()` returned false with no response set; `ZBase.php` throws when the response is null and `CNewValidator`'s reason went to the message stack unread |
| `[object Object]` | Zabbix's `{title, messages}` error object concatenated as a string |
| `Missing item field.` | Flat `item.test` payload |
| `Unsupported timeout value.` | `item.test` item with no `timeout` |
| `Undefined constant ZBX_PREPROC_CHANGE_PER_SECOND` | UI label used as a constant name |
| Walk returns nothing, no error | `interfaceid=''` posted from a `<z-select>`, or the device-side error read only from the transport layer |
| Discovery rule created somewhere unexpected | Target picker constructed but never attached to the dialog, so it used its defaults invisibly |

The last one is the instructive failure. `php -l`, `node --check` and the full test suite
passed on code that built a UI element into the void. Static checks cannot see that a DOM
node was never appended.

---

## 12. Testing

`tests/run.php` covers the logic with no Zabbix dependency: OID validation and ordering,
the walk parser including multi-line `Hex-STRING` continuations, diff semantics, type
mapping, structural table detection, and monitored OID extraction. 70 assertions.

`tests/constants.php` is a static check. It walks the PHP token stream across every module
file, extracts global constant references matching Zabbix's prefixes, and verifies each
against a vendored list of the names defined in `defines.inc.php`. Token-level rather than
regex, so `$ZBX_SERVER` (a variable) and `CSettingsHelper::ITEM_TEST_TIMEOUT` (a class
constant) are not false positives.

It exists because `ZBX_PREPROC_CHANGE_PER_SECOND` was a fatal waiting on one code path, and
`run.php` hid it: the stubs defined the invented name, so 68 assertions passed against a
constant that exists nowhere in Zabbix. A test fixture that does not mirror reality is
worse than no test, because it manufactures confidence.

**What is not covered.** The entire view layer. Nothing in the harness can tell that
`CFileUpload` does not exist, that a `<script>` tag is missing, or that a picker was never
attached. Every one of those shipped. The honest position is that this module has unit
tests and no integration tests, and that the failures which actually reached the user were
all in the untested half.

---

## 13. Version compatibility

| Surface | Stability |
|---|---|
| `host.get`, `item.create`, `discoveryrule.create`, `itemprototype.create`, `template.create`, `valuemap.create`, `script.execute` | Published API |
| `CController`, `CView`, `CHtmlPage`, form classes, style constants | Frontend internals, stable in practice, verified against 7.0 and 7.4 |
| `CZabbixServer::testItem()` and its payload | Internal and moving. The seam |
| `snmptranslate` output formats `-Tz` and `-Td` | net-snmp, stable for decades |

Regenerate `tests/zabbix-constants.txt` from `defines.inc.php` when targeting a new
release, and run `tests/constants.php`. That catches the renamed-constant class of
breakage without a running instance.

---

## 14. Open questions and next steps

**A collector-side engine.** The only unmaintainable dependency is `item.test`. An engine
that talks to an agent already running on the proxy host, over an interface whose owner
controls both ends, would remove it, remove the `php-snmp` requirement in the frontend
container, and deliver typed varbinds instead of recovering types from net-snmp text. The
unresolved part is how credentials reach that agent: either a pre-shared set keyed by
host, or the agent resolving them itself through the Zabbix API.

**Template export.** Objects are created through the API against a live template. Emitting
importable YAML instead would make output portable between instances and reviewable in
version control, which is what the template-builder project actually wants. The analysis
side already produces everything needed; what is missing is a serialiser over
`CTypeMapper` and `CTableAnalyzer` output.

**Bulk retrieval with a cursor.** No engine has both. The local engine trades throughput
for resumability because PHP does not expose per-PDU GETBULK; the server-side engines do
the reverse because `walk[]` returns a whole subtree in one value. A collector-side engine
is the only shape that could offer both.

**Integration testing.** Every failure that reached a user was in the view layer. A
headless browser test that loads the console, runs a walk against `snmpsim`, and asserts
that rows render would have caught most of them.
