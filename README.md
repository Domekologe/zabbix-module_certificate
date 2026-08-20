# Certificate Monitor - Zabbix frontend module

A standalone Zabbix **frontend module** that adds a **Monitoring -> Certificates** page.
The page lists all monitored websites with the latest certificate expiry data and provides
**Add / Edit / Clone / Delete** forms that create and maintain the required host, items, triggers and
user macros through the internal Zabbix API.

A **template-only alternative** is provided in `template_certificate_monitoring.yaml` for
installations where a frontend module cannot be installed.

---

## Feature overview

| Feature | Where |
|---|---|
| Add a website (creates host, master item, dependent items, triggers, macros) | list -> **Add website** |
| **Bulk import** of many websites from a pasted list or a CSV file, with a preview and per-line selection | list -> **Bulk import** |
| Optional **security triggers**: issuer changed, certificate replaced, weak key algorithm, weak signature algorithm | add/edit form, settings |
| **Dashboard widget** listing the certificates that expire soonest | separate module `certmonitor_widget/` |
| **Edit** an existing website (macros, interface, group, tags, thresholds, status) | list -> **Edit** |
| **Clone** an existing website into a pre-filled add form | list -> **Clone** |
| **Details**: every field read from the certificate, from the raw master-item JSON | list -> **Details** |
| **Settings**: defaults used to pre-fill the add form | list -> **Settings** (Super admin) |
| **Test connection** before adding (TLS probe from the frontend, non-blocking) | add/edit form |
| Filter bar: website substring, host group, validation state, expiring within N days, monitoring status | list |
| Sortable columns, including *Days left* | list |
| Summary counters: total / OK / expiring soon / expired / invalid / no data / disabled | list |
| **Check now** per row and as a bulk action (`task.create`) | list |
| Bulk **Enable** / **Disable** monitoring | list |
| **Last checked** column | list |
| **CSV export** of the filtered, sorted list | list -> **Export to CSV** |
| Paging for large lists | list |
| Per-website custom host tags | add/edit form, list column, CSV |
| Colour-coded *Days left*, using that host's own thresholds | list, details |
| Link per row to the raw master-item history | list -> **History** |
| `{$CERT.EXPIRY.*}` sanity check that warns about hand-edited, out-of-order macros | list, details |
| Backfill of dependent items that an older module version did not create | happens on **Edit** -> **Update** |
| Accessible markup: labels tied to inputs, `aria-label` on controls, `aria-live` on the probe result | everywhere |

---

## Contents

```
Zabbix-Module/
├── README.md                              this file
├── template_certificate_monitoring.yaml   alternative: importable Zabbix template
├── certmonitor_widget/                    the dashboard widget (a SECOND module, see below)
│   ├── manifest.json                      "type": "widget"
│   ├── Widget.php
│   ├── includes/
│   │   └── WidgetForm.php                 host group filter + "Show lines"
│   ├── actions/
│   │   └── WidgetView.php                 reads the certmonitor hosts
│   └── views/
│       ├── widget.view.php
│       └── widget.edit.php
└── certmonitor/                           the frontend module (copy this folder to ui/modules/)
    ├── manifest.json
    ├── Module.php
    ├── actions/
    │   ├── CertList.php            list page + CSV export
    │   ├── CertView.php            detail page of one website
    │   ├── CertEdit.php            add / edit / clone form
    │   ├── CertCreate.php          validates the add form, delegates to CertProvision
    │   ├── CertImport.php          bulk import form and preview
    │   ├── CertImportCreate.php    creates the selected lines of the preview
    │   ├── CertUpdate.php          updates an existing website
    │   ├── CertDelete.php
    │   ├── CertStatusUpdate.php    shared base of enable/disable
    │   ├── CertEnable.php
    │   ├── CertDisable.php
    │   ├── CertExecuteNow.php      "Check now" via task.create
    │   ├── CertCheck.php           AJAX endpoint of "Test connection"
    │   ├── CertSettings.php        settings form
    │   └── CertSettingsUpdate.php  stores the settings
    ├── includes/
    │   ├── CertHelper.php          constants and pure helpers
    │   ├── CertProvision.php       THE creation logic: host, items, triggers, macros
    │   ├── CertImportParser.php    parses and classifies the bulk import list
    │   ├── CertConfig.php          persistent module settings
    │   └── CertProbe.php           the frontend-side TLS probe
    ├── views/
    │   ├── certmonitor.list.php       error boundary only
    │   ├── certmonitor.list.body.php  the actual list page
    │   ├── certmonitor.list.csv.php
    │   ├── certmonitor.view.php
    │   ├── certmonitor.edit.php
    │   ├── certmonitor.import.php
    │   └── certmonitor.settings.php
    └── assets/
        ├── css/
        │   └── certmonitor.css
        └── js/
            └── certmonitor.js
```

### Registered actions

| Action | Class | View / layout | Purpose |
|---|---|---|---|
| `certmonitor.list` | `CertList` | `certmonitor.list` / htmlpage | list page |
| `certmonitor.list.csv` | `CertList` | `certmonitor.list.csv` / **csv** | CSV export of the same data |
| `certmonitor.view` | `CertView` | `certmonitor.view` / htmlpage | detail page |
| `certmonitor.edit` | `CertEdit` | `certmonitor.edit` / htmlpage | add / edit / clone form |
| `certmonitor.create` | `CertCreate` | redirect | creates a website |
| `certmonitor.import` | `CertImport` | `certmonitor.import` / htmlpage | bulk import form + preview |
| `certmonitor.import.create` | `CertImportCreate` | redirect | creates the selected lines |
| `certmonitor.update` | `CertUpdate` | redirect | updates a website |
| `certmonitor.delete` | `CertDelete` | redirect | deletes websites |
| `certmonitor.enable` | `CertEnable` | redirect | enables monitoring |
| `certmonitor.disable` | `CertDisable` | redirect | disables monitoring |
| `certmonitor.execute` | `CertExecuteNow` | redirect | "Check now" (`task.create`) |
| `certmonitor.check` | `CertCheck` | — / **json** | AJAX TLS probe |
| `certmonitor.settings` | `CertSettings` | `certmonitor.settings` / htmlpage | settings form |
| `certmonitor.settings.update` | `CertSettingsUpdate` | redirect | stores the settings |

Every POST action validates a CSRF token. For module controllers, Zabbix checks the token against the
**full action name** (`CController::checkCsrfToken()` contains
`if (strpos(get_class($this), 'Modules\\') === 0) { return CCsrfTokenHelper::check($token, $this->action); }`),
so each view requests `CCsrfTokenHelper::get('certmonitor.<action>')`.

---

## Requirements

| Requirement | Detail |
|---|---|
| Zabbix frontend | 7.2 or 7.4 (module `manifest_version` 2.0) |
| PHP | 8.0+ (as required by Zabbix 7.x itself) |
| Data collection | **Zabbix agent 2** with the built-in `WebCertificate` plugin |
| External dependencies | none (no Composer packages) |

### Agent requirement (important)

The item key `web.certificate.get` is provided **only by Zabbix agent 2**. The classic C agent
(`zabbix_agentd`) does **not** support it. Zabbix agent 2 ships the `WebCertificate` plugin by
default, so no extra installation or plugin configuration is needed.

Verified against:
<https://www.zabbix.com/documentation/7.4/en/manual/config/items/itemtypes/zabbix_agent/zabbix_agent2>
and <https://www.zabbix.com/documentation/current/en/manual/guides/monitor_certificate>
("Monitor website certificates with Zabbix agent 2 (passive)").

The item type used is **Zabbix agent (passive)**, therefore the created host **must have an
"Agent" interface**. The official quick-reference guide states this explicitly:

> In the **Interfaces** field, add an interface of type "Agent" and specify an IP address.

The agent interface must point at **the machine that runs Zabbix agent 2** (the machine performing
the outgoing TLS connection), **not** at the monitored website. This is why the "Add website" form
has separate `Zabbix agent address` / `Zabbix agent port` fields (default `127.0.0.1` / `10050`,
which matches the assumption of the official guide that server and agent run on the same host).

To test the agent before using the module:

```
zabbix_get -s 127.0.0.1 -k 'web.certificate.get[www.example.com,443]'
```

---

## Item key and JSON response (verified)

```
web.certificate.get[hostname,<port>,<address>]
```

