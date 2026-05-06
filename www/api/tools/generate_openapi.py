#!/usr/bin/env python3
"""
Generate www/api/openapi.yaml from @route/@body/@response PHPDoc tags
in www/api/controllers/*.php.

Usage (run from www/api/):
    python3 tools/generate_openapi.py
"""

import glob
import json
import re
import sys
from pathlib import Path

API_PREFIX = '/api'
CONTROLLERS = sorted(glob.glob(str(Path(__file__).parent.parent / 'controllers' / '*.php')))
OUTPUT = Path(__file__).parent.parent / 'openapi.yaml'


# ---------------------------------------------------------------------------
# PHPDoc parsing
# ---------------------------------------------------------------------------

DOCBLOCK_RE = re.compile(r'/\*\*(.*?)\*/', re.DOTALL)
FUNCTION_RE = re.compile(r'\bfunction\s+\w+\s*\(')


def strip_stars(block):
    """Remove leading ' * ' from each line of a docblock body."""
    lines = []
    for line in block.splitlines():
        line = re.sub(r'^\s*\*\s?', '', line)
        lines.append(line)
    return '\n'.join(lines)


def parse_docblocks(php_source):
    """
    Yield dicts for every docblock that contains a @route tag.
    Each dict has: method, path, description, body, responses.
    responses is a list of (status_code, value) tuples.
    """
    # Find all (docblock_end_pos, docblock_text) pairs
    blocks = [(m.end(), strip_stars(m.group(1))) for m in DOCBLOCK_RE.finditer(php_source)]

    for end_pos, text in blocks:
        route_match = re.search(r'@route\s+(GET|POST|PUT|DELETE|PATCH)\s+(\S+)', text)
        if not route_match:
            continue

        method = route_match.group(1).lower()
        full_path = route_match.group(2)

        oapi_path = full_path if full_path else '/'

        # Description: everything before the first @tag, collapsed
        desc_raw = text[:text.find('@')].strip() if '@' in text else text.strip()
        description = ' '.join(desc_raw.split())

        # @body (optional)
        body_match = re.search(r'@body\s+(.+)', text)
        body_raw = body_match.group(1).strip() if body_match else None

        # @response [statusCode] <json|string>
        responses = []
        for rm in re.finditer(r'@response(?:\s+(\d{3}))?\s+(.+)', text):
            status = int(rm.group(1)) if rm.group(1) else 200
            responses.append((status, rm.group(2).strip()))

        yield {
            'method':      method,
            'path':        oapi_path,
            'description': description,
            'body_raw':    body_raw,
            'responses':   responses,
        }


def load_endpoints():
    """Parse all controller PHP files and return sorted list of endpoint dicts."""
    endpoints = []
    for php_file in CONTROLLERS:
        source = open(php_file, encoding='utf-8', errors='replace').read()
        for ep in parse_docblocks(source):
            endpoints.append(ep)
    # Sort by path then method for stable output
    endpoints.sort(key=lambda e: (e['path'], e['method']))
    return endpoints


# ---------------------------------------------------------------------------
# YAML emission helpers  (no external deps)
# ---------------------------------------------------------------------------

def needs_quoting(s):
    if not isinstance(s, str):
        return False
    SPECIAL = set(': #{}[]!*&|>\'",%@`')
    return any(c in SPECIAL for c in s) or s == '' or s[0] in '-?'


def yaml_scalar(v):
    if v is None:
        return 'null'
    if isinstance(v, bool):
        return 'true' if v else 'false'
    if isinstance(v, (int, float)):
        return str(v)
    if isinstance(v, str):
        v = v.replace('\n', ' ').replace('\r', '')
        if needs_quoting(v):
            return "'" + v.replace("'", "''") + "'"
        return v
    return "'" + json.dumps(v).replace("'", "''") + "'"


def to_yaml_lines(value, indent):
    prefix = '  ' * indent
    lines = []
    if isinstance(value, dict):
        for k, v in value.items():
            k_safe = yaml_scalar(str(k))
            if isinstance(v, (dict, list)):
                lines.append(f'{prefix}{k_safe}:')
                lines.extend(to_yaml_lines(v, indent + 1))
            elif isinstance(v, str) and '\n' in v:
                lines.append(f'{prefix}{k_safe}: |')
                for sub in v.splitlines():
                    lines.append(f'{prefix}  {sub}')
            else:
                lines.append(f'{prefix}{k_safe}: {yaml_scalar(v)}')
    elif isinstance(value, list):
        for item in value:
            if isinstance(item, (dict, list)):
                sub = to_yaml_lines(item, indent + 1)
                if sub:
                    lines.append(f'{prefix}- {sub[0].lstrip()}')
                    lines.extend(sub[1:])
                else:
                    lines.append(f'{prefix}-')
            else:
                lines.append(f'{prefix}- {yaml_scalar(item)}')
    return lines


