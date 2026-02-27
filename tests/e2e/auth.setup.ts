import fs from 'node:fs';
import path from 'node:path';
import { expect, test as setup } from '@playwright/test';

const authFile = 'tests/e2e/.auth/user.json';

setup('authenticate', async ({ page }) => {
    const email = process.env.E2E_EMAIL ?? 'admin@gmail.com';
    const password = process.env.E2E_PASSWORD ?? 'password';

    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: /ingresar/i }).click();

    await expect(page).not.toHaveURL(/\/login$/);
    await expect(page.locator('body')).toContainText(/Panel|Catalogo|Configuracion|Relojes/i);

    const authDir = path.dirname(authFile);
    if (!fs.existsSync(authDir)) {
        fs.mkdirSync(authDir, { recursive: true });
    }

    await page.context().storageState({ path: authFile });
});
