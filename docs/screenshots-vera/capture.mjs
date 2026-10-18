import { chromium } from 'playwright';
import { mkdirSync, copyFileSync, existsSync, readdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT = __dirname;
const BASE = 'http://127.0.0.1:8000';
mkdirSync(OUT, { recursive: true });

const VIDEO = 'b8b56e2e-a632-4fd4-b26f-8a0e716b70e2';
const EVENT = '01a0f52f-347b-71d1-82d3-3ebc83a1e264';
const RUN = 'a6b237d5-9588-4555-8d76-9441efe0e9db';

/** @type {{n:string, path:string, full?:boolean, after?:(page:any)=>Promise<void>}[]} */
const PAGES = [
  { n: '01-welcome-landing', path: '/' },
  { n: '02-login', path: '/login' },
  { n: '03-dashboard', path: '/dashboard' },
  { n: '04-monitor-cctv', path: '/monitor' },
  { n: '05-device-uptime', path: '/uptime' },
  { n: '06-videos-analysis-list', path: '/videos' },
  {
    n: '07-videos-upload-form',
    path: '/videos',
    after: async (page) => {
      // expand / focus upload area if present
      const upload = page.getByRole('button', { name: /upload/i }).first();
      if (await upload.count()) {
        await upload.click({ timeout: 2000 }).catch(() => {});
      }
      await page.waitForTimeout(500);
    },
  },
  { n: '08-video-detail', path: `/videos/${VIDEO}` },
  { n: '09-analysis-detail', path: `/analyses/${RUN}` },
  { n: '10-anomalies-list', path: '/events' },
  { n: '11-anomaly-detail', path: `/events/${EVENT}` },
  { n: '12-activities', path: '/activities' },
  { n: '13-reports', path: '/reports' },
  { n: '14-assistant', path: '/assistant' },
  { n: '15-notifications', path: '/notifications' },
  { n: '16-locations', path: '/locations' },
  { n: '17-video-sources', path: '/sources' },
  { n: '18-sop-rules-models', path: '/rules' },
  { n: '19-members', path: '/members' },
  { n: '20-system', path: '/system' },
  { n: '21-settings-profile', path: '/settings/profile' },
  { n: '22-settings-security', path: '/settings/security' },
  { n: '23-settings-appearance', path: '/settings/appearance' },
  { n: '24-register', path: '/register' },
  { n: '25-forgot-password', path: '/forgot-password' },
];

async function shot(page, name) {
  const file = join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  console.log('saved', name);
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  deviceScaleFactor: 1,
});
const page = await context.newPage();

// Public pages first
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await shot(page, '01-welcome-landing');

await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
await shot(page, '02-login');

await page.fill('input[type="email"], input[name="email"]', 'mahesyt@gmail.com');
await page.fill('input[type="password"], input[name="password"]', 'VeraDemo2026!');
await Promise.all([
  page.waitForURL(/dashboard|organizations/, { timeout: 15000 }),
  page.locator('[data-test="login-button"]').click(),
]);
await page.waitForTimeout(800);
await shot(page, '03-dashboard');

for (const p of PAGES) {
  if (['01-welcome-landing', '02-login', '03-dashboard'].includes(p.n)) continue;
  try {
    const res = await page.goto(BASE + p.path, { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(600);
    if (p.after) await p.after(page);
    await shot(page, p.n);
    if (res && res.status() >= 400) console.warn('status', res.status(), p.n);
  } catch (e) {
    console.error('FAIL', p.n, e.message);
    await shot(page, p.n + '-ERROR').catch(() => {});
  }
}

// Extra: open upload section detail if videos page has file input
try {
  await page.goto(BASE + '/videos', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  // scroll to upload form
  const fileInput = page.locator('input[type="file"]').first();
  if (await fileInput.count()) {
    await fileInput.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
  }
  await shot(page, '07b-videos-upload-area-detail');
} catch (e) {
  console.error('upload detail fail', e.message);
}

await browser.close();
console.log('DONE', OUT);
