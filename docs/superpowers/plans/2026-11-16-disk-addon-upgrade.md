# Disk Addon Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A server's disk limit is always `package disk + Σ active disk addons`, so buying/cancelling a disk addon or upgrading the package keeps the total correct.

**Architecture:** One pure function does the disk math (unit-tested without WHMCS). Thin DB readers find a service's active disk addons and its package base disk. Addon lifecycle calls (create/suspend/unsuspend/terminate/change-package on an addon) branch to a recompute that PATCHes only `servers/{id}/build`, never `/startup`. Parent `CreateAccount`/`ChangePackage` add active disk addons to the base.

**Tech Stack:** PHP 8.0+ (WHMCS provisioning module), Illuminate Capsule (`tblhostingaddons`, `tblmodule_configuration`, `tblhosting`, `tblproducts`), Pterodactyl application API.

**Spec:** `docs/superpowers/specs/2026-11-16-disk-addon-upgrade-design.md`

## Global Constraints

- Unit follows `PteroSyncInstance::get()->disk_as_gb`; panel values are MB (`pteroSyncConvertToMB`, ×1024).
- Only addons with status `Active` count. Disk addon sizes are multiples of 10 GB but this is **not** enforced.
- A disk addon is an addon linked to this module whose `disk` module setting is > 0 and whose `nest_id` and `egg_id` are both empty. **No new config option**: `pterosync_ConfigKeys()` has exactly 24 entries and WHMCS may cap product module settings at 24; also `pteroSyncGetOption` falls back by position, so never insert keys.
- Disk addons never create a server and never touch `servers/{id}/startup`.
- In every lifecycle function the disk-addon branch runs **before** the `serviceid = serviceid.addonId` rewrite; keep the original parent id.
- Do not add the addon lookup inside `pteroSyncGetMemorySwapAndDisk` (it receives the rewritten id).
- Base disk `0` means unlimited in Pterodactyl and must stay `0`.
- Plain PHP tests only (`tests/disk_addons_test.php`, run with `php`); `test.php`/`test.json` are gitignored, so don't use those names.

## Review Focus

- Base disk `0` (unlimited) with an addon → stays `0` (Task 1 test).
- Two 10 GB addons stack → +20 GB (Task 1 test).
- `disk_as_gb=false` → addon amounts treated as MB (Task 1 test).
- Non-numeric / negative / empty addon amounts are ignored, never reduce the total (Task 1 test).
- Disk addon bought before the parent server exists → clear error "parent server not provisioned yet", no server created (Task 3 manual check + code).
- Addon upgraded 10→20 GB goes down the recompute branch, not the server-creating branch (Task 3 manual check).
- Customer-set startup variables survive a disk purchase because `/startup` is never PATCHed (Task 3 manual check).

---

### Task 1: Disk math

**Files:**
- Modify: `pterosync/helper.php` (add function near `pteroSyncGetMemorySwapAndDisk`, ~line 370)
- Create: `tests/disk_addons_test.php`

**Interfaces:**
- Produces: `pteroSyncComputeTotalDiskMb(int $baseDiskMb, array $addonAmounts, bool $asGb): int` — `$addonAmounts` are raw numbers in the configured unit (GB if `$asGb`, else MB); returns total MB.

- [ ] **Step 1: Write the failing test.** `tests/disk_addons_test.php` includes `../pterosync/helper.php` with `error_reporting(E_ALL & ~E_WARNING)` (the include emits a harmless null-offset warning), defines a tiny `check($name, $actual, $expected)` that prints PASS/FAIL and exits 1 on failure, and asserts:
  - `(20480, [5], true)` → `25600`; `(40960, [5], true)` → `46080` (the Package A→B upgrade case)
  - `(20480, [10, 10], true)` → `40960`; `(20480, [], true)` → `20480`
  - `(20480, [512], false)` → `20992`
  - `(0, [10], true)` → `0`
  - `(20480, ['abc', -5, '', null, 5], true)` → `25600`
  - `(20480, ['2.5'], true)` → `23040`
- [ ] **Step 2: Run** `php tests/disk_addons_test.php`. Expected: fatal "undefined function pteroSyncComputeTotalDiskMb".
- [ ] **Step 3: Implement** `pteroSyncComputeTotalDiskMb` in `helper.php`: return `$baseDiskMb` unchanged when it is `<= 0`; sum only `is_numeric` and `> 0` amounts, convert with `pteroSyncConvertToMB` when `$asGb`, return `(int)round(base + extra)`.
- [ ] **Step 4: Run** the test again. Expected: all lines PASS, exit 0.
- [ ] **Step 5: Commit** `feat: add pure disk total computation with tests`.

### Task 2: Database readers

**Files:**
- Modify: `pterosync/helper.php`