def yaml_example_block(value, indent):
    prefix = '  ' * indent
    if isinstance(value, (dict, list)):
        inner = to_yaml_lines(value, indent + 1)
        return f'{prefix}example:\n' + '\n'.join(inner)
    return f'{prefix}example: {yaml_scalar(value)}'


def parse_json_value(raw):
    """Try to parse raw as JSON; return parsed value or the raw string."""
    try:
        return json.loads(raw)
    except (json.JSONDecodeError, TypeError):
        return raw


# ---------------------------------------------------------------------------
# OpenAPI path param extraction
# ---------------------------------------------------------------------------

def extract_path_params(path):
    return re.findall(r'\{([^}]+)\}', path)


def tag_from_path(path):
    parts = [p for p in path.strip('/').split('/') if p and not p.startswith('{') and p != 'api']
    return parts[0] if parts else 'general'


# ---------------------------------------------------------------------------
# Build YAML
# ---------------------------------------------------------------------------

def build_openapi(endpoints):
    tags = sorted(set(tag_from_path(e['path']) for e in endpoints))

    lines = [
        "openapi: '3.0.3'",
        "info:",
        "  title: FPP API",
        "  description: Falcon Player (FPP) REST API",
        "  version: '1.0'",
        "servers:",
        "  - url: /",
        "    description: Local FPP instance",
        "tags:",
    ]
    for tag in tags:
        lines.append(f'  - name: {tag}')
    lines.append('')
    lines.append('paths:')

    # Group by path so multiple methods share one path block
    from collections import defaultdict
    by_path = defaultdict(list)
    for ep in endpoints:
        by_path[ep['path']].append(ep)

    for path in sorted(by_path):
        eps = by_path[path]
        params = extract_path_params(path)

        lines.append(f"  '{path}':")

        if params:
            lines.append('    parameters:')
            for p in params:
                lines.append(f'      - name: {p}')
                lines.append(f'        in: path')
                lines.append(f'        required: true')
                lines.append(f'        schema:')
                lines.append(f"          type: string")

        for ep in sorted(eps, key=lambda e: e['method']):
            method  = ep['method']
            desc    = ep['description']
            tag     = tag_from_path(path)

            # Summary = path without /api/ prefix (the "route name").
            # Description = full human-readable text shown below the title.
            summary = path.removeprefix(API_PREFIX).lstrip('/')

            lines.append(f'    {method}:')
            lines.append(f'      tags:')
            lines.append(f'        - {tag}')
            lines.append(f'      summary: {yaml_scalar(summary)}')
            if desc:
                lines.append(f"      description: {yaml_scalar(desc)}")

            # Request body
            if ep['body_raw']:
                body_val = parse_json_value(ep['body_raw'])
                lines.append('      requestBody:')
                lines.append('        content:')
                if isinstance(body_val, str):
                    lines.append('          text/plain:')
                    lines.append('            schema:')
                    lines.append('              type: string')
                    lines.append(f"            example: {yaml_scalar(body_val)}")
                else:
                    lines.append('          application/json:')
                    lines.append('            schema:')
                    lines.append(f'              type: {"array" if isinstance(body_val, list) else "object"}')
                    lines.append(yaml_example_block(body_val, indent=6))

            # Responses
            lines.append('      responses:')
            if ep['responses']:
                seen = set()
                for status, raw in ep['responses']:
                    if status in seen:
                        continue
                    seen.add(status)
                    val = parse_json_value(raw)
                    lines.append(f"        '{status}':")
                    if isinstance(val, str):
                        lines.append(f"          description: {yaml_scalar(val)}")
                    else:
                        lines.append(f"          description: Success" if status == 200 else f"          description: 'HTTP {status}'")
                        lines.append('          content:')
                        lines.append('            application/json:')
                        lines.append('              schema:')
                        lines.append(f'                type: {"array" if isinstance(val, list) else "object"}')
                        lines.append(yaml_example_block(val, indent=7))
            else:
                lines.append("        '200':")
                lines.append('          description: Success')

    return '\n'.join(lines) + '\n'


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    endpoints = load_endpoints()
    if not endpoints:
        print('ERROR: No @route annotations found. Are you running from www/api/?', file=sys.stderr)
        sys.exit(1)

    yaml_out = build_openapi(endpoints)
    OUTPUT.write_text(yaml_out, encoding='utf-8')

    path_count = len(set(e['path'] for e in endpoints))
    op_count   = len(endpoints)
    print(f'Written {OUTPUT} ({path_count} paths, {op_count} operations)')


if __name__ == '__main__':
    main()
