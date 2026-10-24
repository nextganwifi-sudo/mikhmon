# রিসেলার পারমিশন অনুযায়ী সম্পূর্ণ সেকশন ও বাটন হাইড প্ল্যান (Complete Permission-Based UI Hiding & Silent Redirect)

আপনার নির্দেশনা অনুযায়ী, কোনো রিসেলারকে যেসব বিষয়ের পারমিশন দেওয়া নেই, তার সামনে থেকে সেই পুরো সেকশন, বাম পাশের মেনু, ড্যাশবোর্ডের কার্ড/বক্স এবং ভেতরের বাটনগুলো **সম্পূর্ণ হাইড (Hide)** থাকবে এবং কেউ সরাসরি লিংকে ঢুকতে চাইলে কোনো এরর না দেখিয়ে **অটোমেটিক অনুমোদিত পেজে (Dashboard / Router List)** ফেরত পাঠাবে।

---

## ১. বাম পাশের সাইডবার মেনু (Sidebar Navigation — `include/menu.php`)
- **Hotspot ও Settings ড্রপডাউন:** যদি কোনো রিসেলারের `Hotspot`-এর ভেতরের কোনো অপশনই (Users, Profiles, Active, Hosts, IP Bindings, Cookies) পারমিশন না থাকে, তবে পুরো **Hotspot** ড্রপডাউন মেনুটিই হাইড থাকবে।
- **Template Editor ও Upload Logo:** রিসেলারের যদি `edit_own_template` বা `save_own_template` পারমিশন না থাকে, তবে সাইডবারে `Template Editor` এবং `Upload Logo` মেনু দুটি সম্পূর্ণ হাইড থাকবে।
- **System, Log, Report, Traffic, DHCP, Quick Print, Vouchers:** যেটির পারমিশন নেই সেটি সাইডবারে দেখাবেই না।

---

## ২. ড্যাশবোর্ডের বক্স ও কার্ড হাইড (`dashboard/home.php` ও `dashboard/aload.php`)
ড্যাশবোর্ডে থাকা প্রতিটি কার্ড ও শর্টকাট বক্স রিসেলারের পারমিশন অনুযায়ী হাইড থাকবে:
- **Hotspot Active বক্স:** শুধুমাত্র `view_active` পারমিশন থাকলে দেখাবে।
- **Hotspot Users বক্স:** শুধুমাত্র `view_users` পারমিশন থাকলে দেখাবে।
- **Add User বক্স:** শুধুমাত্র `add_user` পারমিশন থাকলে দেখাবে।
- **Generate Voucher বক্স:** শুধুমাত্র `generate_voucher` পারমিশন থাকলে দেখাবে। (যদি এই ৪টির কোনোটিরই পারমিশন না থাকে, তবে পুরো Hotspot শর্টকাট কার্ডটি হাইড থাকবে)।
- **Traffic Monitor গ্রাফ কার্ড:** শুধুমাত্র `view_traffic` পারমিশন থাকলে দেখাবে।
- **Live Selling Report বক্স:** শুধুমাত্র `view_report` পারমিশন থাকলে দেখাবে।
- **Hotspot Log কার্ড:** শুধুমাত্র `view_logs` পারমিশন থাকলে দেখাবে।

---

## ৩. পেজের ভেতরের অ্যাকশন বাটন হাইড (In-Page Action Buttons)
রিসেলার যদি কোনো পেজ দেখার (`view_*`) পারমিশন পায় কিন্তু এডিট/ডিলিট/অ্যাড করার পারমিশন না পায়, তবে পেজের ভেতরের নিচের বাটনগুলো হাইড থাকবে:
- **Users ও Vouchers (`hotspot/users.php`, `hotspot/usersbyprofile.php`, `hotspot/userbyname.php`):**
  - `add_user` না থাকলে `+ Add` বাটন হাইড।
  - `generate_voucher` না থাকলে `Generate` বাটন হাইড।
  - `export_users` না থাকলে `Script` ও `CSV` ডাউনলোড বাটন হাইড।
  - `print_voucher` না থাকলে `Default / QR / Small` প্রিন্ট বাটনগুলো হাইড।
  - `delete_user` না থাকলে নামের পাশের ডিলিট (`fa-minus-square` / `Remove`), `Remove by Comment`, এবং `Expired Users` ডিলিট বাটন হাইড।
  - `enable_disable_user` না থাকলে লক/আনলক (`Enable/Disable`) ও `Reset` বাটন হাইড।
  - `edit_user` না থাকলে ইউজারের তথ্য সেভ (`Save`) বাটন ও এডিট লিংক হাইড।
- **User Profiles (`hotspot/userprofile.php`, `hotspot/userprofilebyname.php`):**
  - `add_profile` না থাকলে `+ Add` বাটন হাইড।
  - `delete_profile` না থাকলে ডিলিট আইকন হাইড।
  - `edit_profile` না থাকলে `Sync ROS6/ROS7 Expiry` এবং প্রোফাইল এডিট/সেভ বাটন হাইড।
- **Active, Hosts, IP Binding, Cookies, Scheduler, Selling Report:**
  - `disconnect_active`, `manage_hosts`, `manage_ipbinding`, `delete_cookies`, `manage_scheduler`, `delete_report`, `export_report` পারমিশন না থাকলে সংশ্লিষ্ট ডিলিট/অ্যাড/এক্সপোর্ট বাটনগুলো সম্পূর্ণ হাইড থাকবে।

---

## ৪. সাইলেন্ট অটোমেটিক রিডাইরেক্ট (`include/reseller_acl.php`, `index.php`, `admin.php`)
- রিসেলার যদি ব্রাউজারের অ্যাড্রেস বারে লিখে অনুমোদনহীন কোনো সেকশনে ঢুকতে চায়, তবে কোনো লাল রঙের `Access Denied` পেজ না দেখিয়ে সাথে সাথে সাইলেন্টলি তাকে অনুমোদিত ড্যাশবোর্ড (`./?session=...`) অথবা রাউটার লিস্টে (`./admin.php?id=sessions`) ফেরত পাঠাবে।
- সব পরিবর্তন শেষে নতুন **`Mikhmon-Server-NextGan-Complete.zip`** ফাইলেও এটি অটোমেটিক যুক্ত হয়ে যাবে।
