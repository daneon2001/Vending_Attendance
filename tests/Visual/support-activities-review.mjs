// Standalone runner: deliberately does not load playwright.config.ts or real-login auth.setup.ts.
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, access } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { chromium } from '@playwright/test';
import { startVisualServer } from './support-activities-server.mjs';
import { activities, machine, ticket } from './support-activities-fixtures.mjs';

const output = await mkdtemp(path.join(os.tmpdir(), 'vending-support-visual-'));
const server = await startVisualServer();
let browser;
const report = { environment: 'Synthetic fixtures; no DB; built production components', output, checks: [], failures: [] };
try {
    let executablePath = process.env.VISUAL_CHROMIUM_PATH;
    if (executablePath) await access(executablePath);
    browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    for (const viewport of [{ width: 1920, height: 1080 }, { width: 1366, height: 768 }]) {
        for (const role of ['admin', 'support', 'viewer', 'none']) {
            const context = await browser.newContext({ viewport, locale: 'es-MX', timezoneId: 'America/Mexico_City', colorScheme: 'light' });
            await context.addCookies([{ name: 'visual_profile', value: role, url: server.origin }]);
            const external = [];
            await context.route('**/*', route => {
                if (new URL(route.request().url()).origin !== server.origin) {
                    external.push(route.request().url()); return route.abort();
                }
                return route.continue();
            });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            async function capture(name) {
                await page.waitForTimeout(200);
                const metrics = await page.evaluate(() => {
                    const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
                    const bodyWidth = document.documentElement.clientWidth;
                    const overflow = [...document.querySelectorAll('#app *')].filter(el => {
                        if (!visible(el)) return false;
                        const rect = el.getBoundingClientRect();
                        return rect.width > 0 && (rect.right > bodyWidth + 2 || rect.left < -2)
                            && !el.closest('[aria-hidden="true"]');
                    }).slice(0, 10).map(el => el.tagName + '.' + el.className);
                    const unnamedButtons = [...document.querySelectorAll('#app button')].filter(el => visible(el) && !el.textContent.trim() && !el.getAttribute('aria-label') && !el.getAttribute('title')).length;
                    const header = document.querySelector('header')?.getBoundingClientRect();
                    const slot = document.querySelector('.page-header-slot')?.getBoundingClientRect();
                    const headerContainsContent = !!header && !!slot && slot.top >= header.top && slot.bottom <= header.bottom;
                    return { documentWidth: document.documentElement.scrollWidth, viewport: bodyWidth, overflow, unnamedButtons, headerContainsContent,
                        heading: document.querySelector('h1')?.textContent };
                });
                const file = viewport.width + '-' + role + '-' + name + '.png';
                await page.screenshot({ path: path.join(output, file), fullPage: true });
                report.checks.push({ file, ...metrics, errors: [...errors] });
                if (metrics.documentWidth > viewport.width + 2 || metrics.unnamedButtons || !metrics.headerContainsContent || errors.length) report.failures.push(file);
                console.log(file + ' ' + JSON.stringify(metrics) + ' errors=' + JSON.stringify(errors));
                errors.length = 0;
            }
            await page.goto(server.origin + '/support/activities');
            try { await page.waitForSelector('h1', { timeout: 10000 }); }
            catch (error) { await capture('render-failure'); throw error; }
            if (role === 'none') {
                assert.equal(await page.getByRole('link', { name: 'Nueva actividad', exact: true }).count(), 0);
                assert.equal(await page.getByRole('link', { name: 'Actividades', exact: true }).count(), 0);
                await capture('denied');
                await context.close(); continue;
            }
            assert.equal(await page.getByRole('link', { name: 'Nueva actividad', exact: true }).count(), role === 'viewer' ? 0 : 1);
            assert.equal(await page.locator('details[open]').count(), 0);
            await capture('list');
            if (role === 'viewer') {
                await page.goto(server.origin + '/support/activities/' + activities[0].uuid);
                await page.waitForSelector('h1');
                assert.equal(await page.getByRole('button', { name: 'Confirmar cancelación', exact: true }).count(), 0);
                await capture('readonly');
                await context.close(); continue;
            }
            await page.getByText('Filtros avanzados', { exact: false }).first().click();
            await capture('filters');
            const filterInput = page.getByRole('textbox', { name: 'Buscar', exact: true });
            await filterInput.focus();
            await page.keyboard.press('Tab');
            const focus = await page.evaluate(() => {
                const el = document.activeElement;
                const style = getComputedStyle(el);
                return { tag: el.tagName, focusVisible: el.matches(':focus-visible'), outline: style.outlineWidth,
                    shadow: style.boxShadow, label: el.labels?.[0]?.textContent.trim() };
            });
            assert.ok(focus.focusVisible && focus.label && (parseFloat(focus.outline) > 0 || focus.shadow !== 'none'), 'Keyboard focus must be named and visible');
            await capture('keyboard-focus');
            await page.getByRole('textbox', { name: 'Buscar', exact: true }).fill('sin coincidencias');
            await page.getByRole('button', { name: 'Aplicar filtros', exact: true }).click();
            await page.getByText('No encontramos actividades con estos filtros.', { exact: true }).waitFor();
            await capture('empty');
            for (let i = 0; i < activities.length; i++) {
                await page.goto(server.origin + '/support/activities/' + activities[i].uuid);
                await page.getByRole('heading', { name: activities[i].folio, exact: true }).waitFor();
                await capture('detail-' + activities[i].status.toLowerCase());
            }
            for (const result of ['OUTSIDE', 'UNCERTAIN']) {
                await page.goto(server.origin + '/support/activities/' + activities[1].uuid + '?snapshot=' + result);
                await page.getByRole('heading', { name: activities[1].folio, exact: true }).waitFor();
                await capture('snapshot-' + result.toLowerCase());
            }
            await page.goto(server.origin + '/support/activities/create');
            await page.getByRole('heading', { name: 'Nueva actividad', exact: true }).waitFor();
            await capture('create');
            const pickers = page.locator('[data-select-search-root="ignore"]');
            await pickers.nth(0).getByRole('button', { name: 'Buscar', exact: true }).click();
            await pickers.nth(0).locator('select option[value="' + machine.id + '"]').waitFor({ state: 'attached' });
            await pickers.nth(0).locator('select').selectOption(String(machine.id));
            await page.getByRole('combobox', { name: /^Tipo de actividad/ }).selectOption('MAINTENANCE');
            await pickers.nth(1).getByRole('button', { name: 'Buscar', exact: true }).click();
            await pickers.nth(1).getByRole('button', { name: 'Siguiente', exact: true }).waitFor();
            assert.equal(await pickers.nth(1).locator('option').count(), 21);
            await pickers.nth(1).getByRole('button', { name: 'Siguiente', exact: true }).click();
            await pickers.nth(1).getByText(/Página 2/).waitFor();
            assert.equal(await pickers.nth(1).locator('option').count(), 4);
            await capture('create-search-page2');
            await page.getByText('Ticket relacionado (opcional)', { exact: true }).click();
            await pickers.nth(2).getByRole('button', { name: 'Buscar', exact: true }).click();
            await pickers.nth(2).locator('option[value="' + ticket.uuid + '"]').waitFor({ state: 'attached' });
            await pickers.nth(2).locator('select').selectOption(ticket.uuid);
            await capture('create-ticket');
            await pickers.nth(1).getByRole('textbox').fill('error-demo');
            await pickers.nth(1).getByRole('button', { name: 'Buscar', exact: true }).click();
            await pickers.nth(1).getByRole('alert').waitFor();
            await capture('create-error');
            await page.goto(server.origin + '/support/tickets/' + ticket.uuid);
            await page.getByRole('region', { name: 'Actividades de campo relacionadas' }).getByText('ACT-000002', { exact: true }).waitFor();
            assert.equal(await page.getByRole('region', { name: 'Actividades de campo relacionadas' }).getByRole('columnheader').count(), 3);
            await capture('ticket');
            await page.goto(server.origin + '/vending-machines/' + machine.uuid);
            await page.getByRole('region', { name: 'Actividades de campo relacionadas' }).getByText('ACT-000001', { exact: true }).waitFor();
            await capture('machine');
            assert.deepEqual(external, [], 'No external resource requests allowed');
            await context.close();
        }
    }
    // Security checks against the fixture server, not the application/backend.
    assert.equal((await fetch(server.origin + '/.env')).status, 404);
    assert.equal((await fetch(server.origin + '/support/activities', { method: 'POST' })).status, 405);
    console.log('Fixture isolation checks: PASS');
} catch (error) {
    report.failures.push(error.stack);
    console.error(error);
    process.exitCode = 1;
} finally {
    await browser?.close();
    await server.close();
    await writeFile(path.join(output, 'report.json'), JSON.stringify(report, null, 2));
    console.log('VISUAL_REPORT: ' + output);
    console.log('Screens: ' + report.checks.length + '; failures: ' + report.failures.length);
    if (report.failures.length) process.exitCode = 1;
}
