=== SenroGate ===
Contributors: stephen1204paul
Tags: security, ai, mcp, audit-log, woocommerce
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Governs other plugins' agent tool calls, with human approval and a tamper-evident audit log.

== Description ==

SenroGate governs other plugins' agent tool calls, not just its own. Whatever connects an AI
agent to your site (mcp-adapter's default server, WooCommerce's MCP server, a plugin's own MCP
server built on [mcp-adapter](https://github.com/WordPress/mcp-adapter), or SenroFlux), the
calls end up as WordPress Abilities, and SenroGate sits on that path. Every governed call is
classified by tier, checked against a capability pack bound to the calling identity, and written
to a tamper-evident, hash-chained audit log, before it runs. Writes record what they changed, so
an administrator can undo them later.

One honest limit: SenroGate sees ability calls. A plugin that gives agents tools without
registering them as abilities is outside its reach.

The plugin is WordPress-general. It ships a `core/*` module that runs on every site, plus
WooCommerce, FluentCart and SureCart modules that wire in automatically when those plugins are
active. None of them trusts a tool's own "read-only" or "safe" label; classification comes from
the plugin's own policy map.

**Capability packs and tiers**

Every governed ability maps to a tier: reversible (read), side-effecting (write), or
irreversible (delete, refund, cancel). A capability pack is a named, scoped view of the verb
catalog for one identity — a WordPress application password, a user or role, or a WooCommerce
REST API key — with an allow list, a hard-denied tier class, a per-tier approval requirement, and
optional rate and spend limits. A credential with no explicit binding falls back to a safe
default pack rather than inheriting full access.

**Human approval**

When a pack requires approval for a tier (the starter packs do for irreversible calls), a call
at that tier doesn't execute. It is queued under **Tools → Pending Agent Actions** for a human
to approve or reject. When an
Approval is requested, SenroGate captures a fingerprint of the target's current state; when
the approval is claimed, it re-checks that fingerprint. A mismatch means the target changed
between request and claim, so the old approval is marked stale and a fresh request is filed. This
narrows the gap between a human's approval and the write — it does not close it, because the
short window between claiming an approval and executing the call still exists.

**Self-service approval status**

An agent can call `agent-safety/check-approval` with an `approval_id` to poll its own request
without a human in the loop. It reports a reduced, public status (pending, approved, rejected,
expired, used, or superseded) and a plain-English `next_action`, and never returns the approver's
identity, the original arguments, or any token.

**Hash-chained audit log**

Every gate decision and executed action is appended to an append-only, hash-chained log under
**Tools → Agent Audit Log**, with a tamper-evidence check that re-verifies the chain on load.

**Core coverage**

Governs the three abilities WordPress core registers today. Any other `core/*` ability is denied
until SenroGate maps it, so future core write abilities fail closed rather than run
ungoverned.

**WooCommerce coverage**

When WooCommerce is active, SenroGate governs exactly these 16 abilities:

* `woocommerce/products-list`
* `woocommerce/products-get`
* `woocommerce/products-create`
* `woocommerce/products-update`
* `woocommerce/products-delete`
* `woocommerce/products-query`
* `woocommerce/product-create`
* `woocommerce/product-update`
* `woocommerce/product-delete`
* `woocommerce/orders-list`
* `woocommerce/orders-get`
* `woocommerce/orders-create`
* `woocommerce/orders-update`
* `woocommerce/orders-query`
* `woocommerce/order-add-note`
* `woocommerce/order-update-status`

Everything else under `woocommerce/` is refused as an unknown verb. Deleting a product with
`force: true` is classed irreversible; a plain delete (trash) is side-effecting.

**Which MCP endpoint to use**

WooCommerce's own MCP endpoint (`/wp-json/woocommerce/mcp`) is deprecated by WooCommerce itself,
which points clients at the shared `mcp-adapter` server instead. SenroGate governs both the
same way, through the ability permission check, but the two endpoints don't expose the same
information to an agent:

* On the shared `mcp-adapter` server, an agent that hits `approval_required` gets the full error
  data, including `approval_id`, and can call `agent-safety/check-approval` to poll it.
* On WooCommerce's own endpoint, the bundled `mcp-adapter` 0.3.0 only forwards the error message
  text to the client, not the structured error data. An agent sees the message "needs human
  approval" but not the `approval_id`, and `agent-safety/check-approval` isn't reachable on that
  transport at all — the approval still exists and is visible on the Pending Actions page, but
  the agent can't self-serve it.

We recommend running agents against the shared mcp-adapter server. WooCommerce's own endpoint is
supported for as long as WooCommerce ships it, with the limitation above.

**FluentCart and SureCart coverage**

When FluentCart or SureCart is active, SenroGate governs the abilities each one registers, with
its own tier for every one. Refunds are irreversible and can't be pre-approved with a grant, so
each one waits for a person. Some writes move up a tier based on what they do: a price change
that goes live for customers, a coupon switched on, a customer's email changed. Anything under
those namespaces that SenroGate doesn't recognise is refused.

**Change history and undo**

Every governed write records the fields it changed, before and after, linked to its audit row.
**Tools → Agent Changes** lists them. An administrator can restore one change, or everything a
single agent session changed at once (a whole SenroFlux run, for example), after a preview of
what will be written back. Posts, pages, post meta, terms, options, WooCommerce products,
prices and coupons, and FluentCart and SureCart catalogue objects can be restored.

Some changes can be recorded but not undone, and the screen says why: a refund, a permanent
delete, or a customer record captured under a pack that redacts personal data. If someone has
edited the object since the agent did, undo leaves it alone and shows the recorded, current and
proposed values side by side.

**Abilities from other plugins**

Plugins keep registering abilities SenroGate has never heard of. Under **Tools → Agent
Abilities**, an administrator gives each one a tier, with no code. An ability nobody has reviewed
stays refused, and one whose input schema changes after review is refused again until someone
looks at it.

**IP allowlist**

Optionally limit application passwords and WooCommerce API keys to a list of addresses or CIDR
ranges, under **Tools → Agent Capability Packs**. Logged-in browser sessions are never IP-checked,
so a typo can't lock an administrator out. Behind a proxy or CDN, name it as a trusted proxy and
SenroGate reads the forwarded address; otherwise it uses the connecting address.

**Environment awareness**

SenroGate notices when a site's address changes (a migration, a staging clone) and treats it
as a new environment: shadow-mode windows, active grants, and approved-but-unclaimed approvals
are all voided rather than carried over silently, and an admin notice stays up until someone
rebinds the site from the settings page. Production sites (`wp_get_environment_type() ===
'production'`, WordPress's own default) never default to shadow mode and cap any shadow window at
24 hours.

**Multisite**

WordPress multisite is not supported. Activation is refused on a multisite install, network-wide
or per site.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/senrogate`, or install it through the Plugins
   screen.
2. Activate **SenroGate**.
3. Visit **Tools → Agent Capability Packs** to bind identities (application passwords, users or
   roles, WooCommerce REST API keys) to a pack. Unbound credentials get a safe default pack.
4. Review pending actions under **Tools → Pending Agent Actions** and the log under **Tools →
   Agent Audit Log**.

WooCommerce support activates automatically when WooCommerce is active; there is nothing else to
configure for it to be safe by default.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. The plugin is WordPress-general and governs the core Abilities API on any site. WooCommerce
support is a self-contained module that loads only when WooCommerce is active.

= Does it work on multisite? =

No. Activation is refused on a multisite install, both network-wide and per site.

= What about WooCommerce's own MCP endpoint? =

It's governed the same way as the shared mcp-adapter server, but WooCommerce's bundled
mcp-adapter version doesn't forward the structured `approval_required` error data to the client,
so an agent on that endpoint can see the approval message but not the `approval_id`, and can't
reach `agent-safety/check-approval`. See the Description for details. We recommend the shared
mcp-adapter server; WooCommerce's own endpoint is supported while WooCommerce continues to ship
it.

= What happens to my data on uninstall? =

Nothing, by default. Deleting the plugin from the Plugins screen leaves the audit log, approval
tables, change history and plugin options in place, because the audit log is a tamper-evident
security record. To have uninstall remove everything (every table, option and scheduled cron event),
define `AGSAFE_REMOVE_DATA` as `true` (for example in `wp-config.php`) before deleting the
plugin.

= How are AI agents identified? =

By whichever credential authenticated the request: a WordPress application password, a logged-in
user or their role, or — when WooCommerce is active — the WooCommerce REST API key used to
authenticate the request. Each is bound to a capability pack independently under **Tools → Agent
Capability Packs**.

== External services ==

This plugin can send data outside your site in two ways.

**Email.** When a new agent action needs approval, SenroGate sends an email through
`wp_mail()` to the site's admin email address, or to a recipient set on the Pending Agent
Actions screen, linking to the login-protected review screen. It goes through your site's own
mail delivery.

**Webhook (opt-in).** An administrator can set a webhook URL on the Pending Agent Actions
screen. When
set, every new pending approval sends an HTTP POST to that URL with a JSON body containing the
event name, the approval id, the verb (ability) that was called, and a link to the review screen.
No call arguments are sent. The webhook is off until an administrator enters a URL, and the
destination is the site operator's choice. Nothing is sent to the plugin's author.

**SureCart (only when SureCart is active).** SureCart keeps its products, prices and coupons on
its own servers. To record what an agent changed, SenroGate reads the object's current values
through the SureCart plugin before and after a governed write, and an undo writes the recorded
values back the same way. These requests go to SureCart's API using the SureCart plugin's own
connection and the store's existing SureCart account; SenroGate adds no data beyond the object id
and the fields being restored. See SureCart's terms and privacy policy:
https://surecart.com/terms-and-conditions/ and https://surecart.com/privacy-policy/

== Privacy ==

SenroGate keeps a hash-chained audit log of gate decisions and executed agent actions,
including the identity that made the call. A personal-data export for a WordPress user includes
their matching audit rows. An erasure request does not delete them: they are reported as
retained, with a note that they're kept as a tamper-evident security record, because rewriting a
row would break the hash chain that proves the log hasn't been tampered with. Matching is done by
resolving the requester's email to a WordPress user and matching rows on that user only; recorded
tool inputs are never searched for personal data, but they may contain it, since they can include
data handled by other plugins (customer records, order details) that the calling agent's tool
touched.

The change history stores the before and after values of each governed write, which can include
personal data when an agent edits a customer or an order. Rows older than 30 days are deleted
daily (the `agsafe_changes_retention_days` option changes the period). A personal-data export
includes a user's change rows, and an erasure request deletes them; unlike audit rows they aren't
part of the hash chain.

== Upgrade Notice ==

= 0.5.0 =
Adds change history and undo, ability review for other plugins' abilities, an IP allowlist, and FluentCart and SureCart modules. Adds a change-history table; back up your database first.

= 0.4.4 =
Security fix: WooCommerce order statuses written with the wc- prefix now get the same approval tier as unprefixed ones. Update right away.

= 0.4.3 =
Renamed to SenroGate (new folder and main file). If you installed an earlier build from GitHub, deactivate and delete the old plugin folder, then install this one. Tables and settings carry over.

= 0.4.2 =
Fixes a critical error on sites without mcp-adapter the first time a pack allowed an ability call. Update right away.

= 0.4.1 =
Renamed for the WordPress.org directory; no behaviour change. If you installed 0.4.0 from GitHub, deactivate and delete the old agent-safety folder, then install this one. Tables and settings carry over.

= 0.4.0 =
Schema change: the grants table is renamed and the approvals table gains new columns. Back up
your database before upgrading. There is no downgrade path back to a pre-0.4.0 build once this
runs.

== Changelog ==

= 0.5.0 =
* Change history: every governed write records the fields it changed, before and after, linked
  to its audit row. Listed under Tools → Agent Changes and kept for 30 days by default.
* Undo: restore one change, or everything one agent session changed, after a preview. Covers
  posts, pages, post meta, terms, options, WooCommerce products, prices and coupons, and
  FluentCart and SureCart catalogue objects. A change made since the agent's is never
  overwritten; the screen shows it instead.
* Ability review under Tools → Agent Abilities: give abilities registered by other plugins a tier
  without code. Unreviewed abilities stay refused, and a changed input schema needs a fresh
  review.
* IP allowlist for application passwords and WooCommerce API keys, with trusted-proxy support.
  Browser sessions are never IP-checked.
* FluentCart and SureCart modules, each with its own verb catalogue, elevation rules and starter
  packs.
* WooCommerce: moving an order to pending or on-hold is now irreversible-tier, like cancelling
  it, so the starter packs hold it for approval.
* Denials now carry a machine-readable `reason` in their error data.
* The Pending Agent Actions screen summarises held calls from their arguments, with secrets
  redacted and long values cut short. Approval emails and webhooks still carry no arguments.
* Calls made through a plugin's own MCP server (SenroFlux's, for example) are now written to the
  audit log, not only calls through mcp-adapter's default server.
* Refuses to load beside the old Agent Safety plugin instead of causing a fatal error.
* Public PHP API for change history and undo (`agent_safety()->changes()`), and for ability
  review status, so other plugins can show an agent's changes.

= 0.4.4 =
* Security: order status values with WooCommerce's wc- prefix now get the same approval tier as
  unprefixed ones.

= 0.4.3 =
* Renamed to SenroGate: slug and text domain `senrogate`, main file `senrogate.php`. Data, settings
  and hooks are unchanged. If you installed an earlier build from GitHub, deactivate and delete the
  old plugin folder, then install this one; tables and settings carry over.
* Fixed: rate limits, tripwires and daily spend caps could be exceeded under concurrent requests,
  because their counters were read-modify-write transients. Counters now live in their own table and
  each change is a single atomic statement; a call is counted before it is checked.
* The multisite and grants-table notices now show only to users who can manage options.

= 0.4.2 =
* Fixed: on a site without mcp-adapter, the first ability call a capability pack allowed ended in a critical
  error, because the audit log reached for the MCP request handler, which needs mcp-adapter's interface.
  Denied calls were not affected.

= 0.4.1 =
* Renamed for the WordPress.org directory: new slug, text domain and main file.
* No behaviour change. Data, settings and hooks are unchanged.
* If you installed 0.4.0 from GitHub, deactivate and delete the old `agent-safety` folder, then
  install this one. Deleting the old plugin keeps your tables and settings unless
  `AGSAFE_REMOVE_DATA` is defined as true in `wp-config.php`, so they carry over.

= 0.4.0 =
* `core/*` module narrowed to the three abilities WordPress core actually registers
  (`get-site-info`, `get-user-info`, `get-environment-info`); any other `core/*` ability is now
  refused as an unknown verb instead of matching a speculative merge-proposal verb.
* WooCommerce module narrowed to the 16 abilities WooCommerce 11 ships; forward-compatibility
  entries for abilities WooCommerce hasn't shipped yet are removed, and anything else under
  `woocommerce/` is refused.
* `woocommerce/product-delete` is now side-effecting, and irreversible only with `force: true`,
  matching `products-delete`.
* Added a state fingerprint on Approvals: the target's revision marker is captured at request
  time and re-checked at claim time. A mismatch marks the old approval stale and files a fresh
  one. This narrows the window between a human's approval and the write; it does not close it.
* Added environment awareness: a site-address change voids shadow windows, grants, and
  unclaimed approvals rather than carrying them over silently, with an admin notice until the
  site is rebound.
* Added a self-service approval check: `agent-safety/check-approval` lets an agent poll the
  status of its own pending request.
* Directory-compliance pass: i18n on every user-facing string, an opt-in uninstall that now
  covers every option and cron event, disclosed audit-row retention through erasure requests
  documented in the exporter and privacy policy, and the grants table renamed to
  `agsafe_grants`.
* Bumped minimum requirements to WordPress 7.0 and PHP 8.1.
* No downgrade path: the grants table was renamed as part of this release's schema change, so a
  0.3.x build cannot read a 0.4.0 database.
