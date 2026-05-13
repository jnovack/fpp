#!/usr/bin/env python3
"""
Generate www/api/openapi.json from @route/@body/@response PHPDoc tags
in www/api/controllers/*.php.

Usage (run from www/api/):
    python3 tools/generate_openapi.py

Docblock summary/description rules:
  - One prose line  → description only; summary falls back to the route slug.
  - Two+ prose lines → first line is summary, remainder joined as description.

Badge syntax (multiple allowed, Scalar x-badges spec):
  @badge "Label text" <level>
  Levels: success | warning | critical | info
  Maps to {name, color} — Scalar uses color as the badge background.
"""

import glob
import json
import re
import sys
from collections import defaultdict
from pathlib import Path

API_PREFIX = '/api'
CONTROLLERS = sorted(glob.glob(str(Path(__file__).parent.parent / 'controllers' / '*.php')))
OUTPUT = Path(__file__).parent.parent / 'openapi.json'

BADGE_COLORS = {
    'success':  '#2e7d32',
    'warning':  '#b25e00',
    'critical': '#c62828',
    'info':     '#546e7a',
}


# ---------------------------------------------------------------------------
# PHPDoc parsing
# ---------------------------------------------------------------------------

DOCBLOCK_RE = re.compile(r'/\*\*(.*?)\*/', re.DOTALL)


def strip_stars(block):
    lines = []
    for line in block.splitlines():
        line = re.sub(r'^\s*\*\s?', '', line)
        lines.append(line)
    return '\n'.join(lines)


def parse_docblocks(php_source):
    """
    Yield dicts for every docblock that contains a @route tag.
    Each dict has: method, path, description, body_raw, responses.
    responses is a list of (status_code, value) tuples.
    """
    blocks = [(m.end(), strip_stars(m.group(1))) for m in DOCBLOCK_RE.finditer(php_source)]

    for end_pos, text in blocks:
        route_match = re.search(r'@route\s+(GET|POST|PUT|DELETE|PATCH)\s+(\S+)', text)
        if not route_match:
            continue

        method = route_match.group(1).lower()
        full_path = route_match.group(2)
        oapi_path = full_path if full_path else '/'

        desc_raw = text[:text.find('@')].strip() if '@' in text else text.strip()
        # Split into blank-line-separated paragraphs; each paragraph is one
        # or more consecutive non-empty lines joined into a single string.
        paragraphs = []
        current = []
        for ln in desc_raw.splitlines():
            stripped = ln.strip()
            if stripped:
                current.append(stripped)
            elif current:
                paragraphs.append(' '.join(current))
                current = []
        if current:
            paragraphs.append(' '.join(current))

        if len(paragraphs) == 0:
            summary = None
            description = None
        elif len(paragraphs) == 1:
            summary = None  # falls back to route slug in build_openapi
            description = paragraphs[0]
        else:
            summary = paragraphs[0]
            description = ' '.join(paragraphs[1:])

        body_match = re.search(r'@body\s+(.+)', text)
        body_raw = body_match.group(1).strip() if body_match else None

        path_param_names = set(extract_path_params(oapi_path))
        params = []
        for pm in re.finditer(r'@param\s+(\S+)\s+(\S+)\s*(.*)', text):
            php_type, pname, pdesc = pm.group(1), pm.group(2), pm.group(3).strip()
            if pname in path_param_names:
                continue  # path params are handled via extract_path_params
            type_map = {'int': 'integer', 'integer': 'integer',
                        'bool': 'boolean', 'boolean': 'boolean',
                        'float': 'number', 'number': 'number'}
            params.append({
                'name':        pname,
                'in':          'query',
                'required':    False,
                'schema':      {'type': type_map.get(php_type.lower(), 'string')},
                'description': pdesc or None,
            })

        responses = []
        for rm in re.finditer(r'@response(?:\s+(\d{3}))?\s+(.+)', text):
            status = int(rm.group(1)) if rm.group(1) else 200
            responses.append((status, rm.group(2).strip()))

        badges = []
        for bm in re.finditer(r'@badge\s+"([^"]+)"\s+(\w+)', text):
            level = bm.group(2).lower()
            badges.append({
                'name':  bm.group(1),
                'color': BADGE_COLORS.get(level, BADGE_COLORS['info']),
            })

        yield {
            'method':      method,
            'path':        oapi_path,
            'summary':     summary,
            'description': description,
            'body_raw':    body_raw,
            'responses':   responses,
            'badges':      badges,
            'params':      params,
        }


