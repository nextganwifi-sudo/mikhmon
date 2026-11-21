import express from 'express';
import type { Request, Response } from 'express';
import http from 'http';
import net from 'net';
import { spawn } from 'child_process';
import type { ChildProcess } from 'child_process';
import path from 'path';
import fs from 'fs';

const PORT = Number(process.env.PORT) || 3000;
const PHP_PORT = 9009;
const MIKHMON_ROOT = path.resolve(process.cwd(), 'mikhmon');
const UPSTREAM_MIRROR_ROOT = path.resolve(process.cwd(), 'mikhmonv3-upstream');
const BUNDLED_PHP = path.resolve(process.cwd(), '.php-runtime', 'php-wrapper.sh');

function syncUpstreamMirrorAsync(): void {
  const child = spawn(
    'sh',
    ['-c', `mkdir -p "${UPSTREAM_MIRROR_ROOT}" && cp -r "${MIKHMON_ROOT}/." "${UPSTREAM_MIRROR_ROOT}/"`],
    { stdio: 'ignore' }
  );
  child.on('error', () => {});
}

function findExistingPhpBinary(): string | null {
  if (fs.existsSync(BUNDLED_PHP)) {
    return BUNDLED_PHP;
  }
  if (fs.existsSync('/usr/bin/php')) {
    return '/usr/bin/php';
  }
  if (fs.existsSync('/usr/bin/php8.2')) {
    return '/usr/bin/php8.2';
  }
  if (fs.existsSync('/usr/bin/php8.1')) {
    return '/usr/bin/php8.1';
  }
  return null;
}

function installPhpAsync(): Promise<string> {
  return new Promise((resolve) => {
    const existing = findExistingPhpBinary();
    if (existing) {
      resolve(existing);
      return;
    }

    const installScript = `
      while pgrep -x apt-get >/dev/null 2>&1 || pgrep -x dpkg >/dev/null 2>&1; do
        sleep 1
      done
      if [ ! -x /usr/bin/php ] && [ ! -x /usr/bin/php8.2 ]; then
        if [ -f /etc/apt/sources.list.d/debian.sources ]; then
          rm -f /etc/apt/sources.list /var/lib/apt/lists/*ubuntu* 2>/dev/null || true
        fi
        DEBIAN_FRONTEND=noninteractive dpkg --configure -a 2>/dev/null || true
        if ! ls /var/lib/apt/lists/*debian* >/dev/null 2>&1; then
          DEBIAN_FRONTEND=noninteractive apt-get update
        fi
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
          -o Dpkg::Options::="--force-confdef" \
          -o Dpkg::Options::="--force-confold" \
          php-cli php-gd php-mbstring php-curl php-xml php-zip
      fi
    `;

    const child = spawn('sh', ['-c', installScript], {
      stdio: 'inherit',
    });

    child.on('close', () => {
      resolve(findExistingPhpBinary() || 'php');
    });

    child.on('error', () => {
      resolve(findExistingPhpBinary() || 'php');
    });
  });
}

let phpProcess: ChildProcess | null = null;
let phpServerReady = false;

function checkPhpPortOpen(): Promise<boolean> {
  return new Promise((resolve) => {
    const socket = new net.Socket();
    let settled = false;
    const done = (ok: boolean) => {
      if (!settled) {
        settled = true;
        socket.destroy();
        resolve(ok);
      }
    };
    socket.setTimeout(300);
    socket.once('connect', () => done(true));
    socket.once('timeout', () => done(false));
    socket.once('error', () => done(false));
    socket.connect(PHP_PORT, '127.0.0.1');
  });
}

async function waitForPhpReady(maxWaitMs = 25000): Promise<boolean> {
  if (phpServerReady && (await checkPhpPortOpen())) {
    return true;
  }
  const start = Date.now();
  while (Date.now() - start < maxWaitMs) {
    if (await checkPhpPortOpen()) {
      phpServerReady = true;
      return true;
    }
    await new Promise((r) => setTimeout(r, 150));
  }
  return false;
}

