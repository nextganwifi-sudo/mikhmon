import express from 'express';
import type { Request, Response } from 'express';
import http from 'http';
import { spawn, execSync } from 'child_process';
import type { ChildProcess } from 'child_process';
import path from 'path';
import fs from 'fs';

const PORT = Number(process.env.PORT) || 3000;
const PHP_PORT = 9009;
const MIKHMON_ROOT = path.resolve(process.cwd(), 'mikhmon');
const UPSTREAM_MIRROR_ROOT = path.resolve(process.cwd(), 'mikhmonv3-upstream');
const BUNDLED_PHP = path.resolve(process.cwd(), '.php-runtime', 'php-wrapper.sh');
let fallbackPhpSessionId: string | null = null;

function syncUpstreamMirror(): void {
  try {
    execSync(
      `mkdir -p "${UPSTREAM_MIRROR_ROOT}" && cp -r "${MIKHMON_ROOT}/." "${UPSTREAM_MIRROR_ROOT}/"`,
      { stdio: 'ignore' }
    );
  } catch (err) {
    // Ignore mirror sync errors
  }
}

function resolvePhpCommand(): { cmd: string; env: NodeJS.ProcessEnv } {
  const baseEnv: NodeJS.ProcessEnv = {
    ...process.env,
    PHP_CLI_SERVER_WORKERS: '8',
  };

  if (fs.existsSync('/usr/bin/php')) {
    return { cmd: '/usr/bin/php', env: baseEnv };
  }

  if (fs.existsSync('/usr/bin/php8.2')) {
    return { cmd: '/usr/bin/php8.2', env: baseEnv };
  }

  if (fs.existsSync(BUNDLED_PHP)) {
    return { cmd: BUNDLED_PHP, env: baseEnv };
  }

  try {
    execSync(
      'DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" php-cli php-cgi php-gd php-mbstring php-curl php-xml php-zip',
      { stdio: 'inherit' }
    );
  } catch (err) {
    console.error('Failed to auto-install php-cli:', err);
  }

  return { cmd: 'php', env: baseEnv };
}

let phpProcess: ChildProcess | null = null;

function startPhpServer(): Promise<void> {
  return new Promise((resolve) => {
    const { cmd, env } = resolvePhpCommand();
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

    let resolved = false;
    const finish = () => {
      if (!resolved) {
        resolved = true;
        resolve();
      }
    };

    phpProcess.stdout?.on('data', () => finish());
    phpProcess.stderr?.on('data', () => finish());

    phpProcess.on('exit', (code) => {
      console.error(`PHP server exited with code ${code}, restarting in 500ms...`);
      setTimeout(() => {
        startPhpServer().catch((err) => console.error('Failed to restart PHP server:', err));
      }, 500);
    });

    setTimeout(finish, 400);
  });
}

function extractPhpSessionId(cookieStr: string | undefined): string | null {
  if (!cookieStr) return null;
  const match = cookieStr.match(/PHPSESSID=([a-zA-Z0-9,-]+)/);
  return match ? match[1] : null;
}

function rewriteSetCookieForIframe(cookieHeader: string | string[] | undefined, isIframeHttps: boolean): string[] | undefined {
  if (!cookieHeader) return undefined;
  const cookies = Array.isArray(cookieHeader) ? cookieHeader : [cookieHeader];
  for (const c of cookies) {
    const sid = extractPhpSessionId(c);
    if (sid) {
      fallbackPhpSessionId = sid;
    }
  }
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

async function main() {
  syncUpstreamMirror();
  await startPhpServer();

  const app = express();
  app.disable('x-powered-by');

  app.use((req: Request, res: Response) => {
    const forwardedProto = (req.headers['x-forwarded-proto'] as string) || '';
    const forwardedHost = (req.headers['x-forwarded-host'] as string) || '';
    // Nginx in Cloud Run sets X-Forwarded-Host to the external *.run.app domain
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

    let cookieHeader = req.headers.cookie || '';
    const clientSid = extractPhpSessionId(cookieHeader);
    if (clientSid) {
      fallbackPhpSessionId = clientSid;
    } else if (fallbackPhpSessionId) {
      cookieHeader = cookieHeader
        ? `${cookieHeader}; PHPSESSID=${fallbackPhpSessionId}`
        : `PHPSESSID=${fallbackPhpSessionId}`;
    }

    const forwardHeaders: http.OutgoingHttpHeaders = {
      ...req.headers,
      host: req.headers.host || `127.0.0.1:${PORT}`,
      connection: 'close',
      'x-forwarded-proto': isIframeHttps ? 'https' : 'http',
      'x-forwarded-for': (req.headers['x-forwarded-for'] as string) || req.socket.remoteAddress || '127.0.0.1',
    };
    if (cookieHeader) {
      forwardHeaders.cookie = cookieHeader;
    }
    // Request uncompressed response from local PHP server so headers/cookies & streaming work cleanly
    delete forwardHeaders['accept-encoding'];

    const options: http.RequestOptions = {
      hostname: '127.0.0.1',
      port: PHP_PORT,
      path: targetPath,
      method: req.method,
      headers: forwardHeaders,
    };

    const proxyReq = http.request(options, (proxyRes) => {
      const headers: http.OutgoingHttpHeaders = { ...proxyRes.headers };

      if (headers['set-cookie']) {
        headers['set-cookie'] = rewriteSetCookieForIframe(headers['set-cookie'], isIframeHttps);
      }

      // Remove hop-by-hop headers and frame restrictions so Node http.ServerResponse frames cleanly
      delete headers['transfer-encoding'];
      delete headers['connection'];
      delete headers['keep-alive'];
      delete headers['x-frame-options'];

      res.writeHead(proxyRes.statusCode || 200, headers);
      proxyRes.pipe(res, { end: true });
    });

    proxyReq.on('error', (err) => {
      console.error('Proxy request error:', err.message);
      if (!res.headersSent) {
        res.status(502).send('NextGen WiFi Solution server is starting up, please refresh in a moment.');
      }
    });

    req.pipe(proxyReq, { end: true });
  });

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`NextGen WiFi Solution Server running on http://0.0.0.0:${PORT}`);
  });
}

main().catch((err) => {
  console.error('Fatal startup error:', err);
  process.exit(1);
});