def load_endpoints():
    endpoints = []
    for php_file in CONTROLLERS:
        source = open(php_file, encoding='utf-8', errors='replace').read()
        for ep in parse_docblocks(source):
            endpoints.append(ep)
    endpoints.sort(key=lambda e: (e['path'], e['method']))
    return endpoints


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def parse_json_value(raw):
    try:
        return json.loads(raw)
    except (json.JSONDecodeError, TypeError):
        return raw


def extract_path_params(path):
    return re.findall(r'\{([^}]+)\}', path)


def tag_from_path(path):
    parts = [p for p in path.strip('/').split('/') if p and not p.startswith('{') and p != 'api']
    return parts[0] if parts else 'general'


# ---------------------------------------------------------------------------
# Build OpenAPI dict
# ---------------------------------------------------------------------------

def build_openapi(endpoints):
    tags = sorted(set(tag_from_path(e['path']) for e in endpoints))

    spec = {
        'openapi': '3.0.3',
        'info': {
            'title': 'FPP API',
            'description': 'Falcon Player (FPP) REST API',
            'version': '1.0',
        },
        'servers': [{'url': '/', 'description': 'Local FPP instance'}],
        'tags': [{'name': t} for t in tags],
        'paths': {},
    }

    by_path = defaultdict(list)
    for ep in endpoints:
        by_path[ep['path']].append(ep)

    for path in sorted(by_path):
        eps = by_path[path]
        params = extract_path_params(path)
        path_item = {}

        if params:
            path_item['parameters'] = [
                {'name': p, 'in': 'path', 'required': True, 'schema': {'type': 'string'}}
                for p in params
            ]

        for ep in sorted(eps, key=lambda e: e['method']):
            method        = ep['method']
            tag           = tag_from_path(path)
            route_slug    = path.removeprefix(API_PREFIX).lstrip('/')
            summary       = ep['summary'] if ep['summary'] else route_slug

            operation = {
                'tags':    [tag],
                'summary': summary,
            }

            if ep['description']:
                operation['description'] = ep['description']

            if ep['badges']:
                operation['x-badges'] = ep['badges']

            if ep['params']:
                operation['parameters'] = [
                    {k: v for k, v in p.items() if v is not None}
                    for p in ep['params']
                ]

            if ep['body_raw']:
                body_val = parse_json_value(ep['body_raw'])
                if isinstance(body_val, str):
                    content = {'text/plain': {'schema': {'type': 'string'}, 'example': body_val}}
                else:
                    content = {
                        'application/json': {
                            'schema': {'type': 'array' if isinstance(body_val, list) else 'object'},
                            'example': body_val,
                        }
                    }
                operation['requestBody'] = {'content': content}

            responses = {}
            if ep['responses']:
                seen = set()
                for status, raw in ep['responses']:
                    if status in seen:
                        continue
                    seen.add(status)
                    val = parse_json_value(raw)
                    if isinstance(val, str):
                        responses[str(status)] = {'description': val}
                    else:
                        desc = 'Success' if status == 200 else f'HTTP {status}'
                        responses[str(status)] = {
                            'description': desc,
                            'content': {
                                'application/json': {
                                    'schema': {'type': 'array' if isinstance(val, list) else 'object'},
                                    'example': val,
                                }
                            },
                        }
            else:
                responses['200'] = {'description': 'Success'}

            operation['responses'] = responses
            path_item[method] = operation

        spec['paths'][path] = path_item

    return spec


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    endpoints = load_endpoints()
    if not endpoints:
        print('ERROR: No @route annotations found. Are you running from www/api/?', file=sys.stderr)
        sys.exit(1)

    spec = build_openapi(endpoints)
    OUTPUT.write_text(json.dumps(spec, indent=2), encoding='utf-8')

    path_count = len(set(e['path'] for e in endpoints))
    op_count   = len(endpoints)
    print(f'Written {OUTPUT} ({path_count} paths, {op_count} operations)')


if __name__ == '__main__':
    main()
