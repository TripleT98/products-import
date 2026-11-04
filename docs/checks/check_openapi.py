"""Validate the public OpenAPI contract and its synthetic examples, without XLSX access."""
import argparse
import json
from pathlib import Path


def check_contract(path):
    from openapi_spec_validator import validate_spec
    from openapi_schema_validator import OAS30Validator
    spec = json.loads(path.read_text(encoding="utf-8"))
    validate_spec(spec)
    schemas = spec["components"]["schemas"]
    def expand(value):
        if isinstance(value, list):
            return [expand(v) for v in value]
        if not isinstance(value, dict):
            return value
        if "$ref" in value:
            assert value["$ref"].startswith("#/components/schemas/")
            return expand(schemas[value["$ref"].split("/")[-1]])
        return {k: expand(v) for k, v in value.items()}
    count = 0
    def examples(node):
        nonlocal count
        if isinstance(node, dict):
            if "schema" in node and "example" in node:
                OAS30Validator(expand(node["schema"])).validate(node["example"])
                count += 1
            for v in node.values():
                examples(v)
        elif isinstance(node, list):
            for v in node:
                examples(v)
    examples(spec)
    expected = {"/api/health": {"get"}, "/api/auth/login": {"post"},
                "/api/products": {"get"}, "/api/products/{id}": {"get"},
                "/api/imports": {"post"}, "/api/imports/{id}": {"get"},
                "/api/imports/{id}/issues": {"get"}, "/api/_diagnostics": {"get"}}
    assert set(spec["paths"]) == set(expected)
    for route, methods in expected.items():
        assert methods == set(spec["paths"][route])
    print(f"OpenAPI 3.0.3 valid; 8 operations; {count} examples valid")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--spec", type=Path, default=Path(__file__).resolve().parents[1] / "openapi.json",
                        help="Path to the OpenAPI JSON contract.")
    args = parser.parse_args()
    check_contract(args.spec)
