<?php
/*
 *  NextGan WiFi Solution
 *  Hotspot & Reseller Management System for MikroTik RouterOS (v6 & v7)
 */
session_start();
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
}
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-info-circle"></i> About NextGan WiFi Solution</h3>
      </div>
      <div class="card-body">
        <div style="display:flex; align-items:center; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
          <img src="./img/favicon.png?v=nextgan" alt="NextGan WiFi Solution" style="width:72px; height:72px; border-radius:50%; object-fit:cover; border:2px solid #20a8d8;" />
          <div>
            <h3 style="margin:0 0 5px 0; font-weight:bold;">NextGan WiFi Solution</h3>
            <span style="font-size:13px; opacity:0.85;">Advanced MikroTik Hotspot, Voucher &amp; Multi-Reseller Management System (V<?= htmlspecialchars(isset($_SESSION['v']) ? $_SESSION['v'] : '3.20'); ?>)</span>
          </div>
        </div>
        <p>
          <b>NextGan WiFi Solution</b> হলো আধুনিক ও পূর্ণাঙ্গ MikroTik Hotspot এবং WiFi Voucher ম্যানেজমেন্ট সফটওয়্যার। এটি ছোট-বড় সকল ওয়াইফাই হটস্পট ব্যবসায়ী এবং আইএসপি (ISP) উদ্যোক্তাদের জন্য বিশেষভাবে তৈরি করা হয়েছে।
        </p>
        <p>
          <ul>
            <li><b>Software Name :</b> NextGan WiFi Solution</li>
            <li><b>Edition :</b> Enterprise Hotspot &amp; Reseller Edition (V<?= htmlspecialchars(isset($_SESSION['v']) ? $_SESSION['v'] : '3.20'); ?>)</li>
            <li><b>RouterOS Compatibility :</b> MikroTik RouterOS v6.x &amp; v7.x Dual-Stack Support</li>
            <li><b>Core Engine :</b> RouterOS API with Universal Expiration &amp; Sales Reporting</li>
            <li><b>Platform :</b> Standalone Windows Server &amp; Web Hosting Ready</li>
          </ul>
        </p>
        <p>
          <b>NextGan WiFi Solution</b> ব্যবহার করার জন্য আপনাকে অসংখ্য ধন্যবাদ। আপনার হটস্পট ব্যবসার উত্তরোত্তর সাফল্য কামনা করি।
        </p>
        <div style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(150,150,150,0.2);">
          <i>Copyright &copy; <?= date('Y'); ?> <b>NextGan WiFi Solution</b>. All Rights Reserved.</i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-star"></i> NextGan WiFi Solution — Key Features &amp; Capabilities</h3>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-6">
            <h4 style="margin-top:0;"><i class="fa fa-check-circle text-green"></i> Hotspot &amp; RouterOS Management</h4>
            <ul>
              <li><b>RouterOS v6 &amp; v7 Universal Support:</b> স্বয়ংক্রিয়ভাবে RouterOS v6 এবং v7 ডিটেক্ট করে ইউজার এক্সপায়ারেশন ও সেলস রিপোর্ট স্ক্রিপ্ট পরিচালনা করে।</li>
              <li><b>Fast Voucher Generation &amp; Print:</b> এক ক্লিকে শত শত হটস্পট ভাউচার তৈরি, QR Code সহ প্রিন্ট, কাস্টম ও থার্মাল (Small) প্রিন্টিং সুবিধা।</li>
              <li><b>Real-Time Traffic &amp; Active Monitor:</b> লাইভ ইন্টারফেস ট্রাফিক গ্রাফ, অ্যাক্টিভ ইউজার মনিটরিং, হোস্ট এবং আইপি বাইন্ডিং কন্ট্রোল।</li>
              <li><b>Automated Selling Report:</b> দৈনিক ও মাসিক ভাউচার বিক্রির পূর্ণাঙ্গ হিসাব এবং CSV রিপোর্ট এক্সপোর্ট।</li>
            </ul>
          </div>
          <div class="col-6">
            <h4 style="margin-top:0;"><i class="fa fa-shield text-blue"></i> Advanced Multi-Reseller &amp; Security</h4>
            <ul>
              <li><b>Granular Reseller Permission System:</b> প্রতিটি রিসেলারের জন্য আলাদা পারমিশন গ্রুপ অথবা কাস্টম পারমিশন নির্ধারণ করার সুবিধা।</li>
              <li><b>Smart UI Auto-Hiding:</b> রিসেলারের যে অপশনগুলোর পারমিশন দেওয়া নেই, সাইডবার মেনু, ড্যাশবোর্ড কার্ড এবং ভেতরের অ্যাকশন বাটন থেকে সেগুলো স্বয়ংক্রিয়ভাবে হাইড থাকে।</li>
              <li><b>Reseller Custom Voucher &amp; Logo:</b> প্রতিটি রিসেলারের জন্য নিজস্ব ভাউচার টেমপ্লেট ও কাস্টম লোগো অ্যাসাইন করার সুবিধা।</li>
              <li><b>1-Click Standalone Server Package:</b> সরাসরি অ্যাডমিন প্যানেল থেকে রেডিমেড পোর্টেবল Windows Server জিপ ডাউনলোড ও রান করার সুবিধা।</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
