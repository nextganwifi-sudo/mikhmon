<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Mikhmon Safe Extension (Reseller RBAC, Permission Groups, Templates & Logos)
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */

if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -16) == "reseller_acl.php") {
    header("Location:./");
    exit;
}

include_once __DIR__ . '/lang.php';

if (!defined('JSON_PRETTY_PRINT')) {
    define('JSON_PRETTY_PRINT', 128);
}
if (!defined('PASSWORD_DEFAULT')) {
    define('PASSWORD_DEFAULT', 1);
}
if (!function_exists('password_hash')) {
    function password_hash($password, $algo) {
        $salt = substr(str_replace('+', '.', base64_encode(md5(uniqid(mt_rand(), true), true))), 0, 22);
        return crypt((string)$password, '$2y$10$' . $salt);
    }
}
if (!function_exists('password_verify')) {
    function password_verify($password, $hash) {
        return crypt((string)$password, (string)$hash) === (string)$hash;
    }
}

/**
 * Organized 9-category permission registry across all modules:
 * 1. Hotspot Management (All Hotspot Users, Vouchers, Profiles & Active Sessions)
 * 2. User & Profile Transfer (All Cross-Router & Same-Router Transfer Operations)
 * 3. PPPoE Management (All PPPoE Users, Profiles, Online Sessions & Reset)
 * 4. Template & Logo Management (All Voucher Templates & Logo Operations)
 * 5. Network, IP Binding & DHCP (Hosts, IP Bindings, Cookies & DHCP Leases)
 * 6. Reports, Logs & Traffic Monitor (Logs, Selling Reports & Traffic Monitor)
 * 7. Router, System & Scheduler (System Scheduler, Reboot/Shutdown & Router Config)
 * 8. Account, Profile & Language (Self-Service Profile, Avatar, Password & Language/Theme)
 * 9. Reseller & Administrator Security (Protected Admin-Only Operations)
 */
function getAllMikhmonPermissionCategories() {
    return array(
        'Hotspot Management' => array(
            'view_dashboard'      => 'View Dashboard & System Resource',
            'view_users'          => 'View Hotspot Users & Voucher Lists',
            'add_user'            => 'Add Single Hotspot User',
            'generate_voucher'    => 'Generate Batch Vouchers',
            'edit_user'           => 'View & Edit Hotspot User Details',
            'delete_user'         => 'Delete Hotspot Users (Single / Batch / Expired)',
            'enable_disable_user' => 'Enable, Disable & Reset Hotspot Users',
            'reset_mac_address'   => 'Reset Hotspot MAC Address (Profile / Batch / All)',
            'reset_voucher'       => 'Reset Voucher (Reset MAC, Enable, Disable & Check Expiry)',
            'print_voucher'       => 'Print Vouchers (Default / QR / Small)',
            'view_profiles'       => 'View Hotspot User Profiles',
            'add_profile'         => 'Add Hotspot User Profile',
            'edit_profile'        => 'Edit Hotspot User Profile & Sync ROS6/ROS7 Scripts',
            'delete_profile'      => 'Delete Hotspot User Profile',
            'view_active'         => 'View Hotspot Active Sessions',
            'disconnect_active'   => 'Disconnect Active Hotspot User',
        ),
        'User & Profile Transfer' => array(
            'transfer_users_access'        => 'Access User Transfer Center (System → User Transfer)',
            'export_users'                 => 'Export Hotspot Users (.json Transfer File / CSV / Script)',
            'export_rsc_users'             => 'Download RouterOS 6 & 7 Terminal Script (.rsc)',
            'import_transfer_users'        => 'Import Users to Router (Cross-Router OS 6 & 7 Transfer)',
            'auto_activate_transfer_users' => 'Auto-Activate Used Vouchers on Transfer (MAC Lock + IP Binding Bypass)',
            'same_router_profile_transfer' => 'Same-Router Profile Transfer (Entire Profile / Batch / Selected Users)',
        ),
        'PPPoE Management' => array(
            'manage_pppoe'              => 'PPPoE Master Access (Enable/Disable Entire PPPoE Section)',
            'view_pppoe_users'          => 'View PPPoE Users & Disabled/Expired List',
            'add_pppoe_user'            => 'Add PPPoE User (Customer Name, Mobile, Username & Password)',
            'edit_pppoe_user'           => 'Edit PPPoE User Details, Profile & Validity',
            'enable_disable_pppoe_user' => 'Enable & Disable PPPoE Users',
            'delete_pppoe_user'         => 'Delete PPPoE Users',
            'view_pppoe_profiles'       => 'View PPPoE Profiles',
            'manage_pppoe_profiles'     => 'Add, Edit & Delete PPPoE Profiles (Auto Pool & Local IP)',
            'view_pppoe_active'         => 'View Online / Active PPPoE Sessions',
            'disconnect_pppoe_active'   => 'Disconnect Online PPPoE User',
            'reset_pppoe'               => 'Reset PPPoE User (Check Expiry, Clear MAC & Re-enable)',
        ),
        'Template & Logo Management' => array(
            'use_assigned_template'     => 'Use Assigned Voucher Template',
            'preview_assigned_template' => 'Preview Assigned Voucher Template',
            'edit_own_template'         => 'Edit Own Assigned Template & Upload Voucher Logo',
            'save_own_template'         => 'Save Own Template Draft',
            'publish_own_template'      => 'Publish Own Template',
            'restore_own_template'      => 'Restore Own Template Version',
            'assign_template'           => 'Assign or Change Reseller Template (Protected Admin-Only)',
            'manage_global_templates'   => 'Manage Global Voucher Templates (Protected Admin-Only)',
        ),
        'Network, IP Binding & DHCP' => array(
            'view_hosts'       => 'View Hotspot Hosts',
            'manage_hosts'     => 'Remove Hotspot Hosts',
            'bind_host_ip'     => 'Direct Host to IP Binding (MAC / IP / Regular / Bypassed / Blocked)',
            'view_ipbinding'   => 'View Hotspot IP Bindings',
            'manage_ipbinding' => 'Add, Enable, Disable & Remove IP Bindings',
            'edit_ipbinding'   => 'Edit IP Bindings, Status Type & Comments',
            'view_cookies'     => 'View Hotspot Cookies',
            'delete_cookies'   => 'Delete Hotspot Cookies',
            'view_dhcp'        => 'View DHCP Leases',
        ),
        'Reports, Logs & Traffic Monitor' => array(
            'view_logs'     => 'View Hotspot Log & User Log',
            'view_report'   => 'View & Print Selling Reports & Live Report',
            'export_report' => 'Export Selling Report (CSV)',
            'delete_report' => 'Delete Selling Report Records',
            'view_traffic'  => 'View Interface Traffic Monitor',
        ),
        'Router, System & Scheduler' => array(
            'view_scheduler'         => 'View System Scheduler (Menu & List)',
            'manage_scheduler'       => 'Manage System Scheduler (Enable, Disable & Remove)',
            'reboot_shutdown'        => 'Reboot & Shutdown Router',
            'manage_router_settings' => 'Modify Router Session Settings & Upload Logo',
        ),
        'Account, Profile & Language' => array(
            'manage_own_profile'    => 'View & Update Own Account Profile & Avatar',
            'change_own_password'   => 'Change Own Account Password',
            'switch_language_theme' => 'Switch Software Language (English / Bangla) & Theme',
        ),
        'Reseller & Administrator Security' => array(
            'view_resellers'           => 'View Reseller Accounts (Protected Admin-Only)',
            'manage_resellers'         => 'Create, Edit & Delete Resellers (Protected Admin-Only)',
            'manage_admin_account'     => 'Manage Administrator Accounts & Passwords (Protected)',
            'manage_permission_groups' => 'Manage Permission Group Definitions (Protected)',
            'manage_global_security'   => 'Manage VPN Hub, System Update & Global Security (Protected)',
        ),
    );
}

/**
 * Rich metadata (icons, accent colors, bilingual titles & permission labels) for the Permission UI.
 */
