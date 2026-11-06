// Takes the README screenshots from the render harness (see readme-shots.sh). Env: PLAYWRIGHT_MODULE, CHROME, BASE, OUT.
import { createRequire } from 'module';
const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_MODULE);
const { BASE, OUT } = process.env;
const browser = await chromium.launch({ executablePath: process.env.CHROME || 'google-chrome', args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });
const snap = async (loc, name) => { await loc.screenshot({ path: `${OUT}/${name}.png` }); };

await page.goto(`${BASE}/?tab=1`); await page.waitForSelector('#findings-body tr');
await page.addStyleTag({ content: '.lk-status-bar,#lk-note{display:none!important}' });
await snap(page.locator('#lookout-app'), 'findings');

const row = (rule) => page.locator('#findings-body tr', { hasText: rule });
for (const [rule, name] of [['WebGUI auth timeout', 'finding-504'], ['Array has failed device', 'finding-array']]) {
  const tr = row(rule).first();
  await tr.getByRole('button', { name: 'Log' }).click();
  const a = await tr.boundingBox(), b = await page.locator('.lk-logrow').first().boundingBox();
  await page.screenshot({ path: `${OUT}/${name}.png`, clip: { x: a.x, y: a.y, width: a.width, height: b.y + b.height - a.y } });
  await tr.getByRole('button', { name: 'Log' }).click();
}
await page.goto(`${BASE}/?tab=2`); await page.waitForSelector('#settings-panel');
await snap(page.locator('#settings-panel'), 'settings');
await browser.close();