* `hostname` - IP or DNS name. May contain the URL scheme (`https` only), a path (ignored) and a
  port. If a port is given in both the first and second parameter, the values must match.
  If `address` is specified, `hostname` is only used for SNI and hostname verification.
* `port` - port number, default `443`.
* `address` - IP or DNS name. If specified, it is used for the connection.

Return value: a JSON object. Verified field names:

| JSONPath | Meaning |
|---|---|
| `$.x509.version` | X.509 version |
| `$.x509.serial_number` | serial number |
| `$.x509.signature_algorithm` | signature algorithm |
| `$.x509.issuer` | issuer |
| `$.x509.not_before.value` | validity start, text |
| `$.x509.not_before.timestamp` | validity start, UNIX timestamp |
| `$.x509.not_after.value` | validity end, text |
| `$.x509.not_after.timestamp` | validity end, UNIX timestamp |
| `$.x509.subject` | subject |
| `$.x509.public_key_algorithm` | public key algorithm |
| `$.x509.alternative_names` | subject alternative names (array), otherwise `null` |
| `$.result.value` | validation result: `valid`, `valid-but-self-signed`, `invalid` |
| `$.result.message` | detailed validation message |
| `$.sha1_fingerprint` | SHA-1 fingerprint |
| `$.sha256_fingerprint` | SHA-256 fingerprint |

Documented example response:

```json
{
  "x509": {
    "version": 3,
    "serial_number": "0ad893bafa68b0b7fb7a404f06ecaf9a",
    "signature_algorithm": "ECDSA-SHA384",
    "issuer": "CN=DigiCert Global G3 TLS ECC SHA384 2020 CA1,O=DigiCert Inc,C=US",
    "not_before": { "value": "Jan 15 00:00:00 2025 GMT", "timestamp": 1736899200 },
    "not_after":  { "value": "Jan 15 23:59:59 2026 GMT", "timestamp": 1768521599 },
    "subject": "CN=*.example.com,O=Internet Corporation for Assigned Names and Numbers,L=Los Angeles,ST=California,C=US",
    "public_key_algorithm": "ECDSA",
    "alternative_names": ["*.example.com", "example.com"]
  },
  "result": { "value": "valid", "message": "certificate verified successfully" },
  "sha1_fingerprint": "310db7af4b2bc9040c8344701aca08d0c69381e3",
  "sha256_fingerprint": "455943cf819425761d1f950263ebf54755d8d684c25535943976f488bc79d23b"
}
```

Documented limitations of the agent check:

* The item becomes **unsupported** if the destination does not exist, is unavailable, or if the TLS
  handshake fails with any error other than an invalid certificate.
* AIA (Authority Information Access), CRLs, OCSP (including OCSP stapling) and Certificate
  Transparency are **not** supported.

---

## Installation

> **Upgrading from a build before 1.2.0:** the module IDs changed from `ilf_certmonitor` /
> `ilf_certmonitor_widget` to `dks_certmonitor` / `dks_certmonitor_widget`. Zabbix keys the `module`
> database table by ID, so after **Scan directory** the old entries disappear and the new ones show up
> as freshly discovered modules — enable them again. One useful side effect: because a new ID counts
> as a first discovery, the `config` defaults from `manifest.json` are written to the database this
> time, which they are not for an already registered module. Settings you had entered on the settings
> page are tied to the old ID and have to be entered once more.
>
> The monitored hosts, items and triggers are unaffected by the rename — they are ordinary Zabbix
> objects and keep collecting data throughout.

1. Copy the `certmonitor` directory into the `modules` directory of the Zabbix frontend:

   ```
   <zabbix frontend root>/ui/modules/certmonitor/
   ```

   Typical locations:

   | Package | Path |
   |---|---|
   | RHEL/Alma/Rocky (Apache) | `/usr/share/zabbix/ui/modules/certmonitor/` |
   | Debian/Ubuntu (Apache) | `/usr/share/zabbix/ui/modules/certmonitor/` |
   | Zabbix 7.4 nginx packages | `/usr/share/zabbix/ui/modules/certmonitor/` |
   | Source install | `<docroot>/ui/modules/certmonitor/` |

   > On Zabbix 7.0 and later the frontend files live under `ui/`. If your installation predates that
   > layout, use the `modules` directory next to `index.php`.

2. Make sure the web server user can read the files:

   ```
   chown -R root:apache /usr/share/zabbix/ui/modules/certmonitor
   chmod -R o-rwx,g+rX  /usr/share/zabbix/ui/modules/certmonitor
   ```

3. In the frontend, go to **Administration -> General -> Modules** and click **Scan directory**.

4. Find **Certificate Monitor** in the list and click the **Disabled** link to switch the status to
   **Enabled**.

5. Reload the page. A **Certificates** entry appears under **Monitoring**
   (inserted after *Latest data*; if that entry is not visible for your role, it is appended at the
   end of the Monitoring submenu).

6. **For the dashboard widget**, repeat steps 1-4 with the `certmonitor_widget` directory:

   ```
   cp -r certmonitor_widget /usr/share/zabbix/ui/modules/
   ```

   It is a **second, separate module** (a widget module cannot also add a menu entry — see
   *Dashboard widget* below) and has to be enabled separately. Both are installed into
   `ui/modules/`; `ui/widgets/` is reserved for the widgets shipped with Zabbix. After enabling it,
   **Certificate expiry** is available in the *Add widget* dialog of any dashboard.

Module layout and registration reference:
<https://www.zabbix.com/documentation/7.4/en/devel/modules/file_structure> and
<https://www.zabbix.com/documentation/7.4/en/devel/modules/tutorials/module>

---

## Permissions

| Action | Required user role permission |
|---|---|
| View **Monitoring -> Certificates**, **Details**, **Export to CSV** | UI element `Monitoring -> Hosts` (`ui.monitoring.hosts`) |
| **Add** / **Edit** / **Clone** / **Delete** / **Enable** / **Disable** / **Test connection** | User type **Admin** or **Super admin**, plus UI element `Data collection -> Hosts` (`ui.configuration.hosts`) |
| **Check now** (single and bulk) | User type **User** or higher; write permission on the item, unless the role grants *Execute now* (`actions.invoke_execute_now`) |
| **Settings** page and saving settings | User type **Super admin** only (see below) |

In addition, the acting user must have **read-write** permission on the host group selected in the
form (the module only offers host groups returned by `hostgroup.get` with `editable = true`), and
read permission on the resulting host to see it in the list.

Users without host-creation rights can still open the page; the **Add website** button and the
checkboxes/Delete button are hidden for them.

The module never touches hosts it did not create: every host it creates is tagged
`certmonitor: website`, and both the list and the delete action filter on that tag.

---

## What "Add website" creates

Form fields:

| Field | Default | Notes |
|---|---|---|
| Hostname/FQDN | - | DNS name or IP of the website |
| Port | `443` | 1-65535 |
| IP/address override | empty | used for the connection; hostname is then only used for SNI/verification |
| Host group | - | only groups the user can write to |
| Zabbix agent address | `127.0.0.1` | machine running Zabbix agent 2 |
| Zabbix agent port | `10050` | agent listen port |
| Visible host name | auto | defaults to `Certificate: <host>:<port>` |
| Enabled | on | when cleared, the host is created but not monitored |
| Tags | empty | one host tag per line, `name` or `name=value` |
| Description | empty | |
| Warning / Average / High (days) | `30` / `14` / `7` | must satisfy warning > average > high |
| Ignore certificate validation errors | off | see below |
| Issuer changed / Certificate replaced / Weak public key algorithm / Weak signature algorithm | off | the four optional security triggers, see *Security triggers* |

All defaults in the table above are the **built-in fallbacks**. When module settings are stored (see
*Settings page*), those are used instead.

### Ignore certificate validation errors

Tick this for hosts with a self-signed certificate, an internal CA the agent does not trust, or a
name mismatch you knowingly accept. The website is still monitored and the expiry triggers still
fire — only the *validation failed* trigger is created **disabled**, and `{$CERT.IGNORE.VALIDATION}`
is set to `1` on the host. The trigger stays visible, so it can be switched back on by hand at any
time without recreating the host.

Note that `web.certificate.get` returns data even for a broken certificate — the item only goes
unsupported when the TLS connection itself fails. And `valid-but-self-signed` never triggers the
validation problem, because the trigger matches on `invalid` only.

Created objects:

**Host** - technical name `cert_<hostname>_<port>`, one Agent interface, host tags
`certmonitor: website` and `website: <hostname>`.