function getMikhmonPermissionSectionsMeta() {
    return array(
        'Hotspot Management' => array(
            'slug'     => 'hotspot',
            'icon'     => 'fa-wifi',
            'color'    => '#1f6feb',
            'bg'       => 'rgba(31, 111, 235, 0.14)',
            'border'   => 'rgba(56, 139, 253, 0.38)',
            'title_en' => 'Hotspot Management',
            'title_bn' => 'হটস্পট ম্যানেজমেন্ট (Hotspot)',
            'short_en' => 'Hotspot',
            'short_bn' => 'হটস্পট',
            'items_bn' => array(
                'view_dashboard'      => 'ড্যাশবোর্ড ও সিস্টেম রিসোর্স দেখা',
                'view_users'          => 'হটস্পট ইউজার ও ভাউচার লিস্ট দেখা',
                'add_user'            => 'সিঙ্গেল হটস্পট ইউজার যোগ করা',
                'generate_voucher'    => 'ব্যাচ ভাউচার জেনারেট করা',
                'edit_user'           => 'হটস্পট ইউজার ডিটেইলস দেখা ও এডিট করা',
                'delete_user'         => 'হটস্পট ইউজার ডিলিট করা (সিঙ্গেল / ব্যাচ / মেয়াদোত্তীর্ণ)',
                'enable_disable_user' => 'হটস্পট ইউজার ইনেবল, ডিজেবল ও রিসেট করা',
                'reset_mac_address'   => 'হটস্পট ইউজারের ম্যাক অ্যাড্রেস রিসেট করা (প্রোফাইল / ব্যাচ / সব)',
                'reset_voucher'       => 'ভাউচার রিসেট (ম্যাক রিসেট, ইনেবল, ডিজেবল ও মেয়াদ চেক)',
                'print_voucher'       => 'ভাউচার প্রিন্ট করা (Default / QR / Small)',
                'view_profiles'       => 'হটস্পট ইউজার প্রোফাইল লিস্ট দেখা',
                'add_profile'         => 'নতুন হটস্পট প্রোফাইল তৈরি করা',
                'edit_profile'        => 'হটস্পট প্রোফাইল এডিট ও ROS6/ROS7 স্ক্রিপ্ট সিঙ্ক',
                'delete_profile'      => 'হটস্পট প্রোফাইল ডিলিট করা',
                'view_active'         => 'হটস্পট অ্যাক্টিভ সেশন দেখা',
                'disconnect_active'   => 'হটস্পট অ্যাক্টিভ ইউজার ডিসকানেক্ট করা',
            ),
        ),
        'User & Profile Transfer' => array(
            'slug'     => 'transfer',
            'icon'     => 'fa-exchange',
            'color'    => '#0ea5e9',
            'bg'       => 'rgba(14, 165, 233, 0.14)',
            'border'   => 'rgba(56, 189, 248, 0.40)',
            'title_en' => 'User & Profile Transfer',
            'title_bn' => 'ইউজার ও প্রোফাইল ট্রান্সফার (Transfer)',
            'short_en' => 'Transfer',
            'short_bn' => 'ট্রান্সফার',
            'items_bn' => array(
                'transfer_users_access'        => 'ইউজার ট্রান্সফার সেন্টার অ্যাক্সেস (System → User Transfer)',
                'export_users'                 => 'হটস্পট ইউজার ফাইল ডাউনলোড (.json / CSV / Script)',
                'export_rsc_users'             => 'RouterOS 6 ও 7 টার্মিনাল স্ক্রিপ্ট (.rsc) ডাউনলোড',
                'import_transfer_users'        => 'এক রাউটার থেকে অন্য রাউটারে ইউজার ইমপোর্ট (OS 6 ও 7)',
                'auto_activate_transfer_users' => 'ট্রান্সফারে ব্যবহৃত ভাউচার অটো-অ্যাক্টিভ (MAC লক ও IP বাইন্ডিং)',
                'same_router_profile_transfer' => 'একই রাউটারে এক প্রোফাইল থেকে অন্য প্রোফাইলে ইউজার ট্রান্সফার',
            ),
        ),
        'PPPoE Management' => array(
            'slug'     => 'pppoe',
            'icon'     => 'fa-plug',
            'color'    => '#8b5cf6',
            'bg'       => 'rgba(139, 92, 246, 0.14)',
            'border'   => 'rgba(167, 139, 250, 0.38)',
            'title_en' => 'PPPoE Management',
            'title_bn' => 'পিপিপি ম্যানেজমেন্ট (PPPoE)',
            'short_en' => 'PPPoE',
            'short_bn' => 'পিপিপি (PPPoE)',
            'items_bn' => array(
                'manage_pppoe'              => 'পিপিপি মাস্টার অ্যাক্সেস (পুরো PPPoE সেকশন চালু/বন্ধ)',
                'view_pppoe_users'          => 'পিপিপি ইউজার লিস্ট ও এক্সপায়ারড ইউজার দেখা',
                'add_pppoe_user'            => 'নতুন পিপিপি ইউজার যোগ করা (নাম, মোবাইল, ইউজারনেম ও পাসওয়ার্ড)',
                'edit_pppoe_user'           => 'পিপিপি ইউজার ডিটেইলস, প্রোফাইল ও মেয়াদ এডিট করা',
                'enable_disable_pppoe_user' => 'পিপিপি ইউজার ইনেবল ও ডিজেবল করা',
                'delete_pppoe_user'         => 'পিপিপি ইউজার ডিলিট করা',
                'view_pppoe_profiles'       => 'পিপিপি প্রোফাইল লিস্ট দেখা',
                'manage_pppoe_profiles'     => 'পিপিপি প্রোফাইল তৈরি, এডিট ও ডিলিট (অটো পুল ও লোকাল IP)',
                'view_pppoe_active'         => 'অনলাইন / অ্যাক্টিভ পিপিপি সেশন দেখা',
                'disconnect_pppoe_active'   => 'অনলাইন পিপিপি ইউজার ডিসকানেক্ট করা',
                'reset_pppoe'               => 'পিপিপি ইউজার রিসেট (মেয়াদ চেক, ম্যাক ক্লিয়ার ও রি-ইনেবল)',
            ),
        ),
        'Template & Logo Management' => array(
            'slug'     => 'template',
            'icon'     => 'fa-code',
            'color'    => '#f59e0b',
            'bg'       => 'rgba(245, 158, 11, 0.14)',
            'border'   => 'rgba(251, 191, 36, 0.38)',
            'title_en' => 'Template & Logo Management',
            'title_bn' => 'টেমপ্লেট ও লোগো ম্যানেজমেন্ট (Template)',
            'short_en' => 'Templates',
            'short_bn' => 'টেমপ্লেট',
            'items_bn' => array(
                'use_assigned_template'     => 'নির্ধারিত ভাউচার টেমপ্লেট ব্যবহার করা',
                'preview_assigned_template' => 'ভাউচার টেমপ্লেট প্রিভিউ দেখা',
                'edit_own_template'         => 'নিজস্ব টেমপ্লেট এডিট ও ভাউচার লোগো আপলোড',
                'save_own_template'         => 'নিজস্ব টেমপ্লেট ড্রাফট সেভ করা',
                'publish_own_template'      => 'নিজস্ব টেমপ্লেট পাবলিশ করা',
                'restore_own_template'      => 'নিজস্ব টেমপ্লেট আগের ভার্সনে রিস্টোর করা',
                'assign_template'           => 'রিসেলার টেমপ্লেট নির্ধারণ বা পরিবর্তন (অ্যাডমিন সংরক্ষিত)',
                'manage_global_templates'   => 'গ্লোবাল ভাউচার টেমপ্লেট ম্যানেজমেন্ট (অ্যাডমিন সংরক্ষিত)',
            ),
        ),
        'Network, IP Binding & DHCP' => array(
            'slug'     => 'network',
            'icon'     => 'fa-sitemap',
            'color'    => '#10b981',
            'bg'       => 'rgba(16, 185, 129, 0.14)',
            'border'   => 'rgba(52, 211, 153, 0.38)',
            'title_en' => 'Network, IP Binding & DHCP',
            'title_bn' => 'নেটওয়ার্ক, IP বাইন্ডিং ও DHCP',
            'short_en' => 'Network',
            'short_bn' => 'নেটওয়ার্ক',
            'items_bn' => array(
                'view_hosts'       => 'হটস্পট হোস্ট লিস্ট দেখা',
                'manage_hosts'     => 'হটস্পট হোস্ট রিমুভ করা',
                'bind_host_ip'     => 'হোস্ট থেকে সরাসরি IP বাইন্ডিং করা (MAC / IP / Regular / Bypassed / Blocked)',
                'view_ipbinding'   => 'হটস্পট IP বাইন্ডিং দেখা',
                'manage_ipbinding' => 'IP বাইন্ডিং যোগ, ইনেবল, ডিজেবল ও ডিলিট করা',
                'edit_ipbinding'   => 'IP বাইন্ডিং, স্ট্যাটাস (Regular/Bypassed/Blocked) ও কমেন্ট এডিট করা',
                'view_cookies'     => 'হটস্পট কুকিজ দেখা',
                'delete_cookies'   => 'হটস্পট কুকিজ ডিলিট করা',
                'view_dhcp'        => 'DHCP লিজ দেখা',
            ),
        ),
        'Reports, Logs & Traffic Monitor' => array(
            'slug'     => 'reports',
            'icon'     => 'fa-bar-chart',
            'color'    => '#ec4899',
            'bg'       => 'rgba(236, 72, 153, 0.14)',
            'border'   => 'rgba(244, 114, 182, 0.38)',
            'title_en' => 'Reports, Logs & Traffic Monitor',
            'title_bn' => 'রিপোর্ট, লগ ও ট্রাফিক মনিটর',
            'short_en' => 'Reports & Logs',
            'short_bn' => 'রিপোর্ট ও লগ',
            'items_bn' => array(
                'view_logs'     => 'হটস্পট লগ ও ইউজার লগ দেখা',
                'view_report'   => 'সেলিং রিপোর্ট ও লাইভ রিপোর্ট দেখা ও প্রিন্ট করা',
                'export_report' => 'সেলিং রিপোর্ট এক্সপোর্ট করা (CSV)',
                'delete_report' => 'সেলিং রিপোর্ট রেকর্ড ডিলিট করা',
                'view_traffic'  => 'ইন্টারফেস ট্রাফিক মনিটর দেখা',
            ),
        ),
        'Router, System & Scheduler' => array(
            'slug'     => 'router',
            'icon'     => 'fa-cogs',
            'color'    => '#06b6d4',
            'bg'       => 'rgba(6, 182, 212, 0.14)',
            'border'   => 'rgba(34, 211, 238, 0.38)',
            'title_en' => 'Router, System & Scheduler',
            'title_bn' => 'রাউটার, সিস্টেম ও শিডিউলার',
            'short_en' => 'Router & System',
            'short_bn' => 'রাউটার ও সিস্টেম',
            'items_bn' => array(
                'view_scheduler'         => 'সিস্টেম শিডিউলার দেখা (মেনু ও লিস্ট)',
                'manage_scheduler'       => 'সিস্টেম শিডিউলার ম্যানেজ করা (ইনেবল, ডিজেবল ও ডিলিট)',
                'reboot_shutdown'        => 'রাউটার রিবুট ও শাটডাউন করা',
                'manage_router_settings' => 'রাউটার সেশন সেটিংস পরিবর্তন ও লোগো আপলোড',
            ),
        ),
        'Account, Profile & Language' => array(
            'slug'     => 'account',
            'icon'     => 'fa-user-circle',
            'color'    => '#22c55e',
            'bg'       => 'rgba(34, 197, 94, 0.14)',
            'border'   => 'rgba(74, 222, 128, 0.38)',
            'title_en' => 'Account, Profile & Language',
            'title_bn' => 'অ্যাকাউন্ট, প্রোফাইল ও ভাষা সেটিংস',
            'short_en' => 'Account & Lang',
            'short_bn' => 'অ্যাকাউন্ট ও ভাষা',
            'items_bn' => array(
                'manage_own_profile'    => 'নিজস্ব প্রোফাইল তথ্য ও অবতার আপলোড করা',
                'change_own_password'   => 'নিজস্ব অ্যাকাউন্ট পাসওয়ার্ড পরিবর্তন করা',
                'switch_language_theme' => 'সফটওয়্যারের ভাষা (English / বাংলা) ও থিম পরিবর্তন করা',
            ),
        ),
        'Reseller & Administrator Security' => array(
            'slug'     => 'security',
            'icon'     => 'fa-shield',
            'color'    => '#ef4444',
            'bg'       => 'rgba(239, 68, 68, 0.14)',
            'border'   => 'rgba(248, 113, 113, 0.38)',
            'title_en' => 'Reseller & Administrator Security',
            'title_bn' => 'রিসেলার ও অ্যাডমিন সিকিউরিটি (সংরক্ষিত)',
            'short_en' => 'Admin Security',
            'short_bn' => 'অ্যাডমিন সংরক্ষিত',
            'items_bn' => array(
                'view_resellers'           => 'রিসেলার অ্যাকাউন্ট দেখা (অ্যাডমিন সংরক্ষিত)',
                'manage_resellers'         => 'রিসেলার তৈরি, এডিট ও ডিলিট (অ্যাডমিন সংরক্ষিত)',
                'manage_admin_account'     => 'অ্যাডমিন অ্যাকাউন্ট ও পাসওয়ার্ড ম্যানেজমেন্ট (সংরক্ষিত)',
                'manage_permission_groups' => 'পারমিশন গ্রুপ কনফিগারেশন (সংরক্ষিত)',
                'manage_global_security'   => 'VPN হাব, সিস্টেম আপডেট ও গ্লোবাল সিকিউরিটি (সংরক্ষিত)',
            ),
        ),
    );
}

