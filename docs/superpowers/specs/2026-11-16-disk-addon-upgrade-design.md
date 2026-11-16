# Disk Addon Upgrade — Design (short)

## Intent
Customers who already have a server can buy a "disk package" as a WHMCS product addon
(sizes in multiples of 10 GB). The server's disk limit becomes
**base package disk + sum of active disk addons**. CPU/RAM are untouched.
Example: Package A 20 GB + 5 GB addon = 25 GB. Upgrade to Package B (40 GB) keeps the
addon: 40 + 5 = 45 GB.

## Goals
- Disk total is always recomputed from scratch: `package disk + Σ active disk addons`.
  Idempotent, so upgrades/downgrades, repeated purchases (stacking), cancel and
  suspend/expiry cannot drift.
- Disk addon purchase/termination only changes the existing server's disk.
- Package upgrade (`pterosync_ChangePackage`) carries the addons over.

## Non-goals / anti-goals
- A disk addon must never create a new server (current addon code, commit 8150c7d,
  creates a separate server for `addonId > 0`; that path stays for non-disk addons).
- No incremental +/- arithmetic against the panel's current value.
- No enforcement of the 10 GB multiple in code (that is a pricing/catalogue choice).

## Approaches considered
1. **Recompute total each time (chosen).** Robust across upgrades.
2. Incremental delta on the panel value. Rejected: wrong after package change.
3. Store extra disk in a custom field on the service. Rejected: second source of
   truth that must be kept in sync with addon status.

How an addon is recognised as a disk addon and how many GB it gives:
- **Chosen:** the addon is linked to this module in WHMCS and its module settings
  "Disk" field carries the GB (admin configures it in WHMCS, no file edit).
  Marked as a disk addon by having a disk value and no server-creation settings
  (exact marker to be settled in the plan; my call).
- **Fallback:** `config.json` map `"disk_addons": {"<addonId>": GB}`.
  Optional, used only if the WHMCS-side route proves unworkable.

## Behaviour
- Unit follows `disk_as_gb` (existing), converted via `pteroSyncConvertToMB`.
- Only addons with status `Active` for that `serviceid` count (my assumption).
- Addon Create → recompute + PATCH `servers/{id}/build`. Terminate/Suspend → recompute
  without it. ChangePackage → new package disk + active disk addons.
- The shared computation lives next to `pteroSyncGetMemorySwapAndDisk` (`helper.php`).

## Left to the builder
- Verify how WHMCS stores addon module settings (expected `tblmodule_configuration`,
  entity type addon) and what `$params` an addon module call actually receives.
- Error handling when the server doesn't exist, and a debug log line for the computed
  total (base + addons).
- Language strings / docs/wiki note for admins.
