#!/usr/bin/env bash
# Regenerate openapi.json from @route PHPDoc annotations in controllers/*.php
# Run from www/api/: bash tools/build_docs.sh
set -e
TOOLS="$(dirname "$0")"
python3 "$TOOLS/generate_openapi.py"
echo "✓ Lint with: npx @redocly/cli lint --config openapi.lint.yaml openapi.json"