**Host macros** (editable afterwards on the host):

* `{$CERT.WEBSITE.HOSTNAME}`, `{$CERT.WEBSITE.PORT}`, `{$CERT.WEBSITE.IP}`
* `{$CERT.EXPIRY.WARN}`, `{$CERT.EXPIRY.AVG}`, `{$CERT.EXPIRY.CRIT}`
* `{$CERT.IGNORE.VALIDATION}`
* `{$CERT.SEC.ISSUER.CHANGED}`, `{$CERT.SEC.FINGERPRINT.CHANGED}`, `{$CERT.SEC.WEAK.KEY}`,
  `{$CERT.SEC.WEAK.SIGNATURE}` — `1`/`0`, see *Security triggers*
* `{$CERT.KEY.ALGO.WEAK}`, `{$CERT.SIG.ALGO.WEAK}` — the patterns used by the two weak-algorithm
  triggers

---

## Security triggers

Four **optional** triggers can be switched on per website in the *Security triggers* fieldset of the
add/edit form, and org-wide in the settings page. They are **off by default**, so upgrading the module
never silently adds problems to an existing installation.

Like the validation trigger, all four are **always created on the host**. Clearing a checkbox creates
(or leaves) the trigger **disabled** instead of deleting it, so it stays visible and can be flipped by
hand. The intent is recorded in a `{$CERT.SEC.*}` macro, so it can also be changed later on the host
itself — reopening the entry in the form and saving will then reflect the macro.

| Trigger | Expression | Default severity | Enabled by |
|---|---|---|---|
| Issuer changed | `change(/<host>/cert.issuer)=1` | Average (configurable) | `{$CERT.SEC.ISSUER.CHANGED}` |
| Certificate replaced (SHA-256 fingerprint changed) | `change(/<host>/cert.sha256_fingerprint)=1` | Warning (configurable) | `{$CERT.SEC.FINGERPRINT.CHANGED}` |
| Weak public key algorithm | `find(/<host>/cert.public_key_algorithm,,"iregexp","{$CERT.KEY.ALGO.WEAK}")=1` | Average | `{$CERT.SEC.WEAK.KEY}` |
| Weak signature algorithm | `find(/<host>/cert.signature_algorithm,,"iregexp","{$CERT.SIG.ALGO.WEAK}")=1` | High | `{$CERT.SEC.WEAK.SIGNATURE}` |

### Why `change()` and not `last()`/`prev()` (verified)

* `prev()` **does not exist** in Zabbix 7.x. Any `last() <> prev()` expression is invalid syntax and
  will be rejected. Verified against the 7.4 history-function reference, which lists no such function.
* `change(/host/key)` **is** supported for string items in 7.x: *"Supported value types: Float,
  Integer, String, Text, Log"* and *"For strings returns: 0 - values are equal; 1 - values differ."*
* `last(/host/key,#1) <> last(/host/key,#2)` would also work, but the trigger-expression page states
  that *"String operand is still cast to numeric if another operand is numeric"*, so two textually
  different but numerically equal values could compare as equal. `change()` has no such ambiguity for
  string types, which is why it is used here.
* `find(/host/key,,"iregexp","pattern")` — the second parameter (evaluation period) may be left empty;
  it then *"defaults to the latest value"*. For string/text/log items only `eq`, `ne`, `like`,
  `regexp` and `iregexp` are supported operators. `iregexp` is used so `sha1` matches `SHA1-RSA`.

Sources: <https://www.zabbix.com/documentation/7.4/en/manual/appendix/functions/history> and
<https://www.zabbix.com/documentation/7.4/en/manual/config/triggers/expression>

### "Certificate replaced" fires on every renewal — by design

The SHA-256 fingerprint of a certificate changes whenever the certificate itself is replaced, and a
**perfectly normal renewal replaces the certificate**. This trigger will therefore fire every time
Let's Encrypt (or any other CA) renews the certificate. That is intentional: it is the only reliable
"this is not the same certificate as before" signal available from `web.certificate.get`.

Treat it as an **audit signal, not an incident**. That is why its default severity is only
*Warning* — the severity is configurable org-wide in the settings page. Because a Zabbix trigger's
severity is a static property and cannot be driven by a user macro, the severity is applied **when the
trigger is created**; changing it in the settings afterwards does not rewrite existing triggers.

### "Weak key": what the agent actually reports (important limitation)

**The Zabbix agent 2 `web.certificate.get` item does not report a key length.** This was verified in
the plugin source, `src/go/plugins/web/certificate/certificate.go` (branch `release/7.4`), whose
output struct contains exactly:

```
x509.version, x509.serial_number, x509.signature_algorithm, x509.issuer,
x509.not_before.value, x509.not_before.timestamp,
x509.not_after.value,  x509.not_after.timestamp,
x509.subject, x509.public_key_algorithm, x509.alternative_names,
result.value, result.message, sha1_fingerprint, sha256_fingerprint
```

`public_key_algorithm` is produced by Go's `x509.PublicKeyAlgorithm.String()` and can only ever be
`RSA`, `DSA`, `ECDSA`, `Ed25519` or `Unknown` — a bare algorithm name, **never a modulus size and
never a curve**. A check such as *"RSA below 2048 bit"* is therefore **impossible** from this item.

Rather than invent a field that does not exist, the trigger is based on what is available: the
**algorithm name**, matched against the pattern `{$CERT.KEY.ALGO.WEAK}` (default `DSA|Unknown`).
If you need the actual key size, collect it separately, e.g. with a script/external item running
`openssl x509 -noout -text`.

`signature_algorithm` comes from `x509.SignatureAlgorithm.String()` and looks like `SHA256-RSA`,
`SHA1-RSA`, `MD5-RSA` or `ECDSA-SHA384`, so the default pattern `SHA1|MD5|MD2` matches the weak ones.

### Adding these triggers to existing websites

Open the website with **Edit**, tick what you want and press **Update**. `CertUpdate` backfills any
missing `{$CERT.*}` macro, any missing dependent item **and** any missing security trigger, so a host
created by version 1.0 or 1.1 is upgraded in place without losing history.

Triggers created from version 1.2.0 on carry the tag `certmonitor_trigger: <id>` (`issuer_changed`,
`fingerprint_changed`, `weak_key`, `weak_signature`, `validation`, `expired`, `expiry_crit`,
`expiry_avg`, `expiry_warn`), which is how the update path recognises them even after a rename.

---

## Bulk import

**Admin only** (same permission as *Add website*: `USER_TYPE_ZABBIX_ADMIN` plus
`UI_CONFIGURATION_HOSTS`). Reachable from the **Bulk import** button on the list page.

### Format

```
host[:port][,hostgroup][,description]
```

| Field | Required | Default |
|---|---|---|
| `host` | yes | — DNS name or IP address of the website |
| `:port` | no | the **Default port** from the settings (shipped: `443`) |
| `hostgroup` | no | the **Default host group** from the settings |
| `description` | no | empty |

Rules:

* One website per line.
* Each line is parsed with `str_getcsv()`, so a **pasted list and an uploaded CSV file use exactly the
  same format**, and a field containing a comma can be quoted with double quotes.
* Everything after the **second** comma belongs to the description, so it may contain further commas
  even unquoted.
* Empty lines and lines starting with `#` are ignored.
* A leading header line whose first field is `host` or `hostname` is ignored, so a CSV exported with a
  header can be fed in unchanged.
* A host group is **never created**: an unknown or non-writable group name makes the line *invalid*.
* If a line omits the group and **no** default host group is configured, that line is *invalid*.
* Maximum **500** entries per import; maximum **1 MiB** for an uploaded file, which must be UTF-8
  (a UTF-8 BOM is stripped automatically).

Example:

```
www.example.com
api.example.com:8443,Web servers
intranet.example.org,Internal,"Reverse proxy, HQ site"
# this line is a comment and is ignored
```

Everything not expressible in a line — Zabbix agent address and port, update interval, warning
thresholds, "ignore validation", the security triggers and the host name prefix — is taken from the
**module settings** (`CertConfig`), exactly as if the entries had been created one by one.

### Preview and import

Pressing **Preview** (or uploading a file, which previews immediately) shows a table with one row per
line and a per-line status:

| Status | Meaning |
|---|---|
| **OK** (green) | the line is valid and will be created; checkbox ticked |
| **Already monitored** (orange) | a host with that technical name already exists, or an earlier line in the same list defines it; no checkbox |
| **Invalid** (red) | with the exact reason: bad host name, bad port, unknown host group, … ; no checkbox |