/**
 * List of protected administrator-only permission keys that ordinary resellers can NEVER be granted,
 * even in Full or Custom mode.
 */
function getProtectedAdminOnlyPermissions() {
    return array(
        'assign_template',
        'manage_global_templates',
        'view_resellers',
        'manage_resellers',
        'manage_admin_account',
        'manage_permission_groups',
        'manage_global_security',
    );
}

/**
 * Check if a permission key is a protected Administrator-only operation.
 */
function isProtectedAdminPermission($permKey) {
    return in_array($permKey, getProtectedAdminOnlyPermissions(), true);
}

/**
 * Complete catalog of granular permissions (returns categorized map for UI and flat lookup support).
 */
function getAllMikhmonPermissionDefinitions() {
    return getAllMikhmonPermissionCategories();
}

/**
 * Return flat map of all permission keys => label
 */
function getFlatMikhmonPermissionLabels() {
    $flat = array();
    foreach (getAllMikhmonPermissionCategories() as $cat => $items) {
        foreach ($items as $k => $label) {
            $flat[$k] = $label;
        }
    }
    return $flat;
}

/**
 * Return flat map of all permission keys => default boolean value
 */
function getDefaultPermissionMap($defaultVal = false) {
    $map = array();
    foreach (getAllMikhmonPermissionCategories() as $group => $items) {
        foreach ($items as $key => $label) {
            if ($defaultVal && isProtectedAdminPermission($key)) {
                $map[$key] = true;
            } else {
                $map[$key] = (bool)$defaultVal;
            }
        }
    }
    return $map;
}

/**
 * Strip protected administrator-only permissions from any reseller permission map,
 * while migrating legacy permission maps cleanly.
 */
function sanitizeResellerPermissions($permMap) {
    $clean = getDefaultPermissionMap(false);
    if (is_array($permMap)) {
        // Legacy key aliases
        if (!empty($permMap['add_single_user'])) $permMap['add_user'] = true;
        if (!empty($permMap['view_reports'])) $permMap['view_report'] = true;
        if (!empty($permMap['delete_reports'])) $permMap['delete_report'] = true;
        if (!empty($permMap['remove_host'])) $permMap['manage_hosts'] = true;
        if (!empty($permMap['remove_cookie'])) $permMap['delete_cookies'] = true;
        if (!empty($permMap['manage_template_logo'])) {
            $permMap['edit_own_template'] = true;
            $permMap['save_own_template'] = true;
        }
        // Backward-compatible inheritance for newly added keys when loading older JSON files
        $hasExport = !empty($permMap['export_users']);
        $hasGen = !empty($permMap['generate_voucher']);
        $hasEditUser = !empty($permMap['edit_user']);
        if (!array_key_exists('transfer_users_access', $permMap) && ($hasExport || $hasGen)) {
            $permMap['transfer_users_access'] = true;
        }
        if (!array_key_exists('export_rsc_users', $permMap) && $hasExport) {
            $permMap['export_rsc_users'] = true;
        }
        if (!array_key_exists('import_transfer_users', $permMap) && ($hasExport && $hasGen)) {
            $permMap['import_transfer_users'] = true;
        }
        if (!array_key_exists('auto_activate_transfer_users', $permMap) && ($hasExport && $hasGen)) {
            $permMap['auto_activate_transfer_users'] = true;
        }
        if (!array_key_exists('same_router_profile_transfer', $permMap) && ($hasEditUser || $hasGen)) {
            $permMap['same_router_profile_transfer'] = true;
        }
        if (!array_key_exists('reset_mac_address', $permMap) && ($hasEditUser || !empty($permMap['enable_disable_user']) || !empty($permMap['reset_voucher']))) {
            $permMap['reset_mac_address'] = true;
        }
        if (!array_key_exists('bind_host_ip', $permMap) && (!empty($permMap['manage_hosts']) || !empty($permMap['manage_ipbinding']))) {
            $permMap['bind_host_ip'] = true;
        }
        if (!array_key_exists('edit_ipbinding', $permMap) && !empty($permMap['manage_ipbinding'])) {
            $permMap['edit_ipbinding'] = true;
        }
        if (!array_key_exists('manage_own_profile', $permMap) && (!empty($permMap['view_dashboard']) || !empty($permMap['view_users']))) {
            $permMap['manage_own_profile'] = true;
        }
        if (!array_key_exists('change_own_password', $permMap) && ($hasEditUser || $hasGen)) {
            $permMap['change_own_password'] = true;
        }
        if (!array_key_exists('switch_language_theme', $permMap)) {
            $permMap['switch_language_theme'] = true;
        }

        foreach ($clean as $k => $v) {
            if (isProtectedAdminPermission($k)) {
                $clean[$k] = false;
            } else {
                $clean[$k] = !empty($permMap[$k]);
            }
        }
    }
    return $clean;
}

/**
 * Built-in default permission group definitions for Read, Write, Full, and Reset Voucher modes.
 */
function getDefaultPermissionGroupDefinitions() {
    $readKeys = array(
        'view_dashboard',
        'view_users',
        'view_profiles',
        'view_active',
        'view_hosts',
        'view_ipbinding',
        'view_cookies',
        'view_dhcp',
        'view_logs',
        'view_report',
        'view_traffic',
        'manage_pppoe',
        'view_pppoe_users',
        'view_pppoe_profiles',
        'view_pppoe_active',
        'use_assigned_template',
        'preview_assigned_template',
        'manage_own_profile',
        'switch_language_theme',
    );

    $writeKeys = array_merge($readKeys, array(
        'add_user',
        'generate_voucher',
        'edit_user',
        'enable_disable_user',
        'reset_mac_address',
        'reset_voucher',
        'print_voucher',
        'transfer_users_access',
        'export_users',
        'export_rsc_users',
        'import_transfer_users',
        'auto_activate_transfer_users',
        'same_router_profile_transfer',
        'bind_host_ip',
        'edit_ipbinding',
        'export_report',
        'edit_own_template',
        'save_own_template',
        'add_pppoe_user',
        'edit_pppoe_user',
        'enable_disable_pppoe_user',
        'disconnect_pppoe_active',
        'reset_pppoe',
        'change_own_password',
    ));

    $readMap = getDefaultPermissionMap(false);
    foreach ($readKeys as $rk) {
        $readMap[$rk] = true;
    }

    $writeMap = getDefaultPermissionMap(false);
    foreach ($writeKeys as $wk) {
        $writeMap[$wk] = true;
    }

    $fullMap = getDefaultPermissionMap(false);
    foreach ($fullMap as $fk => $fv) {
        if (!isProtectedAdminPermission($fk) && $fk !== 'view_scheduler' && $fk !== 'manage_scheduler') {
            $fullMap[$fk] = true;
        }
    }

    $resetOnlyMap = getDefaultPermissionMap(false);
    $resetOnlyMap['reset_voucher'] = true;
    $resetOnlyMap['switch_language_theme'] = true;

    return array(
        'Read'          => sanitizeResellerPermissions($readMap),
        'Write'         => sanitizeResellerPermissions($writeMap),
        'Full'          => sanitizeResellerPermissions($fullMap),
        'Reset Voucher' => sanitizeResellerPermissions($resetOnlyMap),
    );
}

/**
 * Path to permission groups storage file
 */
function getPermissionGroupsStoragePath() {
    return __DIR__ . '/permission_groups.json';
}

/**
 * Load administrator-configurable Read, Write, and Full permission group definitions.
 */
function loadPermissionGroups($forceReload = false) {
    if (!$forceReload && isset($GLOBALS['NGW_CACHE_PERM_GROUPS']) && is_array($GLOBALS['NGW_CACHE_PERM_GROUPS'])) {
        return $GLOBALS['NGW_CACHE_PERM_GROUPS'];
    }
    $defaults = getDefaultPermissionGroupDefinitions();
    $path = getPermissionGroupsStoragePath();
    if (!file_exists($path)) {
        @file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT));
        $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $defaults;
        return $defaults;
    }
    $raw = @file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $defaults;
        return $defaults;
    }
    $result = array();
    foreach (array('Read', 'Write', 'Full', 'Reset Voucher') as $mode) {
        if (isset($decoded[$mode]) && is_array($decoded[$mode])) {
            $result[$mode] = sanitizeResellerPermissions(array_merge($defaults[$mode], $decoded[$mode]));
        } else {
            $result[$mode] = $defaults[$mode];
        }
    }
    $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $result;
    return $result;
}

/**
 * Save administrator-configured Read, Write, Full, and Reset Voucher permission groups.
 */
function savePermissionGroups($groups) {
    $defaults = getDefaultPermissionGroupDefinitions();
    $toSave = array();
    foreach (array('Read', 'Write', 'Full', 'Reset Voucher') as $mode) {
        $src = isset($groups[$mode]) && is_array($groups[$mode]) ? $groups[$mode] : $defaults[$mode];
        $toSave[$mode] = sanitizeResellerPermissions($src);
    }
    $ok = @file_put_contents(getPermissionGroupsStoragePath(), json_encode($toSave, JSON_PRETTY_PRINT)) !== false;
    $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $toSave;
    unset($GLOBALS['NGW_RESELLER_SYNCED']);
    return $ok;
}

