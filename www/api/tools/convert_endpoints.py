#!/usr/bin/env python3
"""One-time converter: endpoints.json -> openapi.yaml"""

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).parent.parent
INPUT = ROOT / "endpoints.json"
OUTPUT = ROOT / "openapi.yaml"


def path_to_openapi(endpoint):
    """Convert :Param to {Param} style."""
    return "/" + re.sub(r":([a-zA-Z_][a-zA-Z0-9_]*)", r"{\1}", endpoint)


def extract_params(path):
    """Return list of {name, in, required, schema} for path params."""
    return [
        {
            "name": p,
            "in": "path",
            "required": True,
            "schema": {"type": "string"},
        }
        for p in re.findall(r"\{([^}]+)\}", path)
    ]


def tag_from_endpoint(endpoint):
    return endpoint.split("/")[0]


def yaml_scalar(value, indent=0):
    """Render a Python value as a YAML scalar/block, indented."""
    prefix = "  " * indent
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, str):
        # Multi-line or special chars -> block scalar
        if "\n" in value or any(c in value for c in [':', '#', '{', '}', '[', ']', ',', '&', '*', '?', '|', '-', '<', '>', '=', '!', '%', '@', '`', '"', "'"]):
            escaped = value.replace("'", "''")
            return f"'{escaped}'"
        return value
    # Complex — use json.dumps as a quoted string
    return "'" + json.dumps(value).replace("'", "''") + "'"


def needs_quoting(k):
    return any(c in str(k) for c in ': #{}[]!*&|>\'"%@`')


def to_yaml_lines(value, indent):
    """Recursively emit a Python value as YAML lines at the given indent depth."""
    prefix = "  " * indent
    lines = []
    if isinstance(value, dict):
        for k, v in value.items():
            k_safe = f"'{k}'" if needs_quoting(k) else k
            if isinstance(v, (dict, list)):
                lines.append(f"{prefix}{k_safe}:")
                lines.extend(to_yaml_lines(v, indent + 1))
            elif isinstance(v, str) and '\n' in v:
                lines.append(f"{prefix}{k_safe}: |")
                for subline in v.splitlines():
                    lines.append(f"{prefix}  {subline}")
            else:
                lines.append(f"{prefix}{k_safe}: {yaml_scalar(v)}")
    elif isinstance(value, list):
        for item in value:
            if isinstance(item, (dict, list)):
                sub = to_yaml_lines(item, indent + 1)
                first = sub[0] if sub else f"{'  ' * (indent + 1)}"
                lines.append(f"{prefix}- {first.lstrip()}")
                lines.extend(sub[1:])
            else:
                lines.append(f"{prefix}- {yaml_scalar(item)}")
    return lines


def yaml_example_block(value, indent):
    """Render a value as a native YAML 'example' block at the given indent level."""
    prefix = "  " * indent
    if isinstance(value, (dict, list)):
        inner = to_yaml_lines(value, indent + 1)
        return f"{prefix}example:\n" + "\n".join(inner)
    else:
        return f"{prefix}example: {yaml_scalar(value)}"


def build_openapi(data):
    endpoints = data["endpoints"]
    # Collect all tags
    tags = sorted(set(tag_from_endpoint(e["endpoint"]) for e in endpoints))

    lines = []

    # Header
    lines += [
        "openapi: '3.0.3'",
        "info:",
        "  title: FPP API",
        "  description: Falcon Player (FPP) REST API",
        "  version: '1.0'",
        "servers:",
        "  - url: /api",
        "    description: Local FPP instance",
        "tags:",
    ]
    for tag in tags:
        lines.append(f"  - name: {tag}")
    lines.append("")
    lines.append("paths:")

    for entry in endpoints:
        endpoint = entry["endpoint"]
        oapi_path = path_to_openapi(endpoint)
        params = extract_params(oapi_path)
        tag = tag_from_endpoint(endpoint)
        methods = entry.get("methods", {})

        lines.append(f"  '{oapi_path}':")

        # If path params are shared across methods, emit once at path level
        if params:
            lines.append("    parameters:")
            for p in params:
                lines.append(f"      - name: {p['name']}")
                lines.append(f"        in: path")
                lines.append(f"        required: true")
                lines.append(f"        schema:")
                lines.append(f"          type: string")

        for method, spec in methods.items():
            http_method = method.lower()
            desc = spec.get("desc", "")
            inp = spec.get("input")
            out = spec.get("output")

            lines.append(f"    {http_method}:")
            lines.append(f"      tags:")
            lines.append(f"        - {tag}")
            lines.append(f"      summary: '{endpoint}'")
            if desc:
                desc_safe = desc.replace("'", "''")
                lines.append(f"      description: '{desc_safe}'")

            # Request body
            if inp is not None:
                lines.append(f"      requestBody:")
                lines.append(f"        content:")
                if isinstance(inp, str):
                    lines.append(f"          text/plain:")
                    lines.append(f"            schema:")
                    lines.append(f"              type: string")
                    inp_safe = inp.replace("'", "''")
                    lines.append(f"            example: '{inp_safe}'")
                else:
                    lines.append(f"          application/json:")
                    lines.append(f"            schema:")
                    lines.append(f"              type: object")
                    lines.append(yaml_example_block(inp, indent=6))

            # Response
            lines.append(f"      responses:")
            lines.append(f"        '200':")
            if out is None:
                lines.append(f"          description: Success")
            elif isinstance(out, str):
                out_safe = out.replace("'", "''")
                lines.append(f"          description: '{out_safe}'")
            else:
                lines.append(f"          description: Success")
                lines.append(f"          content:")
                lines.append(f"            application/json:")
                lines.append(f"              schema:")
                if isinstance(out, list):
                    lines.append(f"                type: array")
                    lines.append(f"                items: {{}}")
                else:
                    lines.append(f"                type: object")
                lines.append(yaml_example_block(out, indent=7))

    return "\n".join(lines) + "\n"


def main():
    with open(INPUT) as f:
        data = json.load(f)

    output = build_openapi(data)
    with open(OUTPUT, "w") as f:
        f.write(output)

    count = len(data["endpoints"])
    print(f"Written {OUTPUT} ({count} endpoints)")


if __name__ == "__main__":
    main()
