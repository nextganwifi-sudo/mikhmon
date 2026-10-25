# Minimal GitHub System Update & Clean Reseller Management UI

This plan streamlines the **System Update** page into a single GitHub URL + **Git Safe Update** button, and redesigns **Reseller Management** into a clean, full-width **Reseller List** with a **Three-Dot (`⋮`) Action Menu**, a **Details Popup**, and a clean **Add/Edit Reseller Popup Modal**.

---

## User Review & Confirmed Decisions

> [!IMPORTANT]
> Summary of your confirmed requirements and design choices:

- **Confirmed Decision 1 — Single-Option GitHub System Update (`System Update`)**:
  - The **System Update** page will contain **only** one clean box: an input field to save/connect your **GitHub Repository URL** and a single **Git Safe Update** button.
  - All extra boxes (ZIP upload, backup tables, and guides) will be removed. When you push changes from AI Studio to GitHub and click **Git Safe Update**, the software automatically pulls the latest code from GitHub while keeping 100% of your existing data (routers, resellers, templates, logos) intact.
- **Confirmed Decision 2 — Clean Reseller Header & Full-Width Reseller List**:
  - Top header bar shows **only 3 items**: `Reseller Management | Add Reseller | Permission Group` (`(Read / Write / Full)` suffix and `Router List` link removed).
  - Clicking **Reseller** from the sidebar shows **only the full-width Reseller List** (`Action (⋮)`, `Username`, `Name`, `Mode`, `Routers`, `Status`)—no form is shown on the right side by default.
- **Confirmed Decision 3 — Three-Dot (`⋮`) Action Dropdown with `Details`**:
  - Each row's `Action` column has a clean **Three-Dot (`⋮`)** button that opens a dropdown menu containing:
    1. **Details** (opens a popup displaying all information about that reseller)
    2. **Edit** (opens the Edit Reseller popup modal)
    3. **Template Editor** (opens HTML Template Editor for that reseller)
    4. **Upload Logo** (opens Logo Upload for that reseller)
    5. **Activate / Deactivate**
    6. **Delete**
- **Confirmed Decision 4 — Streamlined Add / Edit Reseller Popup Modal**:
  - Clicking **Add Reseller** (in the top header) or **Edit** (inside the `⋮` menu) opens a clean **Popup Modal** window.
  - Inside the popup:
    - **Assigned Routers** uses a clean **Select Router (`Select Router ▾`) dropdown button** with multi-select checkboxes inside the dropdown.
    - Unnecessary fields (`Internal Reseller ID`, `Template Code & Layout`, `Reseller Logo`, and extra top toolbar buttons) are removed.
    - Contains **only ONE Save button** at the bottom.

---

## 1. Overview & Complete Explanation of How It Will Work

### A. System Update Page (`System Update`)
1. When you click **System Update** from the sidebar (or Admin Settings), you will see a single, clean card.
2. Inside the card:
   - **GitHub Repository URL Input**: Enter your GitHub repo link (e.g., `https://github.com/username/repo.git`). The system automatically saves and remembers this URL so you don't need to type it again.
   - **Single Button — `Git Safe Update`**: Clicking this button automatically backs up your live configuration (`config.php`, `resellers.json`, `permission_groups.json`, reseller templates, and logos), fetches the latest code from your GitHub repository, applies the update, and restores all your data so **zero data is ever lost**.

### B. Reseller Management Page (`Reseller`)
1. **Top Header Bar**:
   - Displays only: **`Reseller Management | Add Reseller | Permission Group`**.
   - Clicking **Permission Group** takes you directly to the Permission Group configuration table.
   - Clicking **Add Reseller** opens the **Add Reseller Popup Modal**.
2. **Full-Width Reseller List Table**:
   - Displays columns: **`Action`**, **`Username`**, **`Name`**, **`Mode`**, **`Routers`**, and **`Status`**.
   - Under **`Action`**, each reseller has a **`⋮` (Three-Dot)** button.
