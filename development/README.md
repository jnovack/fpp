# Playwright Development

This folder contains the Playwright test setup for the FPP web UI.

## Prerequisites

- VS Code
- Node.js 20+ with `npm`
- Docker Desktop or a local Docker engine

Recommended VS Code extension:

- `ms-playwright.playwright`

## Install

From the `development/` directory:

```bash
npm install
npx playwright install
```

## Start FPP Locally

From the repository root, start the development container:

```bash
docker compose -f Docker/docker-compose-dev.yml up -d --build fpp
```

The FPP web UI should then be available at:

```text
http://127.0.0.1:8080
```

## Run The Tests

From `development/`:

```bash
npm test
```

This runs every test twice:

- once in light mode
- once in dark mode

Screenshots and results are written to:

- `playwright-report/`
- `test-results/`

## Run In VS Code

Open the repository in VS Code, then:

1. Install the Playwright extension.
2. Open the Testing view.
3. Make sure the Docker FPP container is already running on `http://127.0.0.1:8080`.
4. Run or debug individual tests from `development/tests/fpp.spec.ts`.

If you need a different target URL, set `PLAYWRIGHT_BASE_URL` before running tests.

Example:

```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8080 npm test
```

## View The HTML Report

From `development/`:

```bash
npm run report
```

## Stop The Container

From the repository root:

```bash
docker compose -f Docker/docker-compose-dev.yml down
```
