# Implementation Plan - Mobile Login UI Branding & Title Position Update

## User Request
- All 4 UIs (`classic`, `modern`, `compact`, `aurora`) login page mobile view:
  1. **Logo**: Round logo centered.
  2. **Company Name**: Below the logo, **'নেক্সজেন ওয়াইফাই'** (large/bold).
  3. **Company Subtitle**: Below that, **'সলিউশন'** (small).
  4. **"Please Login" (প্লিজ লগইন)**: Positioned at the **top-left corner** of the login card/box on mobile view.

## Proposed Changes
1. **`mikhmon/include/login.php` & CSS**:
   - Add a unified mobile branding & title CSS class (`.ngw-login-mob-header`) where:
     - "Please Login" title sits at the **top-left corner** (`text-align: left`, font size ~15-16px, bold).
     - Round logo (`ngw-round-medallion`, ~80-86px) is centered.
     - Company title **'নেক্সজেন ওয়াইফাই'** is rendered large & bold (`font-size: 18px`, weight 800, color `#ffffff`).
     - Subtitle **'সলিউশন'** is rendered smaller (`font-size: 12.5px`, color `#93c5fd` or theme accent, weight 600).
   - Apply this consistently across all 4 UI mobile login sections in `mikhmon/include/login.php`.
2. **Asset Version Bump**:
   - Bump cache buster to `?v=20261125f` across `mikhmon/include/login.php`, `mikhmon/include/headhtml.php`, `mikhmon/sw.js`, `mikhmon/manifest.webmanifest`, `mikhmon/js/ngw-pwa.js`.
3. **Triple-Sync**:
   - Copy all updated files to `/` and `mikhmonv3-upstream/`.
4. **Verification**:
   - Run PHP syntax check (`php -l`), compile (`compile_applet`), lint (`lint_applet`), and push to GitHub `main`.
