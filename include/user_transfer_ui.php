<?php
// Text and language selection scoped to User Transfer (isolated per user).
include_once __DIR__ . '/lang.php';
if (isset($_GET['lang']) && in_array($_GET['lang'], array('en', 'bn'), true)) {
    $langid = ngwSaveCurrentUserLang($_GET['lang']);
} elseif (isset($_GET['setlang']) && in_array($_GET['setlang'], array('en', 'bn'), true)) {
    $langid = ngwSaveCurrentUserLang($_GET['setlang']);
}
$transferLanguage = isset($langid) ? $langid : 'en';
$transferStrings = $transferLanguage === 'bn' ? array(
    'Ready.' => 'প্রস্তুত।',
    'User Transfer' => 'ইউজার ট্রান্সফার (রাউটার টু রাউটার)',
    'Back to router' => 'রাউটারে ফিরে যান',
    'Same-Router Profile Transfer' => 'একই রাউটারে প্রোফাইল ট্রান্সফার',
    'Export users' => 'ইউজার এক্সপোর্ট (ডাউনলোড)',
    'Import users' => 'ইউজার ইমপোর্ট (আপলোড)',
    'Destination OS' => 'গন্তব্য রাউটারের OS (OS 6 ও OS 7 উভয়ই সমর্থিত)',
    'Filter users' => 'ইউজার ফিল্টার',
    'All Hotspot users' => 'সব হটস্পট ইউজার',
    'Batch — exact comment' => 'ব্যাচ — সম্পূর্ণ কমেন্ট',
    'Specific profile' => 'নির্দিষ্ট প্রোফাইল',
    'Batch comment or profile name' => 'ব্যাচের কমেন্ট অথবা প্রোফাইলের নাম',
    'Download file' => 'ফাইল ডাউনলোড (.json)',
    'Transfer file' => 'ট্রান্সফার ফাইল (.json)',
    'Destination profile' => 'গন্তব্য প্রোফাইল',
    'Keep original profile (if exists on router)' => 'আগের প্রোফাইল বহাল রাখুন (রাউটারে থাকলে)',
    'Auto-login used vouchers into Active & Cookie (MAC Lock + Uptime/Expiry preserved, no IP Binding)' => 'ব্যবহৃত ভাউচার অটো-লগইন (IP বাইন্ডিং ছাড়াই কুকি, আপটাইম ও মেয়াদসহ সরাসরি Active-এ আসবে)',
    'Hotspot server' => 'হটস্পট সার্ভার',
    'All servers' => 'সব সার্ভার',
    'Validate file' => 'ফাইল যাচাই করুন',
    'Start import' => 'ইমপোর্ট শুরু করুন',
    'Resume import' => 'ইমপোর্ট চালিয়ে যান',
    'Loading router data…' => 'রাউটারের তথ্য লোড হচ্ছে…',
    'Reload this page.' => 'পেজটি আবার লোড করুন।',
    'Router not found.' => 'রাউটার পাওয়া যায়নি।',
    'Router connection failed.' => 'রাউটারের সঙ্গে সংযোগ হয়নি।',
    'Router rejected or interrupted the request. Check users before retrying.' => 'রাউটার অনুরোধ প্রত্যাখ্যান করেছে বা সংযোগ বিচ্ছিন্ন হয়েছে। আবার চেষ্টা করার আগে ইউজার তালিকা দেখুন।',
    'Select destination OS.' => 'গন্তব্য OS বাছুন।',
    'Invalid filter.' => 'ফিল্টার সঠিক নয়।',
    'Enter a profile or exact batch comment.' => 'প্রোফাইল অথবা ব্যাচের সম্পূর্ণ কমেন্ট দিন।',
    'Choose a transfer file smaller than 10 MB.' => '১০ MB-এর ছোট ট্রান্সফার ফাইল বাছুন।',
    'Invalid transfer file.' => 'ট্রান্সফার ফাইল সঠিক নয়।',
    'Select an existing destination profile.' => 'গন্তব্যের বিদ্যমান প্রোফাইল বাছুন।',
    'Destination server not found.' => 'গন্তব্য সার্ভার পাওয়া যায়নি।',
    'Invalid user row.' => 'ইউজারের তথ্য সঠিক নয়।',
    'Duplicate username in file.' => 'ফাইলে একই ইউজারনেম একাধিকবার রয়েছে।',
    'Import job not found.' => 'ইমপোর্টের কাজ পাওয়া যায়নি।',
    'Unknown action.' => 'অজানা অনুরোধ।',
    'Validated. Existing usernames will be skipped.' => 'যাচাই সম্পন্ন। আগে থেকে থাকা ইউজারনেম বাদ যাবে।',
    'Access denied' => 'অনুমতি নেই',
    'Session expired. Sign in again.' => 'সেশনের মেয়াদ শেষ। আবার লগইন করুন।',
    'Response interrupted. Resume the same import or retry export.' => 'উত্তর পাওয়া যায়নি। একই ইমপোর্ট চালিয়ে যান অথবা আবার এক্সপোর্ট করুন।',
    'Request failed' => 'অনুরোধ ব্যর্থ হয়েছে',
    'Exporting…' => 'এক্সপোর্ট হচ্ছে…',
    'users exported.' => 'জন ইউজার এক্সপোর্ট হয়েছে।',
    'users.' => 'জন ইউজার।',
    'processed;' => 'প্রক্রিয়া হয়েছে;',
    'added;' => 'যোগ হয়েছে;',
    'auto-activated;' => 'অটো-অ্যাক্টিভ হয়েছে;',
    'skipped.' => 'বাদ গেছে।',
    'Import complete.' => 'ইমপোর্ট সম্পন্ন।',
    'Missing name; export requires password access.' => 'ইউজারনেম পাওয়া যায়নি।',
    'Invalid username.' => 'ইউজারনেম সঠিক নয়।',
) : array();
function transferText($text) { global $transferStrings; return $transferStrings[$text] ?? $text; }
function transferHtml($text) { return htmlspecialchars(transferText($text), ENT_QUOTES, 'UTF-8'); }