3. **Three-Dot (`⋮`) Menu Options**:
   - **Details**: Opens a clean **Reseller Details Popup** showing the reseller's ID, Username, Full Name, Account Status, Permission Mode, Assigned Routers (with Hotspot Names), Template Code, Active Logo preview, and Creation Date.
   - **Edit**: Opens the **Edit Reseller Popup Modal** pre-filled with that reseller's data.
   - **Template Editor**: Jumps directly to that reseller's HTML Template Editor.
   - **Upload Logo**: Jumps directly to that reseller's Logo Uploader.
   - **Activate / Deactivate**: Toggles the reseller's active status.
   - **Delete**: Deletes the reseller after confirmation.
4. **Clean Add / Edit Reseller Popup Modal**:
   - Contains only the essential fields:
     - **Username**
     - **Name**
     - **Password**
     - **Account Status** (`Active` / `Disabled`)
     - **Assigned Routers** (`Select Router ▾` dropdown button where you can tick one or multiple routers)
     - **Permission Mode** (`Read`, `Write`, `Full`, `Reset Voucher`, or `Custom`)
   - Has **only one `Save` button** at the bottom (plus a `Close` button to dismiss the popup).

---

## 2. User Experience & Visual Design

### Architecture & Layout Diagram
```
┌──────────────────────────────────────────────────────────────────────────┐
│  Reseller Management  |  + Add Reseller  |  Permission Group             │
├──────────────────────────────────────────────────────────────────────────┤
│  Reseller List (Full Width)                                              │
│  ┌────────┬────────────┬──────────────┬───────────┬───────────┬────────┐ │
│  │ Action │ Username   │ Name         │ Mode      │ Routers   │ Status │ │
│  ├────────┼────────────┼──────────────┼───────────┼───────────┼────────┤ │
│  │  [ ⋮ ] │ reseller1  │ North Outlet │ Write     │ MikroTik  │ Active │ │
│  └────┼───┴────────────┴──────────────┴───────────┴───────────┴────────┘ │
│       │                                                                  │
│       ▼  (Clicking ⋮ opens dropdown)                                     │
│  ┌──────────────────────┐                                                │
│  │ 👁 Details           │ ──► Opens [Reseller Details Popup Modal]       │
│  │ ✏ Edit              │ ──► Opens [Edit Reseller Popup Modal]          │
│  │ <> Template Editor   │ ──► Opens HTML Code Editor for Reseller        │
│  │ 🖼 Upload Logo       │ ──► Opens Logo Uploader for Reseller           │
│  │ 🔒 Activate/Disable  │                                                │
│  │ 🗑 Delete            │                                                │
│  └──────────────────────┘                                                │
└──────────────────────────────────────────────────────────────────────────┘
```

### Add / Edit Reseller Popup Modal Structure
```
┌────────────────────────────────────────────────────────────┐
│  Add Reseller / Edit Reseller                          [✕] │
├────────────────────────────────────────────────────────────┤
│  Username         [ ____________________________ ]         │
│  Name             [ ____________________________ ]         │
│  Password         [ ________________________ [👁] ]         │
│  Account Status   [ Active (Can Login)         ▾ ]         │
│  Assigned Routers [ Select Router (2 selected) ▾ ]         │
│                     ├─ [✓] MikroTik-v6 (NextGan-Hotspot)   │
│                     └─ [✓] MikroTik-v7 (NextGan-ROS7)      │
│  Permission Mode  [ Write                      ▾ ]         │
│                                                            │
│  [ 💾 Save ]                                               │
└────────────────────────────────────────────────────────────┘
```

---

## 3. Key Product Decisions & Trade-Offs

- **Decision 1 — Persistent GitHub URL Storage (`include/github_repo.txt`)**:
  - *Chosen Approach*: When you paste your GitHub URL and click **Git Safe Update**, the URL is saved in `include/github_repo.txt` (which is also added to the Protected Data Locklist) so it remains pre-filled on future visits.
  - *Why*: Eliminates re-entering the repository link every time you update.
- **Decision 2 — Preserving Existing Template/Logo Settings When Editing a Reseller**:
  - *Chosen Approach*: Removing the Template and Logo inputs from the Add/Edit Reseller popup keeps the form clean while the backend automatically preserves the reseller's existing `template_code`, `assigned_template`, `template_status`, and `logo_filename` (managed via the Three-Dot `⋮` menu).
  - *Why*: Ensures zero data loss on existing reseller templates/logos while making the Add/Edit form minimal and fast.
