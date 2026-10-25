# Per-Reseller Template/Logo Management, VPS Domain Hosting & Safe Auto-Updater, and MikroTik SSH Connection Mode

This plan details how to edit each reseller's individual HTML template and logo when hosted on a VPS, how to host the software on a LightNode VPS with a custom domain and update it from AI Studio with **zero data loss**, and how to connect MikroTik routers using **SSH** (alongside the default **API**) via a compact toggle next to `IP MikroTik`.

---

## User Review & Confirmed Decisions

> [!IMPORTANT]
> The following design choices were confirmed from your answers and requirements:

- **Confirmed Decision 1 — MikroTik `[API | SSH]` Toggle Next to `IP MikroTik`**: The Router Settings page keeps its exact existing layout, with **API** selected by default and a compact **SSH** button right beside the `IP MikroTik` input field so you can switch any router to SSH-only authentication whenever the MikroTik account lacks API access.
- **Confirmed Decision 2 — Per-Reseller HTML Template & Logo Editing on VPS**: Both **Template Editor** and **Upload Logo** pages will include a **Reseller Selector Dropdown** (`Global` or any specific Reseller), and the **Reseller Management** list will feature direct shortcut buttons (`Template` and `Logo`) for each reseller.
- **Confirmed Decision 3 — LightNode VPS Auto-Install & 1-Click Safe Update**: A dedicated **System Update & VPS Guide** module in Admin Settings will provide the 1-command LightNode VPS + Domain installer script, 1-Click Safe Update (via Git Pull or AI Studio ZIP package), and automatic pre-update data protection so routers, resellers, templates, and logos are never lost.
- **Confirmed Decision 4 — Bilingual Language Support (`English` Default + `বাংলা`)**: All new labels, buttons, tooltips, and instructions default to **English** everywhere and switch seamlessly to **Bengali (`বাংলা`)** when Bengali is selected.

---

## 1. Overview & Complete Answers to Your Questions

### A. How to Edit Each Reseller's HTML Template & Upload Separate Logos on a VPS
When hosted on your VPS, you **never** need to edit files manually via FTP or SSH:
1. **Editing Each Reseller's HTML Voucher Code (`Default`, `Thermal`, `Small`)**:
   - Go to **Template Editor** from the left menu (or click the **Edit Template** shortcut button next to any reseller in **Reseller Management**).
   - At the top of the Template Editor, use the **Scope / Reseller Dropdown** to select the reseller (e.g., `#101 reseller1 (NGW-001)`).
   - Choose the template type (`Default`, `Thermal`, or `Small`), edit the HTML/PHP voucher code directly in the built-in code editor, and click **Save** or **Publish**.
   - Each reseller's code is stored in its own isolated folder (`uploads/reseller-templates/{reseller_id}/`), so editing one reseller's template never affects another reseller or the global template.
2. **Uploading Each Reseller's Individual Logo**:
   - Go to **Upload Logo** from the left menu (or click the **Logo** shortcut button in **Reseller Management**).
   - Select the target reseller from the **Select Reseller / Scope** dropdown (or select `Session / Global Logo` for the main router logo).
   - Choose any `PNG`, `JPG`, or `WebP` image file and click **Upload**. It automatically saves to `uploads/reseller-logos/{reseller_id}/` and appears on that reseller's printed vouchers immediately.

---

### B. How to Host on a LightNode VPS with a Custom Domain
1. **Point Your Domain to the LightNode VPS Public IP**:
   - In your domain registrar's DNS panel (e.g., Namecheap, Cloudflare, GoDaddy), create two `A` records pointing to your LightNode VPS Public IPv4 address:
     - `A` Record: Host `@` $\rightarrow$ `YOUR_VPS_PUBLIC_IP`
     - `A` Record: Host `www` $\rightarrow$ `YOUR_VPS_PUBLIC_IP`
