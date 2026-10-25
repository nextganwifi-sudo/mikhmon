# Production Reliability Audit & Permanent Fix Plan — NextGan WiFi Solution (Mikhmon)

This plan provides a root-cause analysis and permanent engineering fix for the intermittent `504 Gateway Time-out` / `upstream timed out` incidents on **NextGan WiFi Solution** (`https://voucher.nextganwifi.xyz`, Ubuntu 24.04 VPS), where both Node.js (`:3000`) and PHP (`:8081`) remain alive in `systemd` (`NRestarts=0`, low RAM usage, no OOM events) yet stop responding to HTTP requests until `mikhmon.service` is manually restarted.

> [!IMPORTANT]
> **Critical Root-Cause Discovery (Verified in Repository Code)**
> Inspection of the application supervisor and reverse proxy revealed **four interacting defects**—most notably a **deterministic 64 KB Linux kernel pipe buffer deadlock** on the PHP child process's `stderr` stream and a **single-threaded PHP worker queue without request timeouts**—that cause the exact freeze pattern observed on your VPS.

---

## 1. Executive Summary of the Current Situation

Your Ubuntu 24.04 VPS has ample free memory (`~1.4 GiB` available out of `1.9 GiB`, zero OOM kills) and healthy Nginx/SSL configuration, yet the web application periodically enters a **"zombie-hang" state**:
- Both the Node.js supervisor process (`:3000`) and the PHP backend process (`:8081`) remain running with valid PIDs.
- Because neither process exits, `systemd` sees `NRestarts=0` and never triggers `Restart=always`.
- Local requests to `127.0.0.1:8081` and `127.0.0.1:3000` hang indefinitely until Nginx times out with `504 Gateway Time-out`.
- Restarting `mikhmon.service` flushes the hung child process and restores sub-second response times (`~0.64s` TTFB) immediately.

---

## 2. Verified Facts vs. Root Causes

### Verified Facts from VPS & Codebase Audit
1. **Sufficient Hardware & Memory**: `492 MiB` used / `1.4 GiB` available RAM, `0` OOM kills or segfaults in kernel logs. Hardware exhaustion is **ruled out**.
2. **Process Survival During Outage**: `NRestarts=0` proves Node.js and PHP did not crash; they deadlocked while staying alive.
3. **Architecture**: Nginx (`:443`) $\rightarrow$ Node.js Express proxy (`127.0.0.1:3000`) $\rightarrow$ PHP built-in CLI server (`127.0.0.1:8081`).

### Verified Root Causes in Code (Ranked by Severity)

| # | Root Cause | Evidence in Codebase | Why It Causes Your Exact Symptoms |
| :--- | :--- | :--- | :--- |
| **RC-1 (Primary Smoking Gun)** | **Unconsumed `stdout`/`stderr` OS Pipe Buffer Deadlock** | Node.js spawns `php -S 127.0.0.1:8081` with `stdio: ['ignore', 'pipe', 'pipe']`, **but attaches zero `.on('data')` listeners** to `phpProcess.stdout` or `phpProcess.stderr`. | PHP's built-in server writes an access log line to `stderr` on **every HTTP request**. On Ubuntu 24.04, the Linux kernel pipe buffer is **64 KB (`65,536` bytes)**. After ~600–900 requests (roughly 10–15 minutes of dashboard & live CPU/RAM polling), the unread `stderr` pipe fills to 64 KB. The next HTTP request causes `php` to block indefinitely inside the kernel `write(2)` syscall waiting for Node.js to read `stderr`. Both processes stay alive (`NRestarts=0`, 0% CPU), and ports `8081` and `3000` freeze completely. |
| **RC-2 (Critical)** | **Single-Threaded PHP Built-in Server (`PHP_CLI_SERVER_WORKERS` Unset)** | Node.js spawns `php -S 127.0.0.1:8081` without `PHP_CLI_SERVER_WORKERS` and without `-d max_execution_time`. | By default, `php -S` runs a **single worker process** handling 1 request at a time in a strict FIFO queue. If any single request blocks on a slow/unreachable MikroTik RouterOS socket or session lock, **every subsequent request to `:8081` queues behind it and times out**. |
| **RC-3 (High)** | **Unbounded Node.js Proxy Requests & No Active Health Watchdog** | The Node.js `http.request` proxy to `:8081` has no `.setTimeout()`, and Node.js only restarts PHP on process `'exit'`, never when `:8081` stops responding. | When `:8081` hangs, Node.js (`:3000`) holds incoming Nginx connections open forever instead of failing fast and automatically recycling the stuck PHP process. |
| **RC-4 (Medium)** | **Orphaned PHP Processes on Service Restart & Missing Session Unlocks** | Node.js lacks `SIGTERM`/`SIGINT` handlers to kill `phpProcess` cleanly on stop, and `admin.php` (`id=connect`) + `status/ping-test.php` still hold PHP session locks during network socket calls. | If Node.js is restarted abruptly, an orphaned `php` process can hold `:8081`, causing `connect() failed (111: Connection refused)` or restart loops. Session locks block concurrent AJAX requests from the same browser session. |

