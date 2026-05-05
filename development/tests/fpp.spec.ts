import { test } from '@playwright/test';

const SETTINGS_TABS = [
  { slug: 'playback', title: 'Playback' },
  { slug: 'av', title: 'Audio/Video' },
  { slug: 'ui', title: 'UI' },
  { slug: 'privacy', title: 'Privacy' },
  { slug: 'logs', title: 'Logging' },
  { slug: 'services', title: 'Services' },
  { slug: 'system', title: 'System' }
];

test('Index Overview', async ({ page }) => {
  await page.goto('/index.php');
  await page.locator('#bodyWrapper').first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.waitForTimeout(1200);
  await page.evaluate(() => window.scrollTo(0, 0));
});

test('Initial Setup Overview', async ({ page }) => {
  await page.goto('/initialSetup.php');
  await page.locator('#initialSetup').first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.waitForTimeout(250);
});

test('Backup File Copy Error State', async ({ page }) => {
  await page.goto('/backup.php');
  await page.locator('#fppBackupTabs').first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.click('#backups-fileCopy-tab');
  await page.waitForTimeout(300);
  await page.click('input[value="Copy"]');
  await page.waitForTimeout(500);
});

test('Backup JSON Backup Tab', async ({ page }) => {
  await page.goto('/backup.php');
  await page.locator('#fppBackupTabs').first().waitFor({ state: 'visible', timeout: 30_000 });

  const jsonBackupTab = page.locator('#backups-jsonBackup-tab').first();
  if (await jsonBackupTab.count()) {
    await jsonBackupTab.click();
  }

  await page.waitForTimeout(300);
});

test('File Manager Logs', async ({ page }) => {
  await page.goto('/filemanager.php');
  await page.locator('#fileManagerTabs').first().waitFor({ state: 'visible', timeout: 30_000 });

  const logsTab = page.locator('#tab-logs-tab').first();
  if (await logsTab.count()) {
    await logsTab.click();
  }

  await page.waitForTimeout(500);
});

test('Network Overview', async ({ page }) => {
  await page.goto('/networkconfig.php');
  await page.locator('h1.title').first().waitFor({ state: 'visible', timeout: 30_000 });

  const interfaceSettingsTab = page.locator('#interface-settings-tab').first();
  if (await interfaceSettingsTab.count()) {
    await interfaceSettingsTab.click();
  }

  await page.waitForTimeout(400);
});

test('Plugins Overview', async ({ page }) => {
  await page.goto('/plugins.php');
  await page.locator('#bodyWrapper').first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.waitForTimeout(1500);

  const checkAllUpdatesButton = page.locator('#checkAllUpdatesBtn').first();
  if (await checkAllUpdatesButton.count()) {
    await checkAllUpdatesButton.click();
  }

  await page.waitForTimeout(900);
});

test('Scheduler Overview', async ({ page }) => {
  await page.goto('/scheduler.php');
  await page.locator('h1.title').first().waitFor({ state: 'visible', timeout: 30_000 });

  const addScheduleButton = page.locator('button[onclick="AddScheduleEntry();"]').first();
  if (await addScheduleButton.count()) {
    await addScheduleButton.click();
  }

  await page.waitForTimeout(400);
});

test('System Stats Overview', async ({ page }) => {
  await page.goto('/system-stats.php');
  await page.locator('h1.title').first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.waitForTimeout(400);
});

test('System Upgrade Overview', async ({ page }) => {
  await page.goto('/system-upgrade.php');
  await page.locator('h1.title').first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.waitForTimeout(400);
});

for (const tab of SETTINGS_TABS) {
  test(`Settings Tab - ${tab.title}`, async ({ page }) => {
    await page.goto('/settings.php');
    await page.locator('#settingsManagerTabs').first().waitFor({ state: 'visible', timeout: 30_000 });

    const tabLocator = page.locator(`#settings-${tab.slug}-tab`).first();
    await tabLocator.click();

    await page.waitForFunction(
      ([tabSelector, paneSelector]) => {
        const selectedTab = document.querySelector(tabSelector);
        const selectedPane = document.querySelector(paneSelector);

        return Boolean(
          selectedTab &&
          selectedPane &&
          selectedTab.classList.contains('active') &&
          selectedPane.classList.contains('active')
        );
      },
      [`#settings-${tab.slug}-tab`, `#settings-${tab.slug}`]
    );

    await tabLocator.scrollIntoViewIfNeeded();
    await page.waitForTimeout(250);
  });
}