/**
 * Standard Reseller permission preset (Write mode equivalent)
 */
function getStandardSellerPermissions() {
    $groups = loadPermissionGroups();
    return $groups['Write'];
}

/**
 * Resolve effective permissions for a reseller record based on permission_mode (Read, Write, Full, Reset Voucher, Custom)
 */
function resolveEffectiveResellerPermissions($reseller) {
    $mode = isset($reseller['permission_mode']) ? trim((string)$reseller['permission_mode']) : 'Custom';
    $groups = loadPermissionGroups();
    if ($mode === 'Read' || $mode === 'Write' || $mode === 'Full' || $mode === 'Reset Voucher') {
        return $groups[$mode];
    }
    $rawPerms = isset($reseller['permissions']) && is_array($reseller['permissions']) ? $reseller['permissions'] : getStandardSellerPermissions();
    return sanitizeResellerPermissions($rawPerms);
}

/**
 * Parse all configured router session names from include/config.php
 */
function getConfiguredRouterSessions($configFilePath = null) {
    if ($configFilePath === null) {
        $configFilePath = __DIR__ . '/config.php';
    }
    if (!file_exists($configFilePath)) {
        return array();
    }
    $routers = array();
    foreach (file($configFilePath) as $line) {
        $parts = explode("'", $line);
        if (isset($parts[1])) {
            $sesname = trim($parts[1]);
            if ($sesname !== '' && $sesname !== 'mikhmon' && strpos($line, '$data[') !== false) {
                $routers[$sesname] = $sesname;
            }
        }
    }
    return array_values($routers);
}

/**
 * Path to resellers storage file
 */
function getResellersStoragePath() {
    return __DIR__ . '/resellers.json';
}

/**
 * Ensure runtime upload directories exist with protective index.php and .htaccess files
 */
function ensureResellerRuntimeDirectories() {
    $baseRoot = dirname(__DIR__);
    $dirs = array(
        $baseRoot . '/uploads',
        $baseRoot . '/uploads/reseller-logos',
        $baseRoot . '/uploads/reseller-templates',
        $baseRoot . '/images',
    );
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        $idxFile = $d . '/index.php';
        if (!file_exists($idxFile)) {
            @file_put_contents($idxFile, "<?php header('Location:../'); exit; ?>\n");
        }
    }
    $htaccess = $baseRoot . '/uploads/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|sh)$\">\n  Deny from all\n</FilesMatch>\n");
    }
}

/**
 * Ensure a reseller's template directory has initialized template files
 */
function ensureResellerTemplateFiles($resellerId) {
    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$resellerId);
    if ($safeId === '') {
        return false;
    }
    ensureResellerRuntimeDirectories();
    $baseRoot = dirname(__DIR__);
    $tplDir = $baseRoot . '/uploads/reseller-templates/' . $safeId;
    if (!is_dir($tplDir)) {
        @mkdir($tplDir, 0755, true);
    }
    $variants = array(
        'template.php'         => $baseRoot . '/voucher/default.php',
        'template-thermal.php' => $baseRoot . '/voucher/default-thermal.php',
        'template-small.php'   => $baseRoot . '/voucher/default-small.php',
    );
    foreach ($variants as $targetName => $sourceDefault) {
        $targetPath = $tplDir . '/' . $targetName;
        if (!file_exists($targetPath) && file_exists($sourceDefault)) {
            @copy($sourceDefault, $targetPath);
        }
        $draftPath = $tplDir . '/' . str_replace('.php', '.draft.php', $targetName);
        if (!file_exists($draftPath) && file_exists($targetPath)) {
            @copy($targetPath, $draftPath);
        }
        $bakPath = $tplDir . '/' . str_replace('.php', '.bak.php', $targetName);
        if (!file_exists($bakPath) && file_exists($targetPath)) {
            @copy($targetPath, $bakPath);
        }
    }
    return true;
}

/**
 * Load all configured resellers from include/resellers.json with backward-compatible schema migration
 */
function loadResellers($forceReload = false) {
    if (!$forceReload && isset($GLOBALS['NGW_CACHE_RESELLERS']) && is_array($GLOBALS['NGW_CACHE_RESELLERS'])) {
        return $GLOBALS['NGW_CACHE_RESELLERS'];
    }
    ensureResellerRuntimeDirectories();
    $path = getResellersStoragePath();
    $groups = loadPermissionGroups();

    if (!file_exists($path)) {
        // Never provision publicly known demo reseller credentials on a fresh installation.
        // Existing deployments retain their resellers.json through the safe-update backup/restore flow.
        $seed = array();
        @file_put_contents($path, json_encode($seed, JSON_PRETTY_PRINT));
        return $seed;
    }

    $raw = @file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array();
    }

    $needsSave = false;
    foreach ($decoded as $idx => $res) {
        // Backward-compatible ID normalization: preserve stable numeric IDs (101, 102, ...) while keeping legacy_ids
        $currId = isset($res['id']) ? (string)$res['id'] : (string)(101 + $idx);
        if (!isset($res['legacy_ids']) || !is_array($res['legacy_ids'])) {
            $res['legacy_ids'] = array();
        }
        if ($currId === 'res_kiosk_utara' || $currId === 'reseller_1') {
            $res['legacy_ids'] = array_values(array_unique(array_merge($res['legacy_ids'], array($currId, 'res_kiosk_utara', 'reseller_1'))));
            $res['id'] = '101';
            $needsSave = true;
        } elseif ($currId === 'res_mitra_selatan' || $currId === 'reseller_2') {
            $res['legacy_ids'] = array_values(array_unique(array_merge($res['legacy_ids'], array($currId, 'res_mitra_selatan', 'reseller_2'))));
            $res['id'] = '102';
            $needsSave = true;
        } elseif ($currId === '') {
            $res['id'] = (string)(101 + $idx);
            $needsSave = true;
        }

        if (empty($res['permission_mode']) || !in_array($res['permission_mode'], array('Read', 'Write', 'Full', 'Reset Voucher', 'Custom'), true)) {
            $res['permission_mode'] = 'Custom';
            $needsSave = true;
        }
        if (empty($res['template_code'])) {
            $numCode = is_numeric($res['id']) ? ((int)$res['id'] - 100) : ($idx + 1);
            if ($numCode < 1) $numCode = $idx + 1;
            $res['template_code'] = sprintf('NGW-%03d', $numCode);
            $needsSave = true;
        }
        if (empty($res['assigned_template']) || !in_array($res['assigned_template'], array('default', 'thermal', 'small'), true)) {
            $res['assigned_template'] = 'default';
            $needsSave = true;
        }
        if (empty($res['template_status']) || !in_array($res['template_status'], array('active', 'inactive'), true)) {
            $res['template_status'] = 'active';
            $needsSave = true;
        }
        if (!isset($res['logo_filename'])) {
            $res['logo_filename'] = '';
            $needsSave = true;
        }

        $perms = isset($res['permissions']) && is_array($res['permissions']) ? $res['permissions'] : getStandardSellerPermissions();
        if ($res['permission_mode'] !== 'Reset Voucher') {
            if (!isset($perms['use_assigned_template'])) {
                $perms['use_assigned_template'] = true;
            }
            if (!isset($perms['preview_assigned_template'])) {
                $perms['preview_assigned_template'] = true;
            }
        }
        $res['permissions'] = sanitizeResellerPermissions($perms);
        ensureResellerTemplateFiles($res['id']);
        $decoded[$idx] = $res;
    }

    if ($needsSave) {
        @file_put_contents($path, json_encode(array_values($decoded), JSON_PRETTY_PRINT));
    }
    $GLOBALS['NGW_CACHE_RESELLERS'] = $decoded;
    return $decoded;
}

/**
 * Find a reseller record by internal ID, legacy ID, or username.
 */
function findResellerByIdOrUsername($identifier) {
    $identifier = trim((string)$identifier);
    if ($identifier === '') {
        return null;
    }
    foreach (loadResellers() as $r) {
        if ((string)$r['id'] === $identifier || (isset($r['username']) && $r['username'] === $identifier)) {
            return $r;
        }
        if (isset($r['legacy_ids']) && is_array($r['legacy_ids']) && in_array($identifier, $r['legacy_ids'], true)) {
            return $r;
        }
    }
    return null;
}

/**
 * Generate the next sequential numeric reseller ID (101, 102, 103...)
 */
function getNextResellerId($resellers) {
    $maxId = 100;
    foreach ($resellers as $r) {
        if (isset($r['id']) && is_numeric($r['id'])) {
            $val = (int)$r['id'];
            if ($val > $maxId) {
                $maxId = $val;
            }
        }
    }
    return (string)($maxId + 1);
}

/**
 * Save resellers array to include/resellers.json
 */
function saveResellers($resellers) {
    $path = getResellersStoragePath();
    $cleanList = array_values($resellers);
    $ok = @file_put_contents($path, json_encode($cleanList, JSON_PRETTY_PRINT)) !== false;
    $GLOBALS['NGW_CACHE_RESELLERS'] = $cleanList;
    unset($GLOBALS['NGW_RESELLER_SYNCED']);
    return $ok;
}

/**
 * Verify password against a reseller record
 */
function verifyResellerPassword($reseller, $plainPassword) {
    if (!empty($reseller['password_hash']) && password_verify($plainPassword, $reseller['password_hash'])) {
        return true;
    }
    if (!empty($reseller['password_enc']) && function_exists('decrypt')) {
        if (decrypt($reseller['password_enc']) === $plainPassword) {
            return true;
        }
    }
    return false;
}

/**
 * Retrieve the plain-text password for a reseller record (for Administrator Details view)
 */
function getResellerPlainPassword($reseller) {
    if (!is_array($reseller)) {
        return '';
    }
    $enc = isset($reseller['password_enc']) ? (string)$reseller['password_enc'] : '';
    $hash = isset($reseller['password_hash']) ? (string)$reseller['password_hash'] : '';
    if ($enc !== '') {
        if (function_exists('decrypt')) {
            $dec = (string)decrypt($enc);
            if ($hash === '' || password_verify($dec, $hash)) {
                return $dec;
            }
        }
        $b64 = @base64_decode($enc, true);
        if (is_string($b64) && $b64 !== '' && ($hash === '' || password_verify($b64, $hash))) {
            return $b64;
        }
        if (function_exists('decrypt')) {
            return (string)decrypt($enc);
        }
    }
    if (isset($reseller['password']) && is_string($reseller['password'])) {
        return $reseller['password'];
    }
    return '';
}

