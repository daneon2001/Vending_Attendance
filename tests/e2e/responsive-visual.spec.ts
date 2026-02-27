import { expect, test } from '@playwright/test';

const breakpoints = [
    { name: '360x800', width: 360, height: 800 },
    { name: '480x900', width: 480, height: 900 },
    { name: '768x1024', width: 768, height: 1024 },
    { name: '1024x768', width: 1024, height: 768 },
    { name: '1366x768', width: 1366, height: 768 },
    { name: '1920x1080', width: 1920, height: 1080 },
];

const routes = [
    { key: 'dashboard', path: '/dashboard' },
    { key: 'attendance', path: '/admin/asistencias' },
    { key: 'attendance-detail', path: '__ATTENDANCE_DETAIL__' },
    { key: 'units', path: '/units' },
    { key: 'clocks', path: '/clocks' },
    { key: 'settings-users', path: '/settings/users' },
    { key: 'settings-roles', path: '/settings/roles' },
    { key: 'settings-audit', path: '/settings/audit' },
];

const waitForStableUi = async (page) => {
    await page.waitForLoadState('domcontentloaded');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(350);
};

const disableAnimations = async (page) => {
    await page.addStyleTag({
        content: `
            *, *::before, *::after {
                animation: none !important;
                transition: none !important;
                caret-color: transparent !important;
            }
        `,
    });
};

const assertNoGlobalHorizontalOverflow = async (page, routeKey, breakpointName) => {
    const overflowPixels = await page.evaluate(() => {
        const root = document.documentElement;
        return root.scrollWidth - root.clientWidth;
    });

    expect(
        overflowPixels,
        `Overflow horizontal detectado en ${routeKey} para ${breakpointName}`,
    ).toBeLessThanOrEqual(1);
};

const resolveAttendanceDetailPath = async (page) => {
    const attendanceId = process.env.E2E_ATTENDANCE_ID;
    if (attendanceId) {
        return `/admin/asistencias/${attendanceId}`;
    }

    await page.goto('/admin/asistencias');
    await waitForStableUi(page);

    const detailPath = await page.evaluate(() => {
        const links = Array.from(document.querySelectorAll('a[href]'));
        const href = links
            .map((link) => link.getAttribute('href') ?? '')
            .find((candidate) => /\/admin\/asistencias\/\d+$/.test(candidate));
        return href ?? null;
    });

    return detailPath ?? '/admin/asistencias';
};

test.describe('Visual snapshots responsivos', () => {
    for (const routeItem of routes) {
        for (const breakpoint of breakpoints) {
            test(`${routeItem.key} @ ${breakpoint.name}`, async ({ page }) => {
                await page.setViewportSize({
                    width: breakpoint.width,
                    height: breakpoint.height,
                });

                const targetPath =
                    routeItem.path === '__ATTENDANCE_DETAIL__'
                        ? await resolveAttendanceDetailPath(page)
                        : routeItem.path;

                await page.goto(targetPath);
                await waitForStableUi(page);
                await disableAnimations(page);
                await waitForStableUi(page);
                await assertNoGlobalHorizontalOverflow(page, routeItem.key, breakpoint.name);

                const screenshot = await page.screenshot({ fullPage: true });
                expect(screenshot).toMatchSnapshot(
                    `${routeItem.key}-${breakpoint.width}x${breakpoint.height}.png`,
                    {
                        maxDiffPixelRatio: 0.03,
                    },
                );
            });
        }
    }
});