**Interfaces:**
- Consumes: `pterosync_ConfigKeys()` (position of a key + 1 = its `configoptionN` number).
- Produces:
  - `pteroSyncGetDiskAddonAmount(int $addonId): ?float` — amount in the configured unit if the addon is a disk addon (see Global Constraints), else `null`.
  - `pteroSyncGetActiveDiskAddonAmounts(int $serviceId, array $excludeAddonIds = [], array $includeAddonIds = []): array` — list of floats; counts `tblhostingaddons` rows with `hostingid = $serviceId` that are `Active` **or** in `$includeAddonIds` (status ignored, for the addon currently being provisioned), minus `$excludeAddonIds` (the addon currently being terminated/suspended), keeping only disk addons.
  - `pteroSyncGetServiceBaseDiskMb(int $serviceId): int` — the parent package's disk in MB: configurable option named `disk` on the service first, else the product module setting (`tblhosting.packageid` → `tblproducts.configoption{N}`), same precedence as `pteroSyncGetOption`; applies `disk_as_gb` conversion.

- [ ] **Step 1: Implement the three functions** in `helper.php`, each a thin Capsule query. Addon module settings are read from `tblmodule_configuration` (`entity_type = 'addon'`, `entity_id = $addonId`, `setting_name = 'configoption' . N`, column `value`) — **unverified assumption, see Task 4**; keep all addon-settings access inside `pteroSyncGetDiskAddonAmount` so a different source is a one-function change.
- [ ] **Step 2: Lint** `php -l pterosync/helper.php`. Expected: "No syntax errors detected". (These cannot be unit-tested without WHMCS; they are verified in Task 4.)
- [ ] **Step 3: Commit** `feat: add disk addon and base disk readers`.

### Task 3: Recompute wiring

**Files:**
- Modify: `pterosync/helper.php` (two new functions)
- Modify: `pterosync/pterosync.php` — `pterosync_CreateAccount` (~427), `pterosync_SuspendAccount` (~706), `pterosync_UnsuspendAccount` (~726), `pterosync_TerminateAccount` (~746), `pterosync_ChangePackage` (~809)

**Interfaces:**
- Consumes: everything from Tasks 1–2; `pteroSyncGetServer($params, true)`, `pteroSyncApplicationApi`.
- Produces:
  - `pteroSyncSetServerDiskMb(array $params, array $serverData, int $diskMb): void` — throws `Exception` on non-200. PATCHes `servers/{serverData['id']}/build` with `allocation`, `memory`, `swap`, `io`, `cpu`, `threads`, `oom_disabled` and `feature_limits` copied from `$serverData` (`allocation`, `limits.*`, `feature_limits`), `disk` = `$diskMb`. Never calls `/startup`.
  - `pteroSyncApplyDiskAddon(array $params, int $parentServiceId, array $excludeAddonIds, array $includeAddonIds): string` — returns `'success'` or an error message. Sets `$params['serviceid'] = $parentServiceId`, fetches the server raw; if missing returns "Parent server is not provisioned yet; retry once the main service is active."; computes `pteroSyncComputeTotalDiskMb(pteroSyncGetServiceBaseDiskMb(...), pteroSyncGetActiveDiskAddonAmounts(...), disk_as_gb)`, logs it with `logModuleCall`, calls `pteroSyncSetServerDiskMb`.

- [ ] **Step 1: Implement both functions** in `helper.php` as specified; wrap in try/catch returning the message.
- [ ] **Step 2: Branch the five lifecycle functions.** At the very top of each, before the `serviceid` rewrite: if `$params['addonId'] > 0` and `pteroSyncGetDiskAddonAmount((int)$params['addonId']) !== null`, `return pteroSyncApplyDiskAddon($params, (int)$params['serviceid'], $exclude, $include)` with — Create, Unsuspend, ChangePackage: `include = [addonId]`, `exclude = []`; Suspend, Terminate: `include = []`, `exclude = [addonId]`.
- [ ] **Step 3: Parent integration.** In parent `pterosync_CreateAccount` and `pterosync_ChangePackage`, only when `addonId == 0`, set `$disk = pteroSyncComputeTotalDiskMb((int)$disk, pteroSyncGetActiveDiskAddonAmounts((int)$params['serviceid']), PteroSyncInstance::get()->disk_as_gb)` after `pteroSyncGetMemorySwapAndDisk` (capture the original `serviceid` before any rewrite). `$disk` is already MB there, so pass it as base.
- [ ] **Step 4: Lint** `php -l` on both files and re-run `php tests/disk_addons_test.php`. Expected: no syntax errors, all PASS.
- [ ] **Step 5: Commit** `feat: recompute server disk from package plus active disk addons`.

### Task 4: Verification notes

**Files:**
- Modify: `README.md` (short "Disk addons" section: how to set up an addon — link it to the PteroSync module, set only the *Disk* module setting, leave Nest/Egg empty)

- [ ] **Step 1: Add the README section.**
- [ ] **Step 2: Hand the user this checklist** (cannot be run here — no WHMCS install), and record results in the final message:
  1. Addon module settings appear for an addon linked to PteroSync and save (confirms `tblmodule_configuration` / `configoptionN` naming in `pteroSyncGetDiskAddonAmount`).
  2. Which `$params` arrive for an addon call (`serviceid` = parent id, `addonId` set).
  3. Addon status at the moment Terminate/Suspend runs (the exclude/include lists make either state correct).
  4. Package A (20 GB) + 5 GB addon = 25 GB; upgrade to Package B (40 GB) = 45 GB; cancel addon → 40 GB.
  5. Startup variables set by the customer survive a disk purchase.
- [ ] **Step 3: Commit** `docs: document disk addons`.