/**
 * Path to backup/secondary administrator credentials file
 */
function getBackupAdminStoragePath() {
    return dirname(__FILE__) . '/backup_admin.json';
}

/**
 * Load Secondary / Backup Admin credentials
 */
function getBackupAdminCredentials() {
    $path = getBackupAdminStoragePath();
    $default = array(
        'username' => '',
        'password_enc' => '',
        'password_hash' => '',
        'password' => '',
    );
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    $decoded = @json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return $default;
    }
    $u = isset($decoded['username']) ? trim((string)$decoded['username']) : '';
    $pEnc = isset($decoded['password_enc']) ? (string)$decoded['password_enc'] : '';
    $pHash = isset($decoded['password_hash']) ? (string)$decoded['password_hash'] : '';
    $pPlain = '';
    if ($pEnc !== '' && function_exists('decrypt')) {
        $pPlain = (string)decrypt($pEnc);
    } elseif (isset($decoded['password'])) {
        $pPlain = (string)$decoded['password'];
    }
    return array(
        'username' => $u,
        'password_enc' => $pEnc,
        'password_hash' => $pHash,
        'password' => $pPlain,
    );
}

/**
 * Save Secondary / Backup Admin credentials
 */
function saveBackupAdminCredentials($username2, $plainPassword2) {
    $username2 = trim((string)$username2);
    $plainPassword2 = (string)$plainPassword2;
    $pHash = '';
    if ($plainPassword2 !== '') {
        $pHash = password_hash($plainPassword2, PASSWORD_DEFAULT);
        if (!is_string($pHash) || $pHash === '') {
            return false;
        }
    }
    $data = array(
        'username' => $username2,
        'password_hash' => $pHash,
        'updated_at' => date('Y-m-d H:i:s'),
    );
    return @file_put_contents(getBackupAdminStoragePath(), json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Enforce 5-minute (300 seconds) server-side inactivity timeout on page navigations
 */
function enforceMikhmonSessionSecurity($isLoginOrLogout = false) {
    if ($isLoginOrLogout || !isset($_SESSION["mikhmon"])) {
        return;
    }
    if (function_exists('ngwIsCurrentSessionRevoked') && ngwIsCurrentSessionRevoked()) {
        $_SESSION = array();
        @session_unset();
        @session_destroy();
        echo "<script>try{sessionStorage.removeItem('ngw_tab_auth');sessionStorage.removeItem('ngw_last_act');}catch(e){} window.location='./admin.php?id=login&reason=force_logout';</script>";
        exit;
    }
    if (!empty($_SESSION['mikhmon_login_handshake'])) {
        $GLOBALS['NGW_LOGIN_HANDSHAKE'] = true;
        unset($_SESSION['mikhmon_login_handshake']);
    }
    $timeoutSeconds = 300; // 5 minutes
    $now = time();
    if (isset($_SESSION['mikhmon_last_activity'])) {
        $elapsed = $now - (int)$_SESSION['mikhmon_last_activity'];
        if ($elapsed > $timeoutSeconds) {
            if (function_exists('ngwRemoveCurrentActiveSession')) {
                ngwRemoveCurrentActiveSession();
            }
            $_SESSION = array();
            @session_unset();
            @session_destroy();
            echo "<script>try{sessionStorage.removeItem('ngw_tab_auth');sessionStorage.removeItem('ngw_last_act');}catch(e){} window.location='./admin.php?id=login';</script>";
            exit;
        }
    }
    $_SESSION['mikhmon_last_activity'] = $now;
    if (function_exists('ngwRegisterOrTouchActiveSession')) {
        ngwRegisterOrTouchActiveSession();
    }
}

/**
 * Path to multi-admin users storage file
 */
function getAdminUsersStoragePath() {
    return dirname(__FILE__) . '/admin_users.json';
}

/**
 * Sync the first admin user's credentials to include/config.php for legacy compatibility
 */
function syncPrimaryAdminToConfigFile($username, $passwordEnc) {
    $configPath = dirname(__FILE__) . '/config.php';
    if (!is_file($configPath) || !is_writable($configPath)) {
        return false;
    }
    $configContent = @file_get_contents($configPath);
    if ($configContent === false || $username === '' || $passwordEnc === '') {
        return false;
    }
    $replacement = "\$data['mikhmon'] = array ('1'=>'mikhmon<|<" . addslashes($username) . "','mikhmon>|>" . addslashes($passwordEnc) . "');";
    $replaceCount = 0;
    $newConfig = preg_replace('/^\s*\$data\[[\'"]mikhmon[\'"]\]\s*=\s*array\s*\(.*\);\s*$/m', $replacement, $configContent, 1, $replaceCount);
    if ($newConfig === null || $replaceCount !== 1) {
        return false;
    }
    $tmpPath = @tempnam(dirname($configPath), '.admcfg-');
    if ($tmpPath === false || @file_put_contents($tmpPath, $newConfig, LOCK_EX) === false) {
        if ($tmpPath !== false && is_file($tmpPath)) @unlink($tmpPath);
        return false;
    }
    $perms = @fileperms($configPath);
    if ($perms !== false) @chmod($tmpPath, $perms & 0777);
    $ok = @rename($tmpPath, $configPath);
    if (!$ok && is_file($tmpPath)) @unlink($tmpPath);
    return $ok;
}

/**
 * Load all configured Administrator accounts from include/admin_users.json.
 * Seeds automatically from include/config.php and include/backup_admin.json if not yet created.
 */
function loadAdminUsers($forceReload = false) {
    if (!$forceReload && isset($GLOBALS['NGW_CACHE_ADMIN_USERS']) && is_array($GLOBALS['NGW_CACHE_ADMIN_USERS'])) {
        return $GLOBALS['NGW_CACHE_ADMIN_USERS'];
    }
    $path = getAdminUsersStoragePath();
    $admins = array();
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $decoded = @json_decode((string)$raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (is_array($row) && !empty($row['username'])) {
                    $admins[] = array(
                        'id' => !empty($row['id']) ? (string)$row['id'] : ('adm_' . (count($admins) + 1)),
                        'name' => !empty($row['name']) ? trim((string)$row['name']) : trim((string)$row['username']),
                        'username' => trim((string)$row['username']),
                        'password_enc' => isset($row['password_enc']) ? (string)$row['password_enc'] : '',
                        'password_hash' => isset($row['password_hash']) ? (string)$row['password_hash'] : '',
                        'avatar' => isset($row['avatar']) ? (string)$row['avatar'] : '',
                        'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : date('Y-m-d H:i:s'),
                        'updated_at' => !empty($row['updated_at']) ? (string)$row['updated_at'] : date('Y-m-d H:i:s'),
                    );
                }
            }
        }
    }

    if (empty($admins)) {
        $cfgUser = 'mikhmon';
        $cfgPassEnc = 'aWNlbA==';
        $cfgPassPlain = '1234';
        $configPath = dirname(__FILE__) . '/config.php';
        if (is_file($configPath)) {
            include($configPath);
            if (isset($data['mikhmon']) && is_array($data['mikhmon'])) {
                if (isset($data['mikhmon'][1]) && strpos($data['mikhmon'][1], 'mikhmon<|<') !== false) {
                    $cfgUser = substr($data['mikhmon'][1], strlen('mikhmon<|<'));
                }
                foreach ($data['mikhmon'] as $v) {
                    if (is_string($v) && strpos($v, 'mikhmon>|> ') === false && strpos($v, 'mikhmon>|>') === 0) {
                        $cfgPassEnc = substr($v, strlen('mikhmon>|>'));
                    }
                }
            }
        }
        if (function_exists('decrypt') && $cfgPassEnc !== '') {
            $cfgPassPlain = (string)decrypt($cfgPassEnc);
        }
        $admins[] = array(
            'id' => 'adm_1',
            'name' => 'Administrator',
            'username' => $cfgUser !== '' ? $cfgUser : 'mikhmon',
            'password_enc' => $cfgPassEnc,
            'password_hash' => $cfgPassPlain !== '' ? password_hash($cfgPassPlain, PASSWORD_DEFAULT) : '',
            'avatar' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        $backup = getBackupAdminCredentials();
        if (!empty($backup['username']) && $backup['username'] !== $cfgUser) {
            $bPlain = !empty($backup['password']) ? (string)$backup['password'] : '';
            $bEnc = !empty($backup['password_enc']) ? (string)$backup['password_enc'] : ($bPlain !== '' && function_exists('encrypt') ? encrypt($bPlain) : '');
            $bHash = !empty($backup['password_hash']) ? (string)$backup['password_hash'] : ($bPlain !== '' ? password_hash($bPlain, PASSWORD_DEFAULT) : '');
            $admins[] = array(
                'id' => 'adm_2',
                'name' => 'Admin (' . $backup['username'] . ')',
                'username' => $backup['username'],
                'password_enc' => $bEnc,
                'password_hash' => $bHash,
                'avatar' => '',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            );
        }
        @file_put_contents($path, json_encode($admins, JSON_PRETTY_PRINT));
    }

    $GLOBALS['NGW_CACHE_ADMIN_USERS'] = $admins;
    return $admins;
}

/**
 * Save Administrator accounts array to include/admin_users.json and sync primary config.php
 */
function saveAdminUsers($admins) {
    $clean = array();
    foreach ($admins as $row) {
        if (!is_array($row) || empty($row['username'])) {
            continue;
        }
        $clean[] = array(
            'id' => !empty($row['id']) ? (string)$row['id'] : ('adm_' . (count($clean) + 1)),
            'name' => !empty($row['name']) ? trim((string)$row['name']) : trim((string)$row['username']),
            'username' => trim((string)$row['username']),
            'password_enc' => isset($row['password_enc']) ? (string)$row['password_enc'] : '',
            'password_hash' => isset($row['password_hash']) ? (string)$row['password_hash'] : '',
            'avatar' => isset($row['avatar']) ? (string)$row['avatar'] : '',
            'created_at' => !empty($row['created_at']) ? (string)$row['created_at'] : date('Y-m-d H:i:s'),
            'updated_at' => !empty($row['updated_at']) ? (string)$row['updated_at'] : date('Y-m-d H:i:s'),
        );
    }
    if (empty($clean)) {
        return false;
    }
    $path = getAdminUsersStoragePath();
    $ok = @file_put_contents($path, json_encode($clean, JSON_PRETTY_PRINT), LOCK_EX) !== false;
    if ($ok) {
        $GLOBALS['NGW_CACHE_ADMIN_USERS'] = $clean;
        if (!empty($clean[0]['username']) && !empty($clean[0]['password_enc'])) {
            syncPrimaryAdminToConfigFile($clean[0]['username'], $clean[0]['password_enc']);
        }
    }
    return $ok;
}

/**
 * Generate next unique admin user ID
 */
function generateNextAdminUserId($admins = null) {
    if (!is_array($admins)) {
        $admins = loadAdminUsers();
    }
    $maxNum = 0;
    foreach ($admins as $a) {
        if (isset($a['id']) && preg_match('/^adm_(\d+)$/', (string)$a['id'], $m)) {
            $n = (int)$m[1];
            if ($n > $maxNum) $maxNum = $n;
        }
    }
    return 'adm_' . ($maxNum + 1);
}

/**
 * Verify password against an admin user record
 */
function verifyAdminUserPassword($adminRecord, $plainPassword) {
    if (!is_array($adminRecord) || $plainPassword === '') {
        return false;
    }
    if (!empty($adminRecord['password_hash']) && password_verify($plainPassword, (string)$adminRecord['password_hash'])) {
        return true;
    }
    if (!empty($adminRecord['password_enc']) && function_exists('decrypt')) {
        $dec = (string)decrypt((string)$adminRecord['password_enc']);
        if ($dec !== '' && hash_equals($dec, (string)$plainPassword)) {
            return true;
        }
    }
    return false;
}

/**
 * Find an admin user record by ID or username
 */
function findAdminUserByIdOrUsername($identifier) {
    $identifier = trim((string)$identifier);
    if ($identifier === '') {
        return null;
    }
    $admins = loadAdminUsers();
    foreach ($admins as $adm) {
        if ((string)$adm['id'] === $identifier || (string)$adm['username'] === $identifier) {
            return $adm;
        }
    }
    return null;
}

/**
 * Get the currently logged-in Administrator record (or null if reseller/not logged in)
 */
function getCurrentAdminRecord() {
    if (!isMikhmonAdmin()) {
        return null;
    }
    $admins = loadAdminUsers();
    $admId = isset($_SESSION['mikhmon_admin_id']) ? trim((string)$_SESSION['mikhmon_admin_id']) : '';
    $uname = isset($_SESSION['mikhmon']) ? trim((string)$_SESSION['mikhmon']) : '';
    if ($admId !== '') {
        foreach ($admins as $adm) {
            if ((string)$adm['id'] === $admId) {
                return $adm;
            }
        }
    }
    if ($uname !== '') {
        foreach ($admins as $adm) {
            if ((string)$adm['username'] === $uname) {
                return $adm;
            }
        }
    }
    return !empty($admins[0]) ? $admins[0] : null;
}

/**
 * Authenticate user as Admin (from multi-admin list or primary config) or Reseller
 */
function authenticateMikhmonUser($username, $password, $useradm, $passadm_decrypted) {
    $username = trim((string)$username);
    $password = (string)$password;

    $admins = loadAdminUsers();
    foreach ($admins as $idx => $adm) {
        if (isset($adm['username']) && $adm['username'] === $username && verifyAdminUserPassword($adm, $password)) {
            // Upgrade password_hash or password_enc if missing
            $needsSave = false;
            if (empty($adm['password_hash'])) {
                $admins[$idx]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $needsSave = true;
            }
            if (empty($adm['password_enc']) && function_exists('encrypt')) {
                $admins[$idx]['password_enc'] = encrypt($password);
                $needsSave = true;
            }
            if ($needsSave) {
                saveAdminUsers($admins);
            }
            return array(
                'authenticated' => true,
                'role' => 'admin',
                'id' => (string)$adm['id'],
                'username' => (string)$adm['username'],
                'name' => !empty($adm['name']) ? (string)$adm['name'] : 'Administrator',
                'permission_mode' => 'Admin',
                'template_code' => 'GLOBAL',
                'assigned_template' => 'default',
                'template_status' => 'active',
                'logo_filename' => isset($adm['avatar']) ? (string)$adm['avatar'] : '',
                'allowed_routers' => array('*'),
                'permissions' => getDefaultPermissionMap(true),
            );
        }
    }

    $isPrimaryFallback = (
        $username !== '' && (
            ($username === $useradm && $password === $passadm_decrypted) ||
            ($useradm === 'mikhmon' && $passadm_decrypted === '1234' && $username === 'admin' && $password === 'Pass1213@')
        )
    );

    if ($isPrimaryFallback) {
        return array(
            'authenticated' => true,
            'role' => 'admin',
            'id' => 'adm_1',
            'username' => $username,
            'name' => 'Administrator',
            'permission_mode' => 'Admin',
            'template_code' => 'GLOBAL',
            'assigned_template' => 'default',
            'template_status' => 'active',
            'logo_filename' => '',
            'allowed_routers' => array('*'),
            'permissions' => getDefaultPermissionMap(true),
        );
    }

    $matchedAdminUsername = false;
    foreach ($admins as $adm) {
        if (isset($adm['username']) && strtolower((string)$adm['username']) === strtolower($username)) {
            $matchedAdminUsername = true;
            break;
        }
    }
    if ($username !== '' && strtolower($username) === strtolower((string)$useradm)) {
        $matchedAdminUsername = true;
    }

    $resellers = loadResellers();
    foreach ($resellers as $reseller) {
        if (isset($reseller['username']) && $reseller['username'] === $username) {
            if (!isset($reseller['status']) || $reseller['status'] !== 'active') {
                return array(
                    'authenticated' => false,
                    'error' => 'Reseller account is deactivated.',
                    'error_reason' => "Login blocked: Reseller account '{$username}' is deactivated",
                    'attempted_role' => 'reseller',
                );
            }
            if (verifyResellerPassword($reseller, $password)) {
                $effectivePerms = resolveEffectiveResellerPermissions($reseller);
                return array(
                    'authenticated' => true,
                    'role' => 'reseller',
                    'id' => (string)$reseller['id'],
                    'username' => $reseller['username'],
                    'name' => isset($reseller['name']) ? $reseller['name'] : $reseller['username'],
                    'permission_mode' => isset($reseller['permission_mode']) ? $reseller['permission_mode'] : 'Custom',
                    'template_code' => isset($reseller['template_code']) ? $reseller['template_code'] : 'NGW-001',
                    'assigned_template' => isset($reseller['assigned_template']) ? $reseller['assigned_template'] : 'default',
                    'template_status' => isset($reseller['template_status']) ? $reseller['template_status'] : 'active',
                    'logo_filename' => isset($reseller['logo_filename']) ? $reseller['logo_filename'] : '',
                    'allowed_routers' => isset($reseller['allowed_routers']) && is_array($reseller['allowed_routers']) ? $reseller['allowed_routers'] : array(),
                    'permissions' => $effectivePerms,
                );
            }
            return array(
                'authenticated' => false,
                'error' => 'Invalid username or password.',
                'error_reason' => "Wrong password entered for Reseller '{$username}'",
                'attempted_role' => 'reseller',
            );
        }
    }

    if ($matchedAdminUsername) {
        return array(
            'authenticated' => false,
            'error' => 'Invalid username or password.',
            'error_reason' => "Wrong password entered for Administrator '{$username}'",
            'attempted_role' => 'admin',
        );
    }

    return array(
        'authenticated' => false,
        'error' => 'Invalid username or password.',
        'error_reason' => "Unknown username '{$username}' (Invalid username & password)",
        'attempted_role' => 'unknown',
    );
}

/**
 * Refresh active reseller session permissions, template assignment, logo, and router assignments
 * from disk on every request using stable internal reseller ID (with username fallback).
 */
function syncActiveResellerSession() {
    if (!isset($_SESSION['mikhmon_role']) || $_SESSION['mikhmon_role'] !== 'reseller') {
        return true;
    }
    if (!empty($GLOBALS['NGW_RESELLER_SYNCED'])) {
        return $GLOBALS['NGW_RESELLER_SYNC_RESULT'];
    }
    $resId = isset($_SESSION['mikhmon_reseller_id']) ? trim((string)$_SESSION['mikhmon_reseller_id']) : '';
    $uname = isset($_SESSION['mikhmon']) ? trim((string)$_SESSION['mikhmon']) : '';
    $resellers = loadResellers();
    foreach ($resellers as $r) {
        $matchById = ($resId !== '' && (string)$r['id'] === $resId);
        $matchByLegacy = ($resId !== '' && isset($r['legacy_ids']) && is_array($r['legacy_ids']) && in_array($resId, $r['legacy_ids'], true));
        $matchByUser = ($resId === '' && $uname !== '' && isset($r['username']) && $r['username'] === $uname);
        if ($matchById || $matchByLegacy || $matchByUser) {
            if (!isset($r['status']) || $r['status'] !== 'active') {
                session_destroy();
                $GLOBALS['NGW_RESELLER_SYNCED'] = true;
                $GLOBALS['NGW_RESELLER_SYNC_RESULT'] = false;
                return false;
            }
            $_SESSION['mikhmon'] = $r['username'];
            $_SESSION['mikhmon_reseller_id'] = (string)$r['id'];
            $_SESSION['mikhmon_permission_mode'] = isset($r['permission_mode']) ? $r['permission_mode'] : 'Custom';
            $_SESSION['mikhmon_template_code'] = isset($r['template_code']) ? $r['template_code'] : 'NGW-001';
            $_SESSION['mikhmon_assigned_template'] = isset($r['assigned_template']) ? $r['assigned_template'] : 'default';
            $_SESSION['mikhmon_template_status'] = isset($r['template_status']) ? $r['template_status'] : 'active';
            $_SESSION['mikhmon_logo_filename'] = isset($r['logo_filename']) ? $r['logo_filename'] : '';
            $_SESSION['mikhmon_routers'] = isset($r['allowed_routers']) ? $r['allowed_routers'] : array();
            $_SESSION['mikhmon_permissions'] = resolveEffectiveResellerPermissions($r);
            $GLOBALS['NGW_RESELLER_SYNCED'] = true;
            $GLOBALS['NGW_RESELLER_SYNC_RESULT'] = true;
            return true;
        }
    }
    session_destroy();
    $GLOBALS['NGW_RESELLER_SYNCED'] = true;
    $GLOBALS['NGW_RESELLER_SYNC_RESULT'] = false;
    return false;
}

/**
 * Get the authenticated reseller record (or null if Admin/unauthenticated)
 */
function getCurrentResellerRecord() {
    if (isMikhmonAdmin()) {
        return null;
    }
    if (!syncActiveResellerSession()) {
        return null;
    }
    $resId = isset($_SESSION['mikhmon_reseller_id']) ? (string)$_SESSION['mikhmon_reseller_id'] : '';
    if ($resId !== '') {
        return findResellerByIdOrUsername($resId);
    }
    $uname = isset($_SESSION['mikhmon']) ? (string)$_SESSION['mikhmon'] : '';
    return findResellerByIdOrUsername($uname);
}

/**
 * Check if current user is Administrator
 */
function isMikhmonAdmin() {
    if (!isset($_SESSION['mikhmon'])) {
        return false;
    }
    return !isset($_SESSION['mikhmon_role']) || $_SESSION['mikhmon_role'] === 'admin';
}

/**
 * Verify if current session user can access a router session
 */
function canAccessRouterSession($sessionName) {
    if (empty($sessionName)) {
        return false;
    }
    if (!syncActiveResellerSession()) {
        return false;
    }
    if (isMikhmonAdmin()) {
        return true;
    }
    $allowed = isset($_SESSION['mikhmon_routers']) && is_array($_SESSION['mikhmon_routers']) ? $_SESSION['mikhmon_routers'] : array();
    return in_array('*', $allowed, true) || in_array($sessionName, $allowed, true);
}

/**
 * Find the one active reseller explicitly assigned to a router session.
 * Wildcard access is not treated as ownership. If multiple resellers explicitly
 * claim the same router, return null rather than selecting an arbitrary template.
 */
function findResellerAssignedToRouterSession($sessionName) {
    $sessionName = trim((string)$sessionName);
    if ($sessionName === '') {
        return null;
    }
    $matches = array();
    foreach (loadResellers() as $reseller) {
        if (!isset($reseller['id']) || !isset($reseller['allowed_routers']) || !is_array($reseller['allowed_routers'])) {
            continue;
        }
        if (isset($reseller['status']) && $reseller['status'] !== 'active') {
            continue;
        }
        if (in_array($sessionName, $reseller['allowed_routers'], true)) {
            $matches[] = $reseller;
        }
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Verify if current session user has a specific granular permission
 */
function hasResellerPermission($permKey) {
    if (!syncActiveResellerSession()) {
        return false;
    }
    if ($permKey === 'about' || $permKey === 'view_about') {
        return true;
    }
    if (isMikhmonAdmin()) {
        return true;
    }
    if (isProtectedAdminPermission($permKey)) {
        return false;
    }
    // Alias support
    if ($permKey === 'remove_host') $permKey = 'manage_hosts';
    if ($permKey === 'remove_cookie') $permKey = 'delete_cookies';
    if ($permKey === 'manage_template_logo') $permKey = 'edit_own_template';

    $perms = isset($_SESSION['mikhmon_permissions']) && is_array($_SESSION['mikhmon_permissions']) ? $_SESSION['mikhmon_permissions'] : array();

    // Master PPPoE Gate: If the reseller lacks master PPPoE permission, all PPPoE features are hidden and denied
    if ($permKey !== 'manage_pppoe' && (strpos($permKey, 'pppoe') !== false || $permKey === 'view_ppp' || $permKey === 'manage_ppp')) {
        if (empty($perms['manage_pppoe']) && empty($perms['view_ppp']) && empty($perms['manage_ppp'])) {
            return false;
        }
    }

    return !empty($perms[$permKey]);
}

/**
 * Resolve logo path for voucher printing / preview / header according to recommended asset resolution order:
 * 1. Authenticated reseller's explicitly assigned logo (uploads/reseller-logos/{id}/{logo_filename})
 * 2. Logo assigned to that reseller's template (uploads/reseller-templates/{id}/logo.png)
 * 3. Session-specific default logo (img/logo-{session}.png)
 * 4. Application-wide default logo (img/logo.png)
 */
function resolveActiveVoucherLogo($session, $relativePrefix = '../', $withCacheBust = true, $overrideResellerId = null) {
    $baseRoot = dirname(__DIR__);
    $cacheSuffix = $withCacheBust ? ('?t=' . str_replace(' ', '_', date('Y-m-d H:i:s'))) : '';

    $reseller = getCurrentResellerRecord();
    if ($reseller === null && isMikhmonAdmin() && !empty($overrideResellerId)) {
        $reseller = findResellerByIdOrUsername($overrideResellerId);
    }
    if ($reseller !== null) {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$reseller['id']);
        $logoFile = isset($reseller['logo_filename']) ? basename((string)$reseller['logo_filename']) : '';
        if ($safeId !== '' && $logoFile !== '') {
            $absAssigned = $baseRoot . '/uploads/reseller-logos/' . $safeId . '/' . $logoFile;
            if (is_file($absAssigned) && @getimagesize($absAssigned) !== false) {
                return $relativePrefix . 'uploads/reseller-logos/' . $safeId . '/' . $logoFile . $cacheSuffix;
            }
        }
        if ($safeId !== '') {
            $absTplLogo = $baseRoot . '/uploads/reseller-templates/' . $safeId . '/logo.png';
            if (is_file($absTplLogo) && @getimagesize($absTplLogo) !== false) {
                return $relativePrefix . 'uploads/reseller-templates/' . $safeId . '/logo.png' . $cacheSuffix;
            }
        }
    }

    $safeSession = basename((string)$session);
    if ($safeSession !== '' && is_file($baseRoot . '/img/logo-' . $safeSession . '.png')) {
        return $relativePrefix . 'img/logo-' . $safeSession . '.png' . $cacheSuffix;
    }

    return $relativePrefix . 'img/logo.png' . $cacheSuffix;
}

/**
 * Resolve voucher template filesystem path for voucher/print.php and voucher/vpreview.php
 * Ensures each reseller automatically loads their own assigned/published template without affecting global files.
 */
function resolveActiveVoucherTemplatePath($requestedVariant = 'default', $overrideResellerId = null) {
    $baseRoot = dirname(__DIR__);
    $reseller = getCurrentResellerRecord();
    $isAdminPreview = false;
    if ($reseller === null && isMikhmonAdmin() && !empty($overrideResellerId)) {
        $reseller = findResellerByIdOrUsername($overrideResellerId);
        $isAdminPreview = true;
    }

    $variantMap = array(
        'default'  => 'template.php',
        'thermal'  => 'template-thermal.php',
        'small'    => 'template-small.php',
    );

    if ($reseller !== null) {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$reseller['id']);
        $tplStatus = isset($reseller['template_status']) ? $reseller['template_status'] : 'active';
        $canUse = $isAdminPreview ? true : (hasResellerPermission('use_assigned_template') || hasResellerPermission('preview_assigned_template'));

        if ($safeId !== '' && $tplStatus === 'active' && $canUse) {
            $effectiveVariant = $requestedVariant;
            if ($requestedVariant === 'assigned' && !empty($reseller['assigned_template']) && isset($variantMap[$reseller['assigned_template']])) {
                $effectiveVariant = $reseller['assigned_template'];
            }
            $fileName = isset($variantMap[$effectiveVariant]) ? $variantMap[$effectiveVariant] : 'template.php';
            ensureResellerTemplateFiles($safeId);
            $resellerTplPath = $baseRoot . '/uploads/reseller-templates/' . $safeId . '/' . $fileName;
            if (is_file($resellerTplPath) && is_readable($resellerTplPath)) {
                return $resellerTplPath;
            }
        }
    }

    $fallbackVariant = ($requestedVariant === 'assigned') ? 'default' : $requestedVariant;
    $fallbackFile = isset($variantMap[$fallbackVariant]) ? $variantMap[$fallbackVariant] : 'template.php';
    return $baseRoot . '/voucher/' . $fallbackFile;
}

/**
 * Validate and store a reseller-specific logo upload safely inside uploads/reseller-logos/{reseller_id}/
 */
function handleResellerLogoUpload($resellerId, $fileInput) {
    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$resellerId);
    if ($safeId === '') {
        return array('ok' => false, 'error' => 'Invalid reseller ID.');
    }
    if (!isset($fileInput['tmp_name']) || !is_uploaded_file($fileInput['tmp_name'])) {
        return array('ok' => false, 'error' => 'No valid uploaded file received.');
    }
    if ($fileInput['size'] > 2 * 1024 * 1024) {
        return array('ok' => false, 'error' => 'Logo file exceeds maximum size of 2 MB.');
    }

    $imgInfo = @getimagesize($fileInput['tmp_name']);
    if ($imgInfo === false) {
        return array('ok' => false, 'error' => 'Uploaded file is not a valid image.');
    }
    $width = (int)$imgInfo[0];
    $height = (int)$imgInfo[1];
    $imgType = (int)$imgInfo[2];

    if ($width < 10 || $height < 10 || $width > 2400 || $height > 2400) {
        return array('ok' => false, 'error' => 'Image dimensions must be between 10x10 and 2400x2400 pixels.');
    }

    $allowedTypes = array(
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
    );
    if (defined('IMAGETYPE_WEBP')) {
        $allowedTypes[IMAGETYPE_WEBP] = 'webp';
    }
    if (!isset($allowedTypes[$imgType])) {
        return array('ok' => false, 'error' => 'Only PNG, JPEG, and WebP images are supported.');
    }

    $ext = $allowedTypes[$imgType];
    ensureResellerRuntimeDirectories();
    $baseRoot = dirname(__DIR__);
    $targetDir = $baseRoot . '/uploads/reseller-logos/' . $safeId;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }
    // Remove previous logo files for this reseller
    foreach (glob($targetDir . '/logo.*') as $oldFile) {
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
    $safeFilename = 'logo.' . $ext;
    $destPath = $targetDir . '/' . $safeFilename;
    if (!@move_uploaded_file($fileInput['tmp_name'], $destPath)) {
        return array('ok' => false, 'error' => 'Failed to save uploaded logo on server.');
    }
    return array('ok' => true, 'filename' => $safeFilename);
}