---

## 3. Root-Cause Investigation & Architecture Diagram

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                        CURRENT FAILURE MECHANISM                             │
├──────────────────────────────────────────────────────────────────────────────┤
│  Browser / Nginx (:443)                                                      │
│         │                                                                    │
│         ▼                                                                    │
│  Node.js Supervisor & Proxy (127.0.0.1:3000)                                 │
│    • http.request() has NO timeout -> hangs waiting for :8081                │
│    • stdio: ['ignore', 'pipe', 'pipe'] NEVER drains phpProcess.stderr!       │
│         │                                                                    │
│         ▼  (64 KB Linux pipe buffer fills up after ~700 requests)            │
│  PHP Built-in Server (127.0.0.1:8081)                                        │
│    • Blocks forever on write(2) to stderr!                                   │
│    • Single worker (no PHP_CLI_SERVER_WORKERS) blocks entire queue!          │
└──────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────┐
│                     PROPOSED SELF-HEALING ARCHITECTURE                       │
├──────────────────────────────────────────────────────────────────────────────┤
│  systemd (mikhmon.service: KillMode=control-group, Watchdog / Restart=always)│
│         │                                                                    │
│         ▼                                                                    │
│  Node.js Supervisor & Proxy (127.0.0.1:3000)                                 │
│    • Drains/ignores php stdout & stderr pipes (Zero 64KB Pipe Deadlock)      │
│    • 15s Proxy Request Timeout + Clean 502/504 JSON/HTML Fallback            │
│    • Internal Active Watchdog: probes 127.0.0.1:8081 every 10s               │
│      (3 consecutive probe failures -> SIGKILL & clean respawn of PHP)        │
│    • Exposes GET /__health (verifies both Node :3000 & PHP :8081 liveness)   │
│         │                                                                    │
│         ▼                                                                    │
│  Multi-Worker PHP Server (127.0.0.1:8081)                                    │
│    • PHP_CLI_SERVER_WORKERS=6 (6 concurrent requests, no FIFO head-of-line)  │
│    • -d max_execution_time=25 -d default_socket_timeout=5                    │
│    • Early session_write_close() before RouterOS / ping network I/O          │
└──────────────────────────────────────────────────────────────────────────────┘
```

### Exact Components Inspected
- **Node.js Process Supervisor & Reverse Proxy**: `startPhpServer()` child process spawn options (`stdio` configuration, environment variables, PHP INI flags), `http.request` proxy lifecycle, and signal termination hooks.
- **PHP Session & Socket Concurrency**: Router connection handler (`id=connect`), router TCP ping tester, and `RouterosAPI` socket read loop.

---

## 4. Proposed `systemd` Reliability Improvements

On your Ubuntu 24.04 VPS (`/etc/systemd/system/mikhmon.service`), update the unit configuration so `systemd` cleanly manages the entire process tree (both Node.js and all forked PHP worker processes) and never gets stuck in start-limit lockouts:

```ini
[Unit]
Description=NextGan WiFi Solution (Mikhmon Node.js + PHP Backend)
After=network-online.target
Wants=network-online.target
StartLimitIntervalSec=300
StartLimitBurst=10

[Service]
Type=simple
User=root
WorkingDirectory=/root/apps/mikhmon
Environment=NODE_ENV=production
Environment=PHP_CLI_SERVER_WORKERS=6
ExecStartPre=-/usr/bin/fuser -k 8081/tcp
ExecStart=/usr/bin/npm start
Restart=always
RestartSec=3
TimeoutStopSec=15
KillMode=control-group
KillSignal=SIGTERM
StandardOutput=journal
StandardError=journal
SyslogIdentifier=mikhmon

