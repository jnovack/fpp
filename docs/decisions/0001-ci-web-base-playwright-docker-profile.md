# ADR 0001: ci-web-base Playwright Docker profile

- Status: Accepted
- Date: 2026-05-06

## Context

The GitHub Actions workflow `.github/workflows/playwright-pages.yml` brings up FPP through `Docker/docker-compose.playwright.yml` and then runs Playwright page coverage against the web UI. The default build flow compiles the full `optimized` target, which builds more binaries than this workflow needs and increases CI time.

The Playwright stack also bind-mounts the repo into `/opt/fpp`, which means image-built binaries are hidden at runtime. Any CI-only build decision must therefore be honored both during the Docker image build and again when `Docker/runDocker.sh` starts inside the container.

## Findings

- On 2026-05-06, the Playwright Docker stack was verified to use `Docker/docker-compose.playwright.yml` and `Docker/runDocker.sh`.
- On 2026-05-06, `/api/fppd/status` and `/api/fppd/version` were verified as the critical daemon endpoints used to confirm the web stack is healthy.
- `fppd_start` and the existing health checks expect the daemon binary to remain named `fppd`, so a profile-specific output name would create unnecessary script churn.
- The mounted workspace can contain stale host-built binaries, so an explicit `FPP_BUILD_PROFILE` must trigger a rebuild inside the container rather than relying only on file existence checks.

## Alternatives Considered

### Option 1: Keep using `optimized`

Rejected. It preserves existing behavior but keeps the Playwright workflow paying for unrelated binaries and utilities.

### Option 2: Add a `ci-web-base` target that still emits the standard binary names

Accepted. This gives CI an explicit build profile while preserving `fppd`, `fpp`, and `fppinit` names expected by scripts, health checks, and the Docker runtime.

### Option 3: Introduce a profile-specific daemon filename such as `fppd-ci-web-base`

Rejected. This repo has many shell scripts and health checks that assume the daemon is named `fppd`, including `pgrep`, start/stop helpers, and UI-backed checks. Renaming the binary would spread CI-specific logic through runtime scripts for little gain.

## Decision

Add an opt-in `ci-web-base` make target for the Playwright Docker workflow. The target builds only `fpp`, `fppd`, and `fppinit`, tags the daemon version string with `-ci-web-base`, and is selected explicitly through `FPP_BUILD_PROFILE=ci-web-base` in the Playwright Docker compose and Docker build/runtime path.

## Consequences

### Positive

- The Playwright workflow gets a distinct CI build profile without changing `optimized`.
- The running daemon self-identifies as `ci-web-base` through `/api/fppd/status` and `/api/fppd/version`.
- The Docker runtime rebuilds the mounted workspace with the requested profile, avoiding accidental reuse of stale host binaries.

### Tradeoffs

- `ci-web-base` still links the existing monolithic `libfpp`, so this is a scoped CI build-profile optimization, not a deep daemon modularization.
- Docker startup for the Playwright profile now intentionally rebuilds when `FPP_BUILD_PROFILE` is set.
- The profile currently targets the Playwright Docker workflow only; broader CI reuse needs separate validation.

## What Replaces It

The Playwright Docker workflow will use `ci-web-base` instead of the default `optimized` build path when `FPP_BUILD_PROFILE=ci-web-base` is set.

## Revisit Criteria

Revisit this ADR if Playwright begins requiring plugin-backed behavior, if the CI profile needs additional daemon-backed features, or if the repo later splits `libfpp` into smaller linkable units and can reduce compile time more aggressively.

## References

- [.github/workflows/playwright-pages.yml](../../.github/workflows/playwright-pages.yml)
- [Docker/docker-compose.playwright.yml](../../Docker/docker-compose.playwright.yml)
- [Docker/runDocker.sh](../../Docker/runDocker.sh)