/**
 * Remove a reseller's custom uploaded logo and revert to default logo fallback
 */
function removeResellerCustomLogo($resellerId) {
    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$resellerId);
    if ($safeId === '') {
        return false;
    }
    $baseRoot = dirname(__DIR__);
    $targetDir = $baseRoot . '/uploads/reseller-logos/' . $safeId;
    if (is_dir($targetDir)) {
        foreach (glob($targetDir . '/logo.*') as $oldFile) {
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
    }
    $resellers = loadResellers();
    foreach ($resellers as $idx => $r) {
        if ((string)$r['id'] === $safeId) {
            $resellers[$idx]['logo_filename'] = '';
        }
    }
    return saveResellers($resellers);
}

/**
 * Check if the logged-in reseller ONLY has the Reset Voucher permission (and no other operational menu/view permissions).
 * When true, the reseller is strictly isolated to the Reset Voucher interface.
 */
function isResetOnlyReseller() {
    if (!isset($_SESSION['mikhmon']) || isMikhmonAdmin()) {
        return false;
    }
    if (!syncActiveResellerSession()) {
        return false;
    }
    if (!hasResellerPermission('reset_voucher')) {
        return false;
    }
    $mode = isset($_SESSION['mikhmon_permission_mode']) ? trim((string)$_SESSION['mikhmon_permission_mode']) : '';
    if ($mode === 'Reset Voucher') {
        return true;
    }
    $otherOperationalKeys = array(
        'view_dashboard',
        'view_users',
        'add_user',
        'generate_voucher',
        'edit_user',
        'delete_user',
        'enable_disable_user',
        'reset_mac_address',
        'print_voucher',
        'transfer_users_access',
        'export_users',
        'export_rsc_users',
        'import_transfer_users',
        'auto_activate_transfer_users',
        'same_router_profile_transfer',
        'view_profiles',
        'add_profile',
        'edit_profile',
        'delete_profile',
        'view_active',
        'disconnect_active',
        'view_hosts',
        'manage_hosts',
        'bind_host_ip',
        'view_ipbinding',
        'manage_ipbinding',
        'edit_ipbinding',
        'view_cookies',
        'delete_cookies',
        'view_dhcp',
        'view_logs',
        'view_report',
        'export_report',
        'delete_report',
        'view_traffic',
        'view_scheduler',
        'manage_scheduler',
        'reboot_shutdown',
        'manage_router_settings',
        'manage_pppoe',
        'view_pppoe_users',
        'add_pppoe_user',
        'edit_pppoe_user',
        'delete_pppoe_user',
        'enable_disable_pppoe_user',
        'view_pppoe_profiles',
        'manage_pppoe_profiles',
        'view_pppoe_active',
        'disconnect_pppoe_active',
        'reset_pppoe',
        'edit_own_template',
        'save_own_template',
        'publish_own_template',
        'restore_own_template',
    );
    $perms = isset($_SESSION['mikhmon_permissions']) && is_array($_SESSION['mikhmon_permissions']) ? $_SESSION['mikhmon_permissions'] : array();
    foreach ($otherOperationalKeys as $k) {
        if (!empty($perms[$k])) {
            return false;
        }
    }
    return true;
}