[Install]
WantedBy=multi-user.target
```

### Why These `systemd` Changes Matter
- **`KillMode=control-group` & `ExecStartPre=-/usr/bin/fuser -k 8081/tcp`**: Guarantees that stopping or restarting `mikhmon.service` terminates **every** child PHP worker and frees port `8081` before starting a fresh instance.
- **`StartLimitIntervalSec=300` & `StartLimitBurst=10`**: Prevents `systemd` from permanently disabling the service if a transient burst of restarts occurs.

---

## 5. Proposed Node.js / PHP Health-Check & Auto-Recovery Design

1. **Eliminate the 64 KB Pipe Deadlock Immediately**:
   - Change PHP spawn `stdio` from `['ignore', 'pipe', 'pipe']` to `['ignore', 'ignore', 'pipe']` with an active `.on('data', ...)` listener on `stderr` that only logs real fatal errors and immediately discards routine access-log lines so the OS pipe buffer **never** fills up.
2. **Enable Multi-Worker PHP Concurrency (`PHP_CLI_SERVER_WORKERS=6`)**:
   - Pass `PHP_CLI_SERVER_WORKERS: '6'` in the child process environment alongside `-d max_execution_time=25` and `-d default_socket_timeout=5`. If one worker waits 2–4 seconds on an offline MikroTik router, 5 other workers remain immediately available to serve UI navigation, login, and metrics.
3. **Active Node-to-PHP Liveness Watchdog (Every 10 Seconds)**:
   - Inside the Node.js supervisor, run a lightweight timer every `10,000ms` that makes a `2,500ms`-timeout HTTP probe to `http://127.0.0.1:8081/status/server_metrics.php?health=1` (a fast, zero-auth, zero-session check).
   - If **3 consecutive probes fail or time out**, Node.js logs a warning, sends `SIGKILL` to the stuck PHP process tree, clears port `8081`, and spawns a fresh PHP server automatically—without waiting for manual human intervention.
4. **End-to-End Health Endpoint (`GET /__health`)**:
   - Node.js exposes `GET /__health` on port `3000`, which verifies both Node.js readiness and PHP (`:8081`) responsiveness, returning HTTP `200 OK` (`{"status":"ok","php":"up","uptime":...}`) or HTTP `503 Service Unavailable`.
5. **Graceful Shutdown Hooks (`SIGTERM`, `SIGINT`, `exit`)**:
   - Register process signal handlers in Node.js so `phpProcess` and its worker group are terminated cleanly whenever `mikhmon.service` stops or restarts.

---

## 6. Timeout, Proxy & PHP Session-Lock Hardening

