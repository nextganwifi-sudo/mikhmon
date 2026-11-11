# Hotspot Profile Migration & Auto-Active Cross-Router User Transfer (RouterOS 6 & 7)

This plan introduces a dedicated **Same-Router Profile Transfer** tool for moving Hotspot users between profiles in bulk, and upgrades the **Cross-Router User Transfer** system so transferred active users connect automatically on the new router without re-entering vouchers across both RouterOS 6 and RouterOS 7.

## User Review & Critical Decisions

> [!IMPORTANT]
> All core product decisions have been confirmed with your preferences and preserve 100% of existing Mikhmon functionality:

- **Confirmed Placement for Same-Router Profile Transfer**: Added inside the **Hotspot → Users** sidebar menu (directly below *Generate*) and in the **Hotspot User List** top action header.
- **Confirmed Selection Modes for Profile Transfer**: Supports moving **(1) All users of a source profile**, **(2) All users of a specific batch comment**, or **(3) Individually selected users** (via checkboxes in User List or multi-select picker) to any target profile on the same router.
- **Confirmed Auto-Activation on Cross-Router Transfer**: Exports active state (stamped expiration comment, used uptime/data allowance, locked MAC, active session MAC, and Hotspot cookie MAC) and provisions **MAC Lock + IP Binding (`type=bypassed`)** paired with automatic expiration cleanup on the destination router so users go online immediately without typing their voucher again.
- **Confirmed RSC Script Export**: Clicking **Download RSC** generates a RouterOS 6 & 7 compatible `.rsc` script containing `name` (username), `password`, `profile`, and `comment` (alongside optional auto-active bindings).

---

## 1. Overview & Core Concept

- **What It Does**:
  1. **Same-Router Profile Transfer**: Lets admins and permitted resellers move Hotspot users from one profile to another on the same router—either an entire profile at once, a specific voucher batch, or hand-picked users.
  2. **Cross-Router User Transfer (OS 6 & 7 + Auto-Active)**: Upgrades the existing *System → User Transfer* tool so that exporting and importing users between RouterOS 6 and RouterOS 7 works without errors (even for users with empty comments/passwords) and automatically activates already-used vouchers on the destination router via MAC lock and bypassed IP bindings until their exact expiration time.
- **Target Audience**: ISP/Hotspot administrators and resellers managing multi-profile packages or migrating/upgrading MikroTik routers between RouterOS 6 and RouterOS 7.
- **Key Value**: Eliminates manual one-by-one profile editing and prevents end-users from being forced to re-enter voucher codes after a router change or OS upgrade.

---

## 2. User Experience & Visual Design

- **Key User Flows**:
  1. **Flow A — Same-Router Profile Transfer**:
     - Navigate to **Hotspot → Users → Profile Transfer** (from the sidebar under *Generate* or the *Move Profile* button in the User List header).
     - Choose Selection Mode:
       - *By Profile*: Pick Source Profile → see live user count → pick Destination Profile.
       - *By Batch Comment*: Pick Batch (`vc-...` / `up-...`) → pick Destination Profile.
       - *Selected Users*: Check users directly in the Hotspot User List table (or search & check in the transfer card) → pick Destination Profile.
     - Click **Transfer Profile** → view instant progress bar and summary of moved users.
  2. **Flow B — Cross-Router Export & Auto-Active Import (`User Transfer`)**:
     - **Export**: Choose Destination OS (`RouterOS 6` or `RouterOS 7`) and Filter (`All Hotspot users`, `Batch`, or `Specific profile`).
       - Clicking **Download file (.json)** exports users along with their password, profile, comment/expiry, used uptime, remaining limit-uptime, and associated device MAC address (collected from user MAC, active session, or Hotspot cookie).
       - Clicking **Download RSC (.rsc)** downloads a clean RouterOS terminal script with `name`, `password`, `profile`, and `comment` ready to paste into WinBox New Terminal.
     - **Import**: Upload the `.json` transfer file, choose Destination Profile (or keep original profile if matching) and toggle **Auto-Activate Used Vouchers (MAC + IP Binding Bypass)** (enabled by default).
     - Click **Validate & Start Import** → users are created in batches, active devices get bypassed IP bindings tied to their expiration timestamp, and the destination profile's expiration monitor automatically cleans up both the user and IP binding when time runs out.

- **Visual Identity & Theme**:
  - Follows the active Mikhmon theme (`dark`, `light`, `blue`, `green`, `pink`) with compact mobile-friendly cards, high-contrast status badges, and full bilingual support (**English** by default, **বাংলা** when switched from the top profile menu).

---