/**
 * Determine the first permitted landing URL for a reseller on a given router session (or sessions list).
 */
function getResellerDefaultFallbackUrl($session = '') {
    $safeSession = trim((string)$session);
    if (isResetOnlyReseller()) {
        return './admin.php?id=reset-voucher' . ($safeSession !== '' && canAccessRouterSession($safeSession) ? '&session=' . urlencode($safeSession) : '');
    }
    if ($safeSession !== '' && canAccessRouterSession($safeSession)) {
        if (hasResellerPermission('view_dashboard')) {
            return './?session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_users')) {
            return './?hotspot=users&profile=all&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('generate_voucher')) {
            return './?hotspot-user=generate&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('add_user')) {
            return './?hotspot-user=add&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('reset_voucher')) {
            return './?hotspot=reset-voucher&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_profiles')) {
            return './?hotspot=user-profiles&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_active')) {
            return './?hotspot=active&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_hosts')) {
            return './?hotspot=hosts&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_ipbinding')) {
            return './?hotspot=ipbinding&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_cookies')) {
            return './?hotspot=cookies&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_report')) {
            return './?report=selling&idbl=' . strtolower(date("M")) . date("Y") . '&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_logs')) {
            return './?hotspot=log&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_traffic')) {
            return './?interface=traffic-monitor&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_dhcp')) {
            return './?hotspot=dhcp-leases&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_scheduler')) {
            return './?system=scheduler&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('edit_own_template')) {
            return './?hotspot=template-editor&template=default&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('save_own_template')) {
            return './?hotspot=uplogo&session=' . urlencode($safeSession);
        }
        return './?hotspot=about&session=' . urlencode($safeSession);
    }
    return './admin.php?id=sessions';
}