Individual lines can be deselected before pressing **Import selected**. The import then reports:

```
Import finished: 12 created, 1 skipped, 2 failed.
```

**One failing line never aborts the rest.** Each line is created independently; a failure is reported
with its line number, the original text and the API error, and the loop continues.
`CertProvision::create()` rolls back the partially created host of a failing line by itself, so no
half-configured host is left behind.

### Note on the refactor

The creation logic that used to live inside `CertCreate` now lives in
`includes/CertProvision.php` (`CertProvision::create()`), together with the macro set, the dependent
item builder and all trigger definitions. `CertCreate`, `CertImportCreate` and the backfill paths of
`CertUpdate` all call into it, so the single and the bulk path cannot drift apart.

---

## Dashboard widget

### It has to be a second module (verified)

A Zabbix 7.x module is **either** a frontend module **or** a dashboard widget — one manifest cannot be
both. Verified in `ui/include/classes/core/CModuleManager.php` (branch `release/7.4`), which accepts
only two values for the manifest key `type`:

```php
if (array_key_exists('type', $manifest)
        && !in_array($manifest['type'], [CModule::TYPE_MODULE, CModule::TYPE_WIDGET], true)) {
    return null;
}
...
$base_classname = $manifest['type'] === CModule::TYPE_WIDGET ? CWidget::class : CModule::class;
$classname      = $manifest['type'] === CModule::TYPE_WIDGET ? 'Widget' : 'Module';
```

The type selects the base class **exclusively**: a widget instantiates `Widget.php` extending
`Zabbix\Core\CWidget` and therefore has no `CModule::init()` in which a menu entry could be added.

**Consequently the widget is shipped as a separate module in `certmonitor_widget/`, and both folders
must be installed for the full functionality.** The widget works on its own — it only reads hosts
tagged `certmonitor: website` — but without the frontend module there is no detail page, so it then
renders the website names as plain text instead of dead links (`WidgetView` checks
`APP::ModuleManager()->getModule('dks_certmonitor')`).

### Installation

```
cp -r certmonitor        /usr/share/zabbix/ui/modules/
cp -r certmonitor_widget /usr/share/zabbix/ui/modules/
```

Then enable **both** entries under *Administration → General → Modules* (press **Scan directory**
first). The widget then appears in the *Add widget* dialog of any dashboard as **Certificate expiry**.

Note that a widget is installed into `ui/modules/` like any other module — it does **not** go into
`ui/widgets/`, which is reserved for the widgets shipped with Zabbix. The namespace prefix is derived
from the directory: `CModuleManager::loadManifest()` does
`$manifest['namespace'] = ucfirst($relative_path_parts[0]).'\\'.$manifest['namespace'];`, so a widget
under `ui/modules/certmonitor_widget` with `"namespace": "CertMonitorWidget"` gets the PHP namespace
`Modules\CertMonitorWidget` — which is what the files use.

### What the widget shows

| Column | Notes |
|---|---|
| Website | the visible host name; links to *Monitoring → Certificates → detail* |
| Days left | colour-coded with **that host's own** `{$CERT.EXPIRY.*}` macros: green / yellow / orange / red, `expired` when negative |
| Expires on | the `cert.not_after` timestamp |
| Validation | `valid` / `valid but self-signed` / `invalid` / `no data`, colour-coded |

Configuration:

| Field | Default | Notes |
|---|---|---|
| Host groups | empty (= all) | multiselect; nested groups are included via `getSubGroups()` |
| Show lines | `10` | 1 … 1000 (`ZBX_MIN_WIDGET_LINES` … `ZBX_MAX_WIDGET_LINES`) |

Rows are sorted by expiry, soonest first. Hosts without a `cert.not_after` value yet are not shown.
The default refresh rate is 15 minutes, because the master item is polled once per hour by default.

The widget needs **no JavaScript**: `CWidget::DEFAULT_JS_CLASS` is `CWidget`, whose default
`setContents()` writes the `body` produced by `views/widget.view.php` into the widget. Only
`widget.<id>.view` is declared in the manifest; `widget.<id>.edit` uses the built-in
`CControllerDashboardWidgetEdit` with `views/widget.edit.php`, both auto-registered by
`CWidget::getActions()`.

---

## Detail page

Clicking the host name (or **Details**) in the list opens *Monitoring → Certificates → detail*, which
shows everything about that website in one place:

* **Certificate** — every field that was actually read from the certificate: version, serial number,
  signature algorithm, public key algorithm, subject, issuer, subject alternative names, valid from
  and valid until (both the text reported by the agent and the formatted timestamp), days remaining
  (coloured with that host's own thresholds), validation result and message, and the SHA-1 and
  SHA-256 fingerprints. A link leads to the raw values of the master item.

  The primary source is the **latest value of the master item**, read with
  `Manager::History()->getLastValues()` and `json_decode()`. Three cases are handled explicitly:

  | Situation | What is shown |
  |---|---|
  | No value collected yet | *"No certificate has been collected yet…"* plus the hint to use **Check now** |
  | Value present but not valid JSON | An explicit error line, and the raw-value link so it can be inspected |
  | Master item unsupported or disabled | The item error text / a "master item is disabled" note above the table |

  If the raw JSON is unavailable because the master item stores no history (hosts created by module
  version 1.0 used `history = 0`), the section falls back to the latest values of the **dependent
  items** and says so. Opening that website with **Edit** and pressing **Update** repairs it: the
  master item is switched to a real history period and any missing dependent item is created.

* **Configuration** — monitored website and port, address override, the agent interface that
  performs the check, technical and visible host name, host groups, host status, description,
  the three thresholds, and whether validation is enforced or ignored.
* **User macros** — every `{$CERT.*}` macro with its value and description.
* **Items and latest values** — master and dependent items with key, interval, status, the latest
  value (UNIX timestamps rendered as dates) and when it was collected. Item errors are shown as a
  hoverable marker.
* **Triggers** — description, severity, enabled/disabled, current OK/PROBLEM state and the fully
  expanded expression.

Buttons lead to *Latest data* and, for users who may configure hosts, straight to the item and
trigger configuration of that host.

**Master item** - type *Zabbix agent*, value type *Text*, update interval `1h` (configurable in the
settings), preprocessing *Discard unchanged with heartbeat* `6h`, history `7d`:

```
web.certificate.get[{$CERT.WEBSITE.HOSTNAME},{$CERT.WEBSITE.PORT},{$CERT.WEBSITE.IP}]
```

The key is built from user macros on purpose, so the monitored target can be changed later by
editing the host macros only. This is exactly what **Edit** does — the item is never recreated, so
all collected history survives an edit.

History is kept for `7d` because the *Certificate* section of the detail page reads the raw JSON from
the history tables; an item with `history = 0` never writes one. One JSON document per changed
certificate per week is negligible in size.

**Dependent items** (all with JSONPath preprocessing):

| Key | JSONPath | Value type |
|---|---|---|
| `cert.not_after` | `$.x509.not_after.timestamp` | Numeric (unsigned), units `unixtime` |
| `cert.not_after.value` | `$.x509.not_after.value` | Character |
| `cert.not_before` | `$.x509.not_before.timestamp` | Numeric (unsigned), units `unixtime` |
| `cert.issuer` | `$.x509.issuer` | Text |
| `cert.subject` | `$.x509.subject` | Text |
| `cert.alternative_names` | `$.x509.alternative_names` | Text |
| `cert.validation` | `$.result.value` | Character |
| `cert.message` | `$.result.message` | Text |
| `cert.sha256_fingerprint` | `$.sha256_fingerprint` | Character |
| `cert.sha1_fingerprint` | `$.sha1_fingerprint` | Character |
| `cert.version` | `$.x509.version` | Character |
| `cert.serial_number` | `$.x509.serial_number` | Character |
| `cert.signature_algorithm` | `$.x509.signature_algorithm` | Character |
| `cert.public_key_algorithm` | `$.x509.public_key_algorithm` | Character |

The last five were added in version 1.1. Existing hosts get them automatically the next time they are
saved with **Edit** -> **Update**.

**Triggers** (7.x expression syntax):

| Severity | Expression |
|---|---|
| AVERAGE - validation failed | `find(/<host>/cert.validation,,"like","invalid")=1` |
| DISASTER - expired | `(last(/<host>/cert.not_after) - now()) < 0` |
| HIGH - expires soon | `(last(/<host>/cert.not_after) - now()) / 86400 < {$CERT.EXPIRY.CRIT}` |
| AVERAGE - expires soon | `(last(/<host>/cert.not_after) - now()) / 86400 < {$CERT.EXPIRY.AVG}` |
| WARNING - expires soon | `(last(/<host>/cert.not_after) - now()) / 86400 < {$CERT.EXPIRY.WARN}` |

The triggers are chained with dependencies (warning -> average -> high -> expired -> validation
failed), so only the most severe problem is shown at any time.

Since version 1.2 four more triggers are created in addition, all of them **disabled by default** and
independent of that dependency chain — see *Security triggers* below. Every trigger created from 1.2
on also carries the tag `certmonitor_trigger: <id>`.

The `(last(...) - now()) / 86400 < {$MACRO}` form and the `find(...,,"like","invalid")=1` form are
taken from the official Zabbix template *Website certificate by Zabbix agent 2*
(`templates/app/certificate_agent2/template_app_certificate_agent2.yaml`, branch `release/7.4`).

**Delete** removes the host, which removes its items, triggers and history.

---

## Editing a website

The **Edit** link in each row opens the same form as **Add website**, with a `hostid` parameter; the
title and the submit button change to *Edit website* / *Update*, and the form posts to
`certmonitor.update`.

Editable: visible name, description, host group, monitored hostname, port, address override, agent
address, agent port, custom tags, the three thresholds, the ignore-validation flag, the four security
trigger checkboxes and the host enabled/disabled status.

What `certmonitor.update` does:

* **Macros only, no item recreation.** The master item key is
  `web.certificate.get[{$CERT.WEBSITE.HOSTNAME},{$CERT.WEBSITE.PORT},{$CERT.WEBSITE.IP}]`, so
  changing the target is a pure macro update. All history is preserved.
  Macros that do not belong to this module are kept untouched, and existing `hostmacroid`s are
  reused, so macro rows are updated instead of deleted and re-inserted.
* Updates the **primary agent interface** in place (the existing `interfaceid` is passed, so the
  item → interface reference stays valid).
* Updates the **host group**, visible name, description and status.
* Rewrites the **trigger names and event names**: those embed `<hostname>:<port>` in square brackets,
  e.g. `Certificate [www.example.com:443]: expired`. Only the bracketed part is replaced, so a
  trigger name that a user has customised keeps the customisation.
* Enables or disables the **validation trigger** according to the checkbox (the trigger is identified
  by the item it reads, `cert.validation`, not by its name).
* Refreshes the master item description, sets `history` to `7d`, and creates any **missing dependent
  item**.

**The technical host name is not changed.** It appears in every trigger expression of the host
(`/cert_www.example.com_443/cert.not_after`), so renaming it would mean rewriting all of them. It is
shown read-only in the form with an explanation. The authoritative target is the macro set.

**Partial failure** is reported explicitly. Nothing is rolled back on update (unlike create, where
the freshly made host is deleted), because deleting the host would destroy collected history. If a
later step fails you get both the API error and a second message saying the website was only
partially updated and should be checked.

**Clone** opens the add form pre-filled from an existing entry, with the hostname and visible name
cleared, and creates a brand new host on submit.

---

## Settings page

Reachable from the **Settings** button on the list page. It holds the values that pre-fill the
*Add website* form:

| Setting | Built-in fallback |
|---|---|
| Default port | `443` |
| Default Zabbix agent address | `127.0.0.1` |
| Default Zabbix agent port | `10050` |
| Default host group | not preselected |
| Default update interval (master item) | `1h` |
| Host name prefix | `cert_` |
| Ignore certificate validation errors by default | off |
| Default warning / average / high (days) | `30` / `14` / `7` |
| Issuer changed (default) | off |
| Severity of "issuer changed" | Average |
| Certificate replaced (default) | off |
| Severity of "certificate replaced" | Warning |
| Weak public key algorithm (default) | off |
| Weak public key algorithms | `DSA\|Unknown` |
| Weak signature algorithm (default) | off |
| Weak signature algorithms | `SHA1\|MD5\|MD2` |

The two algorithm patterns are validated before they are stored: they must compile as a PCRE and must
not contain a double quote or a backslash, because they are written into a `{$CERT.*}` macro that is
substituted into a quoted trigger-function argument.

Changing a setting **never modifies an existing website**; it only affects entries created afterwards.
The one exception is a website that is opened with **Edit** and saved: it then picks up any macro or
trigger the module did not create yet, using the current org-wide defaults.
`CertEdit` reads the settings for a new entry and falls back to the `CertHelper` constants whenever
nothing is stored or a stored value is unusable, so a broken configuration can never make the form
unusable.

### How the settings are stored (verified)

They are stored in the **`config` section of the module**, which Zabbix keeps in the `module` table as
a JSON document. This was verified against branch `release/7.4`:

* `ui/include/classes/core/CModule.php` provides `getConfig()`, `getOption()` and `setConfig()`.
  `setConfig()` persists the value with
  `API::Module()->update([['moduleid' => …, 'config' => …]])` — module configuration **can** be
  written back at runtime.
* `ui/include/classes/api/services/CModule.php` declares `config` as an `API_OBJECT` with
  `API_ALLOW_UNEXPECTED` in the rules of both `module.create` and `module.update`.
* The same file restricts `module.get`, `module.update` and `module.delete` to
  `USER_TYPE_SUPER_ADMIN`. **Therefore the settings page is Super admin only.** Global user macros
  (`usermacro.createGlobal` / `updateGlobal`) were the documented fallback, but they carry the same
  Super-admin restriction while additionally polluting the global macro namespace, so the module
  config was chosen.
* `ui/include/classes/core/ZBase.php` (`initModuleManager()`) reads the stored configuration straight
  from the database and passes it to `CModuleManager::addModule()` as an override. **Reading** the
  settings through `APP::ModuleManager()->getModule('dks_certmonitor')->getConfig()` therefore works
  for every user type and never touches the Super-admin-only `module.get` method — which is exactly
  what `CertConfig::get()` does.

Reference: <https://www.zabbix.com/documentation/7.4/en/manual/api/reference/module/update>

---

## Pre-add existence check ("Test connection")

The add/edit form has a **Test connection** button. It posts the hostname, port and address override
to the AJAX action `certmonitor.check`, which:

1. **Resolves the name** (or uses the address override) — IPv4 via `gethostbynamel()`, IPv6 via
   `dns_get_record(..., DNS_AAAA)`. A name that resolves to neither is reported as a DNS failure.
2. **Opens a TCP + TLS connection** with `stream_socket_client('ssl://…')` and an SSL context with
   `capture_peer_cert => true`, `verify_peer => false`, `verify_peer_name => false`,
   `SNI_enabled => true`, `peer_name => <hostname>` and a **5 second** timeout — so even a broken or
   untrusted certificate can be inspected.
3. **Parses the peer certificate** with `openssl_x509_parse()` and reports subject CN, issuer CN,
   valid from, valid until, days remaining, subject alternative names and whether subject and issuer
   are identical (self-signed).
4. **Repeats the handshake with `verify_peer => true`** to find out whether a client that trusts the
   frontend server's CA bundle would accept the certificate, and reports the verification error if
   not.

The result is rendered inline under the button (colour coded: green / amber / red, with `aria-live`
so screen readers announce it). Only PHP core functions are used — no Composer dependency and no
shell command.

### The caveat (important)

**A failure is a warning, never a block.** The **Add** / **Update** button is always enabled, and the
form text says so explicitly:

> This is only a hint. The check is performed by the Zabbix **frontend** server, while the configured
> monitoring is performed by a **Zabbix agent** that may sit in a different network segment, resolve
> different DNS names and trust a different set of certificate authorities. A failure here does not
> prevent adding the website.

Typical legitimate reasons for a failed probe on a perfectly monitorable website: the frontend is in
a DMZ and the agent is not, split-horizon DNS, an internal CA that the agent trusts but the web
server's PHP does not, or egress filtering on the frontend host. Conversely, a *successful* probe
does not prove the agent can reach the website either — always confirm with

```
zabbix_get -s <agent address> -p 10050 -k 'web.certificate.get[www.example.com,443]'
```

---

## "Check now"

Available per row and as a bulk action. It queues an immediate poll of the **master item** of each
selected host, so a fresh certificate value can be pulled without waiting for the update interval.

Verified against branch `release/7.4`:

* `ui/app/controllers/CControllerItemExecuteNow.php` builds
  `['type' => ZBX_TM_TASK_CHECK_NOW, 'request' => ['itemid' => <itemid>]]` and passes it to
  `API::Task()->create()`. `ZBX_TM_TASK_CHECK_NOW` is `6` (`ui/include/defines.inc.php`).
* `checkNowAllowedTypes()` in `ui/include/items.inc.php` lists `ITEM_TYPE_ZABBIX`, so the master item
  qualifies. Dependent items are allowed too, but the built-in controller resolves them to their
  master first — which is why this module targets the master item directly.
* Minimum user type `USER_TYPE_ZABBIX_USER`; write permission on the item is required unless the role
  grants `CRoleHelper::ACTIONS_INVOKE_EXECUTE_NOW`. Both rules are applied.
* The item and the host must both be active; hosts that are not monitored are skipped with a message
  rather than making the whole request fail.

References:
<https://www.zabbix.com/documentation/7.4/en/manual/api/reference/task/create>,
<https://www.zabbix.com/documentation/7.4/en/manual/config/items/check_now>

---

## Alternative: import the template instead

If you would rather not install a frontend module:

1. Go to **Data collection -> Templates -> Import**.
2. Select `template_certificate_monitoring.yaml`, keep the default options and click **Import**.
3. Create a host with an **Agent** interface pointing at your Zabbix agent 2 machine, link the
   template *Website certificate monitoring*, and set `{$CERT.WEBSITE.HOSTNAME}` (and optionally
   `{$CERT.WEBSITE.PORT}` / `{$CERT.WEBSITE.IP}`) on the host.

The file uses `zabbix_export: version: '7.0'`. Zabbix imports export files of its own and of older
versions, so this file can be imported into 7.0, 7.2 and 7.4. If you re-export it from 7.4, Zabbix
will write `version: '7.4'`.

The template covers one website per host. If you need many websites per host, use the official
Zabbix template *Website certificate by Zabbix agent 2*, which adds an LLD layer on top of the same
item key.

---

## Troubleshooting

**"Wrong Widget.php class name for module located at widgets/certmonitor_widget."**

The widget was copied into `ui/widgets/` instead of `ui/modules/`. Move it:

```bash
rm -rf /usr/share/zabbix/ui/widgets/certmonitor_widget
cp -r  certmonitor_widget /usr/share/zabbix/ui/modules/
```

then **Scan directory** again and enable the module.

Why this happens: `CModuleManager::loadManifest()` derives the PHP namespace prefix from the *first
segment of the directory path*, not from the module type:

```php
$manifest['namespace'] = ucfirst($relative_path_parts[0]).'\\'.$manifest['namespace'];
```

So `modules/certmonitor_widget` expects the class `Modules\CertMonitorWidget\Widget`, while
`widgets/certmonitor_widget` expects `Widgets\CertMonitorWidget\Widget`. The shipped files declare
`Modules\…`, hence the mismatch. `initModules()` then reports exactly the message above.

Placing the widget in `ui/modules/` is also the right choice regardless: `ui/widgets/` holds the
widgets shipped with Zabbix and is replaced on upgrade. The `"type": "widget"` key in the manifest —
not the directory — is what makes it a dashboard widget, so it appears in *Add widget* normally.

If you would rather keep it under `ui/widgets/`, change the namespace prefix from `Modules\` to
`Widgets\` in all three PHP files (`Widget.php`, `actions/WidgetView.php`, `includes/WidgetForm.php`).
Nothing else needs to change.

**A wildcard certificate is reported as "invalid"**

The validation result is **not** produced by this module. It is `$.result.value` from the Zabbix
agent 2 item, and the agent uses Go's `crypto/x509` verification — which does understand wildcards.
So an "invalid" on a wildcard certificate is almost always one of these:

| Cause | Example |
|---|---|
| The monitored name is the **apex** | certificate `*.example.com`, monitored `example.com` — a wildcard covers exactly one label and never the bare domain |
| The name is **too deep** | certificate `*.example.com`, monitored `a.b.example.com` — one wildcard, one label |
| Monitored by **IP address** | a wildcard can never match an IP; the certificate needs an IP SAN |
| The **CA is not trusted** by the agent host | internal CA missing from the agent machine's trust store — nothing to do with the wildcard |
| The hostname macro contains extra parts | `{$CERT.WEBSITE.HOSTNAME}` must be a bare host name, not a URL with scheme or path |

To tell these apart, the module shows two things:

* the **agent's own validation message** (`$.result.message`) as a hover hint on the `invalid` marker
  in the list — that message names the actual reason,
* a **Name match** row at the top of the certificate section on the detail page. It re-checks the
  monitored host name against the certificate's SANs (falling back to the subject CN) using the
  RFC 6125 rules, wildcards included, and reports which name covers the host — or, if none does, the
  full list of names that were checked.

If the name matches but the agent still says `invalid`, the problem is the trust chain, not the name.
Either add the CA to the agent host's trust store, or tick **Ignore certificate validation errors**
on that website.

**The module does not appear in Administration -> General -> Modules**

* Click **Scan directory** again.
* Check the path: `manifest.json` must be at `ui/modules/certmonitor/manifest.json`.
* Check file ownership/permissions for the web server user.
* Check the PHP error log; a syntax error in `manifest.json` (trailing comma, wrong quotes) makes
  the module invisible.

**The module is enabled but there is no "Certificates" menu entry**

* Your user role may hide the *Monitoring* section; the entry lives inside it.
* Clear the browser cache and reload; the menu is built per request but the browser may cache CSS.
* Check that `Module.php` was copied (the manifest alone registers the module but adds no menu).

**"Access denied" when opening the page or submitting the form**

* Viewing needs the UI element *Monitoring -> Hosts*; creating needs user type *Admin* and the UI
  element *Data collection -> Hosts*.
* A stale browser tab can submit an outdated CSRF token. Reload the form page and submit again.

**HTTP 500 when opening Monitoring -> Certificates**

The list page now has an error boundary: `views/certmonitor.list.php` only wraps
`views/certmonitor.list.body.php` in a `try/catch (Throwable)`, and `CertList::doAction()` wraps its
data collection the same way. A fatal no longer produces a bare 500 — the page renders the exception
class, message, file and line instead. Send that text along if the page still fails.

Two causes that were fixed in this version:

* `CPagerHelper::paginate()` was called with the page number as a string. In a file with
  `declare(strict_types = 1)` that is a `TypeError`, i.e. a fatal, as soon as you leave page 1.
* All view files declared `strict_types = 1`, while Zabbix core views deliberately do not. Any core
  helper that declares `int` and receives a request string then fatals instead of converting. The
  views now match core; controllers and helper classes keep strict types.

**"Invalid parameter "/1": the parameter "interfaceid" is missing"**

Fixed in this version. `web.certificate.get` is a *Zabbix agent* (passive) item, and the API requires
an `interfaceid` for those. `host.create` only returns the host ID, so the module now reads the
interface back with `hostinterface.get` and passes it to `item.create`. If you still see this, an
older copy of `CertCreate.php` is deployed — recopy the folder and click **Scan directory**.

**The form says "No writable host groups available"**

* The user has no host group with read-write permission. Grant it in **Users -> User groups**.

**The master item is "Not supported"**

Typical messages and causes:

| Message contains | Cause |
|---|---|
| `Unsupported item key` | the host is polled by the classic agent (`zabbix_agentd`), not agent 2 |
| `connection refused` / `no route to host` | the Agent interface points to the wrong machine, or port 10050 is blocked |
| `dial tcp ...: i/o timeout` | the agent machine cannot reach the website on the TLS port |
| `cannot find the certificate` / handshake errors | the destination does not speak TLS on that port |

Test directly from the Zabbix server:

```
zabbix_get -s <agent address> -p 10050 -k 'web.certificate.get[www.example.com,443]'
```

Remember that an **invalid certificate is not an error**: the item stays supported and
`$.result.value` becomes `invalid`. Only handshake/connection failures make the item unsupported.

**Items collect no data**

* The master item has *Discard unchanged with heartbeat 6h*, so a fresh host shows values only
  after the first successful poll. Use **Execute now** on the master item.
* Dependent items only update when the master item produces a value.

**The list shows "No data"**

* No history yet (see above), or history was removed by housekeeping. The list reads the last values
  of the `cert.*` dependent items from history.
* Use **Check now** on the row to poll immediately instead of waiting for the interval.

**The Certificate section says the raw document is not stored**

* The host was created by module version 1.0, whose master item used `history = 0`. Open the website
  with **Edit** and press **Update**: the master item is switched to `7d` and the missing dependent
  items are created. The next poll then fills the section from the raw JSON.

**"Test connection" fails but the website is fine**

* Expected in split networks. The probe runs on the **frontend** server, the monitoring runs on the
  **agent**. See *Pre-add existence check*. Press **Add** anyway.
* `The PHP OpenSSL extension is not available…` — the frontend PHP has no `openssl` extension. The
  module works without it; only the probe is unavailable.

**The Settings button is missing**

* The settings page is **Super admin only**, because Zabbix restricts `module.update` to Super
  admins. Admins can still add and edit websites; they just get the built-in defaults in the form.

**Sorting or the filter is "sticky" between sessions**

* Both are stored per user in the frontend profile under `web.certmonitor.list.*`. Press the
  **Reset** button of the filter to clear it.

**Deleting a website does nothing**

* Only hosts tagged `certmonitor: website` that the user can edit are deletable through this page.
  Hosts created manually must be deleted in **Data collection -> Hosts**.

---

## Notes, and what could not be verified

* **`php -l` was not run.** No PHP binary was available in the environment used to build this
  module, and none could be installed. The PHP files were checked manually and with a bracket/string
  balance checker, but they have not been run through the PHP parser. Run
  `find certmonitor -name '*.php' -exec php -l {} \;` before deploying.
* The module has **not been executed against a live Zabbix 7.2/7.4 instance**. All framework
  classes, methods, constants and API field names used here were taken from the Zabbix source of
  branch `release/7.4` and from the official documentation (links below), but end-to-end behaviour
  is unverified.
* `declare(strict_types = 1)` is used in this module. Zabbix core itself uses
  `declare(strict_types = 0)`. If you hit a `TypeError` originating from a Zabbix core call, switch
  the affected file to `strict_types = 0`.
* Zabbix **7.2 is an unsupported (end-of-life) release** according to the documentation version
  selector; only 7.0 LTS, 7.4 and 6.0 LTS are listed as supported. The module targets the shared
  7.x module API (`manifest_version` 2.0), which is identical in 7.0, 7.2 and 7.4.
* The Zabbix source tree on GitHub has no `release/7.2` branch (only `release/6.0`, `release/7.0`,
  `release/7.4` and `master`), so all source cross-checks were made against `release/7.4` and
  `release/7.0`.

### Verified for version 1.2

| Question | Answer | Source |
|---|---|---|
| Can one manifest be both a frontend module and a widget? | **No.** `type` accepts only `module` or `widget`, and it exclusively selects `Module.php`/`CModule` vs `Widget.php`/`CWidget` | `ui/include/classes/core/CModuleManager.php`, `ui/include/classes/core/CModule.php` (branch `release/7.4`) |
| Which namespace does a widget in `ui/modules/` get? | `Modules\<namespace>` — the prefix comes from the directory, not from the type | `CModuleManager::loadManifest()`: `$manifest['namespace'] = ucfirst($relative_path_parts[0]).'\\'.$manifest['namespace'];` |
| Is a widget JS class mandatory? | No. `CWidget::DEFAULT_JS_CLASS = 'CWidget'`, and `widget.<id>.view` / `.edit` are auto-registered with `layout.widget` / `layout.json` | `ui/include/classes/core/CWidget.php` |
| Documented widget manifest keys | `type`, `widget` (`name`, `form_class`, `js_class`, `in`, `out`, `size`, `refresh_rate`), plus the normal module keys | <https://www.zabbix.com/documentation/7.4/en/devel/modules/file_structure/manifest> and the defaults injected by `CModuleManager` |
| Does `change()` work on string/text items in 7.x? | **Yes** — *"Supported value types: Float, Integer, String, Text, Log"*, *"For strings returns: 0 - values are equal; 1 - values differ"* | <https://www.zabbix.com/documentation/7.4/en/manual/appendix/functions/history> |
| Does `prev()` still exist? | **No** — it is not in the 7.x function reference; `last(…)<>prev(…)` is invalid | same page |
| May the period argument of `find()` be empty? | Yes — *"defaults to the latest value if not specified"*; documented example `find(/host/agent.version,,"like","beta")=1` | same page |
| Does `web.certificate.get` report a key length? | **No.** The plugin emits only the 15 fields listed above; `public_key_algorithm` is Go's `PublicKeyAlgorithm.String()` → `RSA`/`DSA`/`ECDSA`/`Ed25519`/`Unknown` | `src/go/plugins/web/certificate/certificate.go` (branch `release/7.4`) |
| Field/view class names used by the widget | `CWidgetFieldIntegerBox`, `CWidgetFieldMultiSelectGroup` and their `…View` counterparts, `CWidgetView`, `CWidgetFormView`, `CControllerDashboardWidgetView` | `ui/include/classes/widgets/fields/`, `ui/include/classes/html/widgets/`, and the shipped `ui/widgets/toptriggers/` widget |

### Verified for version 1.1

| Question | Answer | Source |
|---|---|---|
| Can module config be persisted via `manifest.json` `config` + `getConfig()`? | Yes | `ui/include/classes/core/CModule.php` (`getConfig`, `getOption`), `ui/include/classes/core/CModuleManager.php` (`addModule($…, $config)`) |
| Can it be **written** at runtime? | Yes, via `CModule::setConfig()`, which calls `API::Module()->update([['moduleid'…, 'config'…]])` | `ui/include/classes/core/CModule.php` |
| Who may write it? | `USER_TYPE_SUPER_ADMIN` only | `ui/include/classes/api/services/CModule.php` (`ACCESS_RULES`, and the explicit type check in `get`/`update`/`delete`) |
| Who may read it? | Everyone — `ZBase::initModuleManager()` reads it from the DB and injects it into the manifest | `ui/include/classes/core/ZBase.php` |
| `task.create` for "Execute now" | `['type' => ZBX_TM_TASK_CHECK_NOW (6), 'request' => ['itemid' => …]]`, min. user type *User*, plus item write access unless `actions.invoke_execute_now` | `ui/app/controllers/CControllerItemExecuteNow.php`, `ui/include/defines.inc.php:1226`, `ui/include/items.inc.php` (`checkNowAllowedTypes`) |
| Sortable headers | `make_sorting_header($label, $field, $sort, $sortorder, $url)` | `ui/app/views/mediatype.list.php` |
| Filter widget | `(new CFilter())->setResetUrl()->setProfile()->setActiveTab()->addFilterTab()->addVar()` | `ui/app/views/mediatype.list.php`, `ui/include/classes/html/CFilter.php` |
| Paging | `CPagerHelper::paginate($page, $rows, $sort_order, CUrl $url)` + `CTableInfo::setPageNavigation()`; `CPagerHelper::resetPage()` on filter change | `ui/include/classes/helpers/CPagerHelper.php`, `ui/include/classes/html/CTableInfo.php` |
| CSV response | view echoes `zbx_toCSV($rows)`, action uses `layout.csv`, controller calls `CControllerResponseData::setFileName()` | `ui/app/views/layout.csv.php`, `ui/app/views/reports.actionlog.list.csv.php`, `ui/app/controllers/CControllerActionLogList.php` |
| JSON/AJAX response | `layout.json` with **no** view; controller returns `['main_block' => json_encode(…)]`; CSRF token passed as a URL argument, body is JSON | `ui/app/views/layout.json.php`, `ui/include/classes/mvc/CRouter.php`, `ui/app/views/js/mediatype.list.js.php` |
| Module CSRF token scope | For `Modules\…` controllers the token is checked against the **full action name** | `ui/include/classes/mvc/CController.php::checkCsrfToken()` |
| Module action defaults | `layout` defaults to `layout.htmlpage`, `view` to `null`; `assets.js` is supported next to `assets.css` | `ui/include/classes/core/CModuleManager.php` (`getActions()`, manifest defaults) |
| `hostgroup.get` option to list only groups that contain hosts | `with_hosts` | `ui/include/classes/api/services/CHostGroup.php` |
| `trigger.get` sub-select for the items of a trigger | `selectItems` | `ui/include/classes/api/services/CTrigger.php` |
| `history.php` link to raw values | `action=HISTORY_VALUES` (`'showvalues'`), `itemids[]` | `ui/include/defines.inc.php:1899` |

### What could **not** be verified for version 1.2

* **`php -l` could not be run** for these new files either — see the 1.1 note below; the same
  bracket/quote/heredoc balance checker was used and all `.php` files and both `manifest.json` files
  passed. Run `php -l` on every file before deploying.
* **Nothing was executed against a live Zabbix instance.** Specifically unobserved:
  * that `trigger.create` accepts `change(/host/cert.issuer)=1` for an item of value type **Text**.
    The documentation lists Text as a supported value type for `change()`, but the frontend's
    expression validator (`CExpressionValidator`) was not run against it. If it is rejected, switch
    the expression to `last(/host/cert.issuer,#1)<>last(/host/cert.issuer,#2)` — for the issuer and
    fingerprint items the numeric-cast caveat is unlikely to matter in practice;
  * that a `{$CERT.*}` macro inside the **pattern argument** of `find(...,"iregexp","{$CERT.SIG.ALGO.WEAK}")`
    is expanded by the server before the regular expression is compiled. User macros in trigger
    function parameters are documented as supported, but this specific combination was not tested;
  * that creating nine triggers per host (five of them possibly disabled) in one `trigger.create`
    call stays within the API limits on all supported versions;
  * that the dashboard renders the widget correctly, and that `Manager::History()->getLastValues()`
    is callable from a widget view controller as it is from a normal one.
* **The widget duplicates four constants** (`certmonitor`, `website`, `cert.not_after`,
  `cert.validation`) instead of importing them from `CertHelper`, because the two modules have
  separate namespaces and the widget must work when installed alone. If the tag or the item keys are
  ever changed in `CertHelper`, `certmonitor_widget/actions/WidgetView.php` must be changed too.
* **Trigger severities are static.** Zabbix has no way to drive a trigger's severity from a user
  macro, so the two configurable severities are applied at creation time only. Changing them in the
  settings page does not rewrite triggers that already exist.
* **Bulk import performance.** The 500-line limit and the 1 MiB upload limit were chosen so the
  request stays inside a typical `max_execution_time`; the actual runtime per line (one host, fifteen
  items, nine triggers) was not measured on real hardware. If PHP times out mid-import, the entries
  created up to that point remain — re-running the same list will show them as *already monitored*.
* **The CSV upload reads `$_FILES` directly**, because the Zabbix declarative input validator does not
  cover file uploads. `is_uploaded_file()`, an explicit size check and a UTF-8 check are applied, but
  the upload path is not covered by `checkInput()` the way every other field is.
* **The bulk import preview is not CSRF-protected**, matching the existing `certmonitor.edit` form:
  it is a read-only page that creates nothing. Only `certmonitor.import.create` validates a token.

### What could **not** be verified for version 1.1

* **`php -l` still could not be run.** No PHP binary was available and it could not be installed
  (no root in the build environment). All `.php` files were checked with a PHP-aware
  bracket/quote/heredoc balance checker (comments and string literals skipped) and `manifest.json`
  was parsed as JSON; `assets/js/certmonitor.js` passed `node --check`. Run
  `find certmonitor -name '*.php' -exec php -l {} \;` before deploying.
* **Nothing was executed against a live Zabbix instance.** In particular these behaviours are
  reasoned from the source but not observed:
  * that `API::Module()->update()` accepts a `config` update for a module that is currently loaded
    without requiring a directory rescan afterwards;
  * that `host.update` with an explicit `interfaceid` never re-creates the interface and therefore
    never invalidates the master item's `interfaceid` reference;
  * that the Zabbix server accepts the `ZBX_TM_TASK_CHECK_NOW` task for a *Zabbix agent* item whose
    key contains user macros.
* **The TLS probe is best-effort by design.** `stream_socket_client()` reports OpenSSL detail through
  a PHP warning; the module recovers it with `error_get_last()`, which is a heuristic and may return
  an empty or unrelated detail on some PHP builds. The probe also cannot see SNI-less virtual hosts,
  client-certificate-gated endpoints or anything behind a proxy that the frontend cannot traverse.
* **Sorting and filtering on collected values happen in PHP**, not in the API, because "days left",
  the validation result and "last checked" live in the history tables and `host.get()` cannot sort by
  them. The host set is therefore capped by `CSettingsHelper::SEARCH_LIMIT` before filtering. On
  installations with more managed websites than the search limit, narrow the filter by host group
  first. This is a deliberate trade-off, not a bug.
* **The trigger rename on edit is textual.** It replaces `[<old hostname>:<old port>]` with the new
  target in the trigger name and event name. A trigger whose name no longer contains that bracketed
  form (because it was renamed by hand into a different shape) is left untouched — safe, but it then
  keeps showing the old target.
* `_n('%1$s day', '%1$s days', $days)` and the other plural strings rely on Zabbix's translation
  helpers; only the English source strings were exercised.

---

## Documentation sources used

* Zabbix agent 2 item keys, `web.certificate.get` parameters and JSON response fields:
  <https://www.zabbix.com/documentation/7.4/en/manual/config/items/itemtypes/zabbix_agent/zabbix_agent2>
* Quick reference guide "Monitor website certificates with Zabbix agent 2 (passive)" (agent
  interface requirement, macros, test command):
  <https://www.zabbix.com/documentation/7.4/en/manual/guides/monitor_certificate>
* Frontend module file structure:
  <https://www.zabbix.com/documentation/7.4/en/devel/modules/file_structure>
* `manifest.json` schema (`manifest_version`, `id`, `name`, `namespace`, `version`, `actions`,
  `assets`, ...):
  <https://www.zabbix.com/documentation/7.4/en/devel/modules/file_structure/manifest>
* Module tutorial (`Module.php`, `CModule`, `APP::Component()->get('menu.main')`, `CMenuItem`,
  `findOrAdd`, `insertAfter`, `CController` with `init`/`checkInput`/`checkPermissions`/`doAction`,
  `CControllerResponseData`):
  <https://www.zabbix.com/documentation/7.4/en/devel/modules/tutorials/module>
* Official template *Website certificate by Zabbix agent 2* (JSONPath preprocessing and trigger
  expressions):
  <https://github.com/zabbix/zabbix/blob/release/7.4/templates/app/certificate_agent2/template_app_certificate_agent2.yaml>
* Zabbix source used for class/constant/API cross-checks (branch `release/7.4`):
  <https://github.com/zabbix/zabbix/tree/release/7.4/ui/include/classes>
* `module.update` (the `config` property):
  <https://www.zabbix.com/documentation/7.4/en/manual/api/reference/module/update>
* `task.create` and "Execute now":
  <https://www.zabbix.com/documentation/7.4/en/manual/api/reference/task/create>,
  <https://www.zabbix.com/documentation/7.4/en/manual/config/items/check_now>
* `host.update` (collections such as `macros`, `tags`, `groups` and `interfaces` are replaced
  wholesale by what is passed):
  <https://www.zabbix.com/documentation/7.4/en/manual/api/reference/host/update>
* Frontend modules overview (`config` section of the manifest):
  <https://www.zabbix.com/documentation/7.4/en/manual/modules>
* Widget module development (`"type": "widget"`, `Widget.php`, `WidgetForm.php`, `WidgetView.php`,
  `widget.view.php`, `widget.edit.php`, `js_class`):
  <https://www.zabbix.com/documentation/7.4/en/devel/modules/widgets>,
  <https://www.zabbix.com/documentation/7.4/en/devel/modules>
* Reference widget used as the model for the PHP-rendered widget with a host-group filter:
  <https://github.com/zabbix/zabbix/tree/release/7.4/ui/widgets/toptriggers>
* History functions `change()`, `find()`, `last()` — supported value types, operators and the empty
  period argument:
  <https://www.zabbix.com/documentation/7.4/en/manual/appendix/functions/history>
* Trigger expression syntax, in particular string comparison with `=` / `<>` and the numeric cast:
  <https://www.zabbix.com/documentation/7.4/en/manual/config/triggers/expression>
* Trigger object properties (`description`, `expression`, `priority`, `status`, `event_name`, `tags`):
  <https://www.zabbix.com/documentation/7.4/en/manual/api/reference/trigger/object>
* Zabbix agent 2 `web.certificate.get` plugin source, used to confirm the exact JSON fields and that
  no key length is reported:
  <https://github.com/zabbix/zabbix/blob/release/7.4/src/go/plugins/web/certificate/certificate.go>