## 3. Key Product Decisions & Trade-Offs

- **Decision 1: Seamless Auto-Activation via MAC Lock + IP Binding (`bypassed`) + Expiry Sync**
  - *Chosen Approach*: MikroTik RouterOS does not allow raw injection into `/ip/hotspot/cookie` via API. Instead, during export we capture each active user's device MAC address from `/ip/hotspot/user`, `/ip/hotspot/active`, and `/ip/hotspot/cookie`, plus their remaining validity/uptime. On import, for any voucher that was already activated and has not expired, we set its `mac-address` on `/ip/hotspot/user` and create an `/ip/hotspot/ip-binding` (`type=bypassed`, `comment="ngw-transfer:<username>"`) while ensuring the expiration scheduler removes both the user and its bypassed IP binding upon expiry.
  - *Why*: Guarantees zero-touch reconnection on the new router—customers' phones and routers stay online immediately without seeing a login page, while still expiring at the exact second their voucher ends.
- **Decision 2: Universal OS 6 & OS 7 Compatibility**
  - *Chosen Approach*: Normalize expiration comments to the universal Mikhmon date format (`mmm/dd/yyyy HH:MM:SS`) understood by our dual-stack RouterOS 6 & 7 scheduler scripts, and allow importing transfer files across OS 6 and OS 7 without blocking if the admin chooses to migrate between versions.
  - *Why*: Prevents broken transfers when upgrading a router from RouterOS 6 to RouterOS 7 or vice versa.

---

## 4. Technical Architecture & Data Strategy *(Technical Reference)*

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        Hotspot Management UI                            │
├───────────────────────────────────┬─────────────────────────────────────┤
│  1. Same-Router Profile Transfer  │  2. Cross-Router User Transfer      │
│  (?hotspot=profile-transfer)      │  (hotspot/usertransfer.php)         │
│  ├─ Mode 1: Entire Source Profile │  ├─ Export JSON (Users + Cookies +  │
│  ├─ Mode 2: Batch Comment         │  │   Active MAC + Uptime/Expiry)    │
│  └─ Mode 3: Selected Checkboxes   │  ├─ Export RSC (name, pass, prof,   │
│                                   │  │   comment for OS 6 & OS 7)       │
│                                   │  └─ Import Job Engine:              │
│                                   │      ├─ Add /ip/hotspot/user        │
│                                   │      ├─ Add /ip/hotspot/ip-binding  │
│                                   │      │   (bypassed for active MACs) │
│                                   │      └─ Sync OS 6/7 Expiry Monitor  │
└─────────────────┬─────────────────┴──────────────────┬──────────────────┘
                  │                                    │
                  ▼                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│             MikroTik RouterOS API (v6 & v7 Dual-Stack)                  │
│  • /ip/hotspot/user (set profile / add user with remaining uptime)      │
│  • /ip/hotspot/active & /ip/hotspot/cookie (read live client MACs)      │
│  • /ip/hotspot/ip-binding (auto-active bypass + auto-remove on expiry)  │
│  • /system/scheduler (universal ROS 6 & 7 expiry & binding cleanup)     │
└─────────────────────────────────────────────────────────────────────────┘
```

- **Data Model & State**:
  - **Export Snapshot Payload (`.json`)**:
    - `name`, `password`, `profile`, `comment` (normalized expiry + batch note)
    - `mac-address` (resolved from user record → active session → hotspot cookie)
    - `uptime_used`, `limit_uptime`, `remaining_uptime`
    - `is_activated` (boolean: whether voucher was already in use/activated on source router)
    - `expiry_timestamp` & `expiry_comment`
  - **RSC Export Format (`.rsc`)**:
    - Safe RouterOS 6 & 7 script commands adding `/ip hotspot user` with `name`, `password`, `profile`, and `comment` (skipping duplicates safely).
- **Interactive Component & State Mapping**:
  - **Hotspot User List (`hotspot/users.php`)**: Adds row selection checkboxes, a "Select All" header checkbox, and a "Move Selected to Profile" quick bar alongside the header link to the dedicated Profile Transfer page.
  - **Profile Transfer Page (`hotspot/profiletransfer.php`)**: Interactive filter tabs (By Profile / By Batch / Selected Users), live preview of matching users, destination profile selector, and batch execution handler.
  - **Cross-Router Transfer (`hotspot/usertransfer.php` & `include/user_transfer.php`)**: Fixes missing-comment/empty-password export exceptions, enriches export with active/cookie MACs and remaining uptime, and updates the chunked import step engine to provision bypassed IP bindings and expiration cleanup for RouterOS 6 and 7.