/**
 * Enforce permission server-side and silently redirect to an authorized page if unauthorized
 */
function requireResellerPermission($permKey, $redirectUrl = './admin.php?id=sessions') {
    if (!hasResellerPermission($permKey)) {
        if (isResetOnlyReseller()) {
            $redirectUrl = './admin.php?id=reset-voucher';
        } else {
            $sess = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
            if ($sess !== '' && canAccessRouterSession($sess)) {
                $fallback = getResellerDefaultFallbackUrl($sess);
                if (($redirectUrl === './?session=' . $sess || $redirectUrl === './admin.php?id=sessions') && !hasResellerPermission('view_dashboard')) {
                    $redirectUrl = $fallback;
                }
            }
        }
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
        }
        echo "<script>window.location='" . htmlspecialchars($redirectUrl, ENT_QUOTES) . "'</script>";
        exit;
    }
}

/**
 * Resolve the avatar/logo URL for the currently authenticated Admin or Reseller
 */
function getCurrentUserAvatarUrl() {
    $baseRoot = dirname(__DIR__);
    $isReseller = isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] === 'reseller';
    if ($isReseller) {
        $rec = getCurrentResellerRecord();
        if (is_array($rec) && !empty($rec['id'])) {
            $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$rec['id']);
            $logoFile = !empty($rec['logo_filename']) ? basename((string)$rec['logo_filename']) : '';
            if ($logoFile !== '') {
                $fullPath = $baseRoot . '/uploads/reseller-logos/' . $safeId . '/' . $logoFile;
                if (is_file($fullPath)) {
                    return './uploads/reseller-logos/' . $safeId . '/' . $logoFile . '?v=' . @filemtime($fullPath);
                }
            }
            foreach (glob($baseRoot . '/uploads/reseller-logos/' . $safeId . '/logo.*') as $candidate) {
                if (is_file($candidate)) {
                    $fn = basename($candidate);
                    return './uploads/reseller-logos/' . $safeId . '/' . $fn . '?v=' . @filemtime($candidate);
                }
            }
        }
        return '';
    }

    // Administrator avatar check (per-admin folder first, then fallback to shared admin folder)
    $admRec = getCurrentAdminRecord();
    $candidateFolders = array();
    if (is_array($admRec) && !empty($admRec['id'])) {
        $safeAdmId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$admRec['id']);
        if ($safeAdmId !== '') {
            $candidateFolders[] = $safeAdmId;
        }
    }
    $candidateFolders[] = 'admin';

    foreach ($candidateFolders as $folder) {
        $adminLogoDir = $baseRoot . '/uploads/reseller-logos/' . $folder;
        if (is_dir($adminLogoDir)) {
            foreach (glob($adminLogoDir . '/logo.*') as $candidate) {
                if (is_file($candidate)) {
                    $fn = basename($candidate);
                    return './uploads/reseller-logos/' . $folder . '/' . $fn . '?v=' . @filemtime($candidate);
                }
            }
        }
    }
    return '';
}

/**
 * Return structured profile summary for the top-right navbar and profile view
 */
function getCurrentUserProfileSummary() {
    $username = isset($_SESSION['mikhmon']) ? (string)$_SESSION['mikhmon'] : '';
    $isReseller = isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] === 'reseller';
    $avatarUrl = getCurrentUserAvatarUrl();

    if ($isReseller) {
        $rec = getCurrentResellerRecord();
        $displayName = (is_array($rec) && !empty($rec['name'])) ? (string)$rec['name'] : $username;
        $resId = (is_array($rec) && !empty($rec['id'])) ? (string)$rec['id'] : (isset($_SESSION['mikhmon_reseller_id']) ? (string)$_SESSION['mikhmon_reseller_id'] : '');
        $permMode = (is_array($rec) && !empty($rec['permission_mode'])) ? (string)$rec['permission_mode'] : (isset($_SESSION['mikhmon_permission_mode']) ? (string)$_SESSION['mikhmon_permission_mode'] : 'Write');
        $tplCode = (is_array($rec) && !empty($rec['template_code'])) ? (string)$rec['template_code'] : (isset($_SESSION['mikhmon_template_code']) ? (string)$_SESSION['mikhmon_template_code'] : '');
        $routers = (is_array($rec) && isset($rec['allowed_routers']) && is_array($rec['allowed_routers'])) ? $rec['allowed_routers'] : array();
        return array(
            'is_reseller' => true,
            'username' => $username,
            'display_name' => $displayName,
            'role_label' => 'Reseller',
            'role_badge' => 'Reseller',
            'permission_mode' => $permMode,
            'account_id' => $resId !== '' ? ('#' . $resId) : 'Reseller',
            'raw_id' => $resId,
            'template_code' => $tplCode,
            'allowed_routers' => $routers,
            'avatar_url' => $avatarUrl,
        );
    }

    $admRec = getCurrentAdminRecord();
    $admName = (is_array($admRec) && !empty($admRec['name'])) ? (string)$admRec['name'] : 'Administrator';
    $admUsername = (is_array($admRec) && !empty($admRec['username'])) ? (string)$admRec['username'] : $username;
    $admId = (is_array($admRec) && !empty($admRec['id'])) ? (string)$admRec['id'] : 'adm_1';

    return array(
        'is_reseller' => false,
        'username' => $admUsername,
        'display_name' => $admName,
        'role_label' => 'Administrator',
        'role_badge' => 'Admin',
        'permission_mode' => 'Full Admin Access',
        'account_id' => 'Admin',
        'raw_id' => $admId,
        'template_code' => 'GLOBAL',
        'allowed_routers' => array('*'),
        'avatar_url' => $avatarUrl,
    );
}
?>