2. **Run the 1-Command VPS Auto-Installer**:
   - Connect to your Ubuntu/Debian LightNode VPS via SSH (`ssh root@YOUR_VPS_PUBLIC_IP`).
   - Run the provided **VPS Auto-Install Command** (available directly inside the app's **System Update & VPS Guide** page).
   - The installer automatically installs **Nginx**, **PHP-FPM** (with `curl`, `mbstring`, `zip`, `xml`, `ssh2`), **Git**, and **Certbot SSL**, configures your custom domain, sets proper write permissions on data folders, and enables free HTTPS (`https://yourdomain.com`).

---

### C. How to Update Your Live VPS from AI Studio Without Losing Any Data
To ensure that **zero routers, resellers, passwords, templates, or logos are ever overwritten or lost** during an update:
1. **Strict Separation of Code vs. Protected Data**:
   - The Safe Update engine maintains a strict **Protected Data Locklist** that is **never overwritten** by code updates:
     - `include/config.php` (All MikroTik routers, IPs, credentials, hotspot names, SSH/API settings)
     - `include/resellers.json` & `include/permission_groups.json` (All reseller accounts, permissions, and group rules)
     - `uploads/reseller-templates/*` & `uploads/reseller-logos/*` (Every reseller's individual HTML voucher templates and logos)
     - `voucher/template*.php`, `img/logo-*.png`, & `images/logo-*.png` (Custom global templates and session logos)
2. **Two Ways to Push/Pull Updates in 1 Click**:
   - **Method 1 — 1-Click Git Pull Update (Recommended)**: Connect your AI Studio project to a GitHub repository. Whenever you make changes in AI Studio and push to GitHub, open your live domain's **Admin Panel $\rightarrow$ System Update**, and click **Check & Apply Safe Update (Git)**. The system automatically creates a full timestamped backup of your data, pulls the new code, and restores your live data files on top.
   - **Method 2 — 1-Click ZIP Package Upload**: Export the project ZIP from AI Studio, open your live domain's **Admin Panel $\rightarrow$ System Update**, upload the `.zip` file, and click **Apply Safe ZIP Update**. The updater extracts only application code while keeping all your existing routers, resellers, templates, and logos 100% intact.

---

## 2. User Experience & Visual Design

### Key User Flows
1. **MikroTik `API / SSH` Connection Toggle in Router Settings**:
   - In **Session Settings** (`Settings -> Session Settings`), the `IP MikroTik` row displays the IP input alongside a compact `[API | SSH]` toggle button right next to it.
   - **API** is selected by default (Port `8728`).
   - Clicking **SSH** switches the router session to SSH mode (Port `22` by default, or custom `IP:Port` such as `103.x.x.x:2222`).
   - Clicking **Save** and **Connect** connects to the MikroTik router over SSH using only the MikroTik `Username` and `Password`—even if the user's MikroTik group has `api` disabled and only `ssh,read,write` enabled.
2. **Per-Reseller Template & Logo Workflow**:
   - **Reseller Management List**: Adds compact `<i class="fa fa-code"></i>` (Template Editor) and `<i class="fa fa-image"></i>` (Upload Logo) action buttons in each reseller row.
   - **Upload Logo Page**: Adds a **Target Scope Dropdown** (`Global / Router Session Logo` vs. `Reseller #101 ...`) at the top of the Upload Logo card so the Administrator can upload, preview, or reset any reseller's logo directly.
3. **System Update & VPS Hosting Center**:
   - Accessible to the Administrator from the Settings menu (`System Update & VPS`).
   - Displays:
     - **Protected Data Status**: Live verification badge confirming that `config.php`, `resellers.json`, reseller templates, and logos are protected.
     - **1-Click Data Backup & Restore**: Download a `.zip` snapshot of all routers, resellers, templates, and logos, or restore from a snapshot.
     - **1-Click Safe Code Update**: Update via **Git Pull** or **AI Studio ZIP Upload** without touching any live data.
     - **LightNode VPS + Domain Setup Script**: Ready-to-copy commands for Ubuntu/Debian VPS setup with Nginx, PHP-FPM, and SSL.

---

## 3. Key Product Decisions & Trade-Offs

- **Decision 1 — Backward-Compatible SSH Mode Encoding in `include/config.php`**:
  - *Chosen Approach*: Encode the connection protocol cleanly inside the session configuration and parse it transparently in `include/readcfg.php` and `RouterosAPI::connect()`.
  - *Why*: Over 30 PHP files call `$API->connect($iphost, $userhost, decrypt($passwdhost))`. By handling SSH detection directly inside `RouterosAPI::connect()` and `readcfg.php`, every single module in the software (Dashboard, Hotspot Users, Generate Voucher, Reset Voucher, Active Sessions, Logs, Traffic Monitor, Selling Reports) works seamlessly over SSH with zero risk of breaking existing pages.
- **Decision 2 — Multi-Driver SSH Transport (`ssh2` extension + OpenSSH CLI + Socket Fallback)**:
  - *Chosen Approach*: `RouterosAPI` will support PHP's `ssh2_connect` extension, native Linux `ssh` CLI with password pipe / `sshpass` on VPS, and a local simulated SSH transport for the AI Studio test environment.
  - *Why*: Ensures 100% compatibility across any Linux VPS environment as well as instant testing inside the AI Studio preview.

---

## 4. Technical Architecture & Data Strategy

### Architecture & Component Diagram
```
┌────────────────────────────────────────────────────────────────────────────┐
│                        NextGan WiFi Solution Web UI                        │
├──────────────────────────┬─────────────────────────┬───────────────────────┤
│   Router Settings UI     │  Template & Logo Studio │  VPS Safe Update UI   │
│  [IP MikroTik] [API|SSH] │  Scope: Global/Reseller │  Git Pull / ZIP Sync  │
└────────────┬─────────────┴────────────┬────────────┴───────────┬───────────┘
             │                          │                        │
             ▼                          ▼                        ▼
┌──────────────────────────┐ ┌───────────────────────┐ ┌─────────────────────┐
│   RouterosAPI Engine     │ │ Isolated Reseller FS  │ │ Zero-Data-Loss Core │
│ ┌──────────┐ ┌─────────┐ │ │ • reseller-templates/ │ │ 1. Snapshot Data    │
│ │Port 8728 │ │Port 22  │ │ │ • reseller-logos/     │ │ 2. Sync Code Only   │
│ │Binary API│ │SSH CLI  │ │ │ • resellers.json      │ │ 3. Restore Data     │
│ └──────────┘ └─────────┘ │ └───────────────────────┘ └─────────────────────┘
└──────────────────────────┘
```

### Interactive Component & State Mapping
- **Router Settings (`[API | SSH]` Toggle)**:
  - Clicking `API` or `SSH` updates the hidden `conn_mode` input (`api` or `ssh`) and visual active badge next to `IP MikroTik`.
  - On Save, `settings.php` persists the mode and `readcfg.php` exposes `$connmode` (`api` or `ssh`) while passing the protocol hint to `RouterosAPI`.
- **RouterOS API-to-SSH CLI Translator**:
  - Translates `$API->comm($path, $params)` calls (such as `/ip/hotspot/user/print`, `/ip/hotspot/user/add`, `/ip/hotspot/user/set`, `/ip/hotspot/user/remove`, `/ip/hotspot/active/print`, `/system/script/print`, `/interface/monitor-traffic`, `/system/resource/print`) into equivalent RouterOS CLI commands executed over SSH (`:put`, `print detail without-paging`, `where`, `set`, `add`, `remove`) and parses key-value pairs into the exact associative array structure returned by the Binary API.
- **Safe Update & Backup Handler**:
  - `createDataSnapshot()`: Archives `include/config.php`, `include/resellers.json`, `include/permission_groups.json`, `uploads/`, `voucher/template*.php`, and `img/logo-*.png` before any update.
  - `applySafeZipUpdate()` / `applySafeGitUpdate()`: Overwrites only code files, explicitly skipping and re-verifying all files in the Protected Data Locklist.
