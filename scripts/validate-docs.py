"""Validate the current OpenAPI, examples, live response samples and environment inventory."""
import argparse
import importlib.util
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def main():
    from openapi_schema_validator import OAS30Validator
    parser = argparse.ArgumentParser()
    parser.add_argument('--responses', type=Path)
    args = parser.parse_args()
    module_spec = importlib.util.spec_from_file_location('contract', ROOT / 'docs/checks/check_openapi.py')
    module = importlib.util.module_from_spec(module_spec)
    module_spec.loader.exec_module(module)
    module.check_contract(ROOT / 'docs/openapi.json')  # Public contract only; no dependency on a control workbook.
    spec = json.loads((ROOT / 'docs/openapi.json').read_text(encoding='utf-8'))

    def expand(node):
        if isinstance(node, list):
            return [expand(v) for v in node]
        if not isinstance(node, dict):
            return node
        if '$ref' in node:
            value = spec
            for key in node['$ref'][2:].split('/'):
                value = value[key]
            return expand(value)
        return {k: expand(v) for k, v in node.items()}

    keys = set(re.findall(r'^([A-Z][A-Z0-9_]*)=', (ROOT / '.env.example').read_text(encoding='utf-8'), re.M))
    documented = set(re.findall(r'^\| `([A-Z][A-Z0-9_]*)` \|', (ROOT / 'docs/environment.md').read_text(encoding='utf-8'), re.M))
    assert keys == documented, {'missing': sorted(keys - documented), 'extra': sorted(documented - keys)}
    used = set()
    for p in (ROOT / 'backend/src').rglob('*.php'):
        used.update(re.findall(r"getenv\('([A-Z][A-Z0-9_]*)'\)", p.read_text(encoding='utf-8')))
    assert used <= keys, sorted(used - keys)
    compose = (ROOT / 'compose.yaml').read_text(encoding='utf-8')
    assert set(re.findall(r'\$\{([A-Z][A-Z0-9_]*)', compose)) == keys
    live = 0
    if args.responses:
        for item in json.loads(args.responses.read_text(encoding='utf-8')):
            operation = spec['paths'][item['path']][item['method'].lower()]
            response = expand(operation['responses'][str(item['status'])])
            OAS30Validator(expand(response['content']['application/json']['schema'])).validate(item['body'])
            assert item['requestId'] and 'application/json' in item['contentType']
            live += 1
    print(f'PASS: OpenAPI/examples; {len(keys)} environment variables; {live} real response samples.')


if __name__ == '__main__':
    main()