async function startPhpServer(): Promise<void> {
  const cmd = (await installPhpAsync()) || 'php';
  const env: NodeJS.ProcessEnv = {
    ...process.env,
    PHP_CLI_SERVER_WORKERS: '8',
  };

  const args = [
    '-d', 'display_errors=0',
    '-d', 'error_reporting=0',
    '-d', 'upload_max_filesize=10M',
    '-d', 'post_max_size=12M',
    '-d', 'max_execution_time=30',
    '-d', 'default_socket_timeout=3',
    '-d', 'session.gc_maxlifetime=86400',
    '-S', `127.0.0.1:${PHP_PORT}`,
    '-t', MIKHMON_ROOT,
  ];

  phpProcess = spawn(cmd, args, {
    cwd: MIKHMON_ROOT,
    env,
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  phpProcess.on('exit', () => {
    phpServerReady = false;
    setTimeout(() => {
      startPhpServer().catch(() => {});
    }, 300);
  });

  await waitForPhpReady(10000);
}

function isBlockedPrivatePath(rawUrlPath: string): boolean {
  const pathOnly = rawUrlPath.split('?')[0];
  let decoded = pathOnly;
  try {
    decoded = decodeURIComponent(pathOnly);
  } catch {
    return true;
  }
  const normalized = decoded.replace(/\/+/g, '/');
  if (normalized.includes('..')) {
    return true;
  }
  if (/^\/(include|data|backups|deploy|\.git|\.aistudio|\.env)(\/|$)/i.test(normalized)) {
    return true;
  }
  if (/^\/uploads\/reseller-templates(\/|$)/i.test(normalized)) {
    return true;
  }
  if (/^\/uploads\/.*\.(php[0-9]?|phtml|phar|cgi|pl|sh)$/i.test(normalized)) {
    return true;
  }
  if (/^\/voucher\/(?!index\.php$|print\.php$|printbt\.php$|vpreview\.php$).*\.(php[0-9]?|phtml|phar|cgi|pl|sh)$/i.test(normalized)) {
    return true;
  }
  if (/\/\.(?!well-known\/)/i.test(normalized)) {
    return true;
  }
  return false;
}

function isStaticAssetPath(rawUrlPath: string): boolean {
  const pathOnly = rawUrlPath.split('?')[0].toLowerCase();
  return /\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|otf|map)$/.test(pathOnly);
}

function rewriteSetCookieForIframe(cookieHeader: string | string[] | undefined, isIframeHttps: boolean): string[] | undefined {
  if (!cookieHeader) return undefined;
  const cookies = Array.isArray(cookieHeader) ? cookieHeader : [cookieHeader];
  if (!isIframeHttps) {
    return cookies;
  }
  return cookies.map((cookie) => {
    let updated = cookie.replace(/;\s*SameSite=(Lax|Strict|None)/gi, '');
    updated = updated.replace(/;\s*Secure/gi, '');
    updated = updated.replace(/;\s*Partitioned/gi, '');
    return `${updated}; SameSite=None; Secure; Partitioned`;
  });
}

function collectRequestBody(req: Request): Promise<Buffer> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    req.on('data', (chunk) => chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk)));
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', (err) => reject(err));
  });
}

async function main() {
  const app = express();
  app.disable('x-powered-by');

  app.use(async (req: Request, res: Response) => {
    const forwardedProto = (req.headers['x-forwarded-proto'] as string) || '';
    const forwardedHost = (req.headers['x-forwarded-host'] as string) || '';
    const isIframeHttps =
      forwardedProto.toLowerCase().includes('https') ||
      forwardedHost.includes('.run.app') ||
      forwardedHost.includes('.google.com') ||
      req.secure;

    let targetPath = req.originalUrl || req.url || '/';
    if (targetPath === '/mikhmon') {
      targetPath = '/';
    } else if (targetPath.startsWith('/mikhmon/')) {
      targetPath = targetPath.slice('/mikhmon'.length);
    }

    if (isBlockedPrivatePath(targetPath)) {
      res.status(404).send('Not Found');
      return;
    }

    let bodyBuf: Buffer;
    try {
      bodyBuf = await collectRequestBody(req);
    } catch {
      if (!res.headersSent) {
        res.status(400).end();
      }
      return;
    }

    await waitForPhpReady(25000);

    const forwardHeaders: http.OutgoingHttpHeaders = {
      ...req.headers,
      host: req.headers.host || `127.0.0.1:${PORT}`,
      connection: 'close',
      'x-forwarded-proto': isIframeHttps ? 'https' : 'http',
      'x-forwarded-for': (req.headers['x-forwarded-for'] as string) || req.socket.remoteAddress || '127.0.0.1',
    };
    delete forwardHeaders['accept-encoding'];
    if (bodyBuf.length > 0) {
      forwardHeaders['content-length'] = String(bodyBuf.length);
    }

    const options: http.RequestOptions = {
      hostname: '127.0.0.1',
      port: PHP_PORT,
      path: targetPath,
      method: req.method,
      headers: forwardHeaders,
    };

    const attemptProxy = (attempt: number) => {
      const proxyReq = http.request(options, (proxyRes) => {
        const headers: http.OutgoingHttpHeaders = { ...proxyRes.headers };

        if (headers['set-cookie']) {
          headers['set-cookie'] = rewriteSetCookieForIframe(headers['set-cookie'], isIframeHttps);
        }

        if (!isStaticAssetPath(targetPath)) {
          headers['cache-control'] = 'no-store, no-cache, must-revalidate, max-age=0, private';
          headers['pragma'] = 'no-cache';
          headers['vary'] = 'Cookie';
        } else {
          headers['cache-control'] = 'public, max-age=86400';
        }

        delete headers['transfer-encoding'];
        delete headers['connection'];
        delete headers['keep-alive'];
        delete headers['x-frame-options'];

        res.writeHead(proxyRes.statusCode || 200, headers);
        proxyRes.pipe(res, { end: true });
      });

      proxyReq.on('error', async () => {
        phpServerReady = false;
        if (!res.headersSent && attempt < 25) {
          await new Promise((r) => setTimeout(r, 200));
          await waitForPhpReady(5000);
          attemptProxy(attempt + 1);
          return;
        }
        if (!res.headersSent) {
          res.status(502).send('NextGan WiFi Solution server is starting up, please refresh in a moment.');
        }
      });

      if (bodyBuf.length > 0) {
        proxyReq.write(bodyBuf);
      }
      proxyReq.end();
    };

    attemptProxy(1);
  });

  // Bind port 3000 immediately so the dev server health check always succeeds right away
  app.listen(PORT, '0.0.0.0', () => {
    console.log(`NextGan WiFi Solution Server running on http://0.0.0.0:${PORT}`);
  });

  syncUpstreamMirrorAsync();
  startPhpServer().catch(() => {});
}

main().catch((err) => {
  console.error('Fatal startup error:', err);
  process.exit(1);
});