1. **Bounded Proxy Timeout in Node.js (`20s` Socket Timeout)**:
   - Add `proxyReq.setTimeout(20000, () => { proxyReq.destroy(new Error('PHP upstream timeout')); })` to the Node.js HTTP proxy so hung upstream requests fail cleanly in 20 seconds (well before Nginx's 60-second `504 Gateway Time-out`) and return a clean HTTP `502`/`504` response instead of hanging the socket.
2. **Release PHP Session Locks Before Network Operations**:
   - Call `session_write_close()` before network ping (`fsockopen`) in the ping test endpoint and immediately after session state updates in the router connection handler so slow MikroTik routers cannot freeze concurrent browser tabs or AJAX requests.
3. **Fast Health Short-Circuit**:
   - Allow `status/server_metrics.php?health=1` to return `{"ok":true}` immediately (without session locking or CPU delta sleep) for sub-millisecond internal watchdog checks.

---

## 7. Data-Preservation & Backup Plan

All reliability changes strictly preserve your live configuration and business data:
- **Protected Paths (Never Overwritten)**:
  - `mikhmonv3-upstream/include/config.php` (RouterOS credentials & sessions)
  - `mikhmonv3-upstream/include/resellers.json` & `mikhmonv3-upstream/include/permission_groups.json`
  - `mikhmonv3-upstream/include/version_state.json`
  - `mikhmonv3-upstream/img/` & `mikhmonv3-upstream/uploads/` (Custom logos)
  - `mikhmonv3-upstream/voucher/template*.php`
- **Pre-Update Backup Procedure on VPS**:
  Before pulling updates on the VPS (or automatically via the built-in System Update page), back up stateful files:
  ```bash
  cp -a /root/apps/mikhmon/mikhmonv3-upstream/include/config.php /root/mikhmon-config.backup.php
  ```

---

## 8. Implementation Checklist

Once you approve this plan (`Proceed`), we will execute the following targeted code changes in the repository:

1. **Node.js Supervisor & Reverse Proxy**:
   - Drain `phpProcess.stdout` and `phpProcess.stderr` streams so the 64 KB Linux kernel pipe buffer can never deadlock PHP.
   - Set `PHP_CLI_SERVER_WORKERS=6`, `-d max_execution_time=25`, and `-d default_socket_timeout=5` when spawning `php -S 127.0.0.1:8081`.
   - Add a 20-second timeout (`proxyReq.setTimeout(20000)`) on all proxied requests to `127.0.0.1:8081`.
   - Implement the 10-second internal PHP health watchdog that automatically kills and respawns PHP if 3 consecutive health checks fail.
   - Add `GET /__health` endpoint for instant end-to-end health verification.
   - Add `SIGTERM` / `SIGINT` / `exit` cleanup handlers and pre-spawn port cleanup so orphaned PHP processes never block port `8081`.
2. **PHP Concurrency & Health Endpoint**:
   - Add fast `?health=1` responder in the server metrics script for zero-overhead watchdog probes.
   - Add `session_write_close()` prior to `fsockopen()` in the router ping test endpoint so unreachable router IPs never lock the user's PHP session.

---

## 9. Testing & Failure Simulation Plan

After deploying the changes, verify self-healing using these 4 tests:

1. **High-Volume Request Stress Test (Verifies 64 KB Pipe Deadlock Fix)**:
   - Run 1,500 rapid requests (`for i in $(seq 1 1500); do curl -s -o /dev/null http://127.0.0.1:3000/__health; done`).
   - Verify that neither port `8081` nor port `3000` freezes after exceeding 64 KB of PHP access logs.
2. **Simulated Hung PHP Process (`SIGSTOP` Test)**:
   - Freeze the PHP process manually (`kill -STOP $(pgrep -f "127.0.0.1:8081")`).
   - Observe the Node.js watchdog detect 3 failed probes (~30s), kill the frozen PHP process, respawn a fresh multi-worker PHP server, and restore HTTP `200` on `/__health` automatically.
3. **Simulated Killed PHP Process (`SIGKILL` Test)**:
   - Terminate PHP (`pkill -9 -f "127.0.0.1:8081"`).
   - Verify Node.js respawns PHP within 500ms and `curl -I http://127.0.0.1:3000/` returns HTTP `200`/`302`.
4. **Unreachable Router Concurrency Test**:
   - Trigger a connection or ping test to a non-routable IP (e.g., `10.255.255.1`) while browsing the dashboard in another tab; verify UI navigation remains instantaneous via the remaining 5 PHP workers.

---

## 10. Rollback Plan

If any issue arises after updating:
1. **Via Web UI**: Open **Settings / System Update** $\rightarrow$ **Rollback to Previous Version** (uses the 4-version snapshot history).
2. **Via VPS Terminal**:
   ```bash
   cd /root/apps/mikhmon
   git reset --hard HEAD~1
   systemctl restart mikhmon.service
   ```

---

## 11. Acceptance Criteria

- Zero `504 Gateway Time-out` errors after >1,500 consecutive requests and multi-day uptime.
- `curl -s http://127.0.0.1:3000/__health` consistently returns `{"status":"ok","php":"up"}` in `<50ms`.
- Simulated `SIGSTOP` or `SIGKILL` of the PHP backend self-heals automatically without restarting `mikhmon.service`.
- Unreachable MikroTik routers fail gracefully within 4 seconds without blocking other tabs or users.

---

## 12. Remaining Risks & Long-Term Recommendations

- **DNS Resolver Stability on VPS**: Your logs noted a brief `Could not resolve host` event. Ensure `/etc/systemd/resolved.conf` on your Ubuntu 24.04 VPS includes fallback DNS servers (`DNS=1.1.1.1 8.8.8.8`) so temporary provider DNS hiccups never block GitHub updates or Let's Encrypt renewals.
- **PHP Built-in Server vs. PHP-FPM**: With `PHP_CLI_SERVER_WORKERS=6`, pipe draining, and the active watchdog, `php -S` easily handles dozens of concurrent admin/reseller sessions on a 1 vCPU / 2 GB RAM VPS. If you scale to 100+ simultaneous users in the future, migrating `:8081` to `php8.3-fpm` with dynamic process management is the next architectural tier.
