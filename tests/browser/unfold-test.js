// Webbläsartest för CBX_Frontends hopfällning ("Visa fler", från jet-unfold).
// HTML:en genereras av PHP-koden (tests/browser/unfold-fixture.php).
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

(async () => {
  const html = execFileSync('php', [path.join(__dirname, 'unfold-fixture.php')]).toString();
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setContent(html);
  let fails = 0, count = 0;
  const ok = (label, cond, info) => { count++; if (!cond) { fails++; console.log('FAIL: ' + label + (info ? ' ' + JSON.stringify(info) : '')); } };
  const state = () => page.evaluate(() => ({
    h: Math.round(document.querySelector('.cbx-unfold__mask').getBoundingClientRect().height),
    text: document.querySelector('.cbx-unfold__button-text').textContent,
    sep: getComputedStyle(document.querySelector('.cbx-unfold__separator')).opacity,
    open: document.querySelector('.cbx-unfold').classList.contains('cbx-unfold-state'),
  }));
  let s = await state();
  ok('hopfälld från start', 20 === s.h && 'Visa fler nyheter' === s.text && '1' === s.sep && !s.open, s);
  await page.click('.cbx-unfold__button');
  await page.waitForTimeout(450);
  s = await state();
  ok('utfälld efter klick', s.h > 1000 && 'Visa färre nyheter' === s.text && '0' === s.sep && s.open, s);
  await page.focus('.cbx-unfold__button');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(350);
  s = await state();
  ok('hopfälld igen med tangentbordet', 20 === s.h && 'Visa fler nyheter' === s.text && !s.open, s);
  await page.setViewportSize({ width: 375, height: 800 });
  s = await state();
  ok('egen hopfälld höjd på mobil', 40 === s.h, s);
  await browser.close();
  console.log(fails ? `${fails} av ${count} hopfällningskontroller misslyckades` : `Alla ${count} hopfällningskontroller OK`);
  process.exit(fails ? 1 : 0);
})();
