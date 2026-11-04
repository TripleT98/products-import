"""Fetch official package metadata only; never installs project dependencies."""
import argparse
import json
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

NPM = {
    "@angular/core": "22.0.0", "@angular/compiler-cli": "22.0.0",
    "@angular/cli": "22.0.0", "@angular/build": "22.0.0",
    "@angular/material": "22.0.0", "@angular/cdk": "22.0.0",
    "@ngrx/store": "22.0.0", "@ngrx/effects": "22.0.0",
    "typescript": "6.0.2", "rxjs": "7.8.2", "vitest": "4.0.8",
    "@playwright/test": "1.56.1",
}
COMPOSER = {
    "slim/slim": "4.15.3", "slim/psr7": "1.8.0",
    "doctrine/orm": "3.7.6", "doctrine/dbal": "4.5.0", "doctrine/migrations": "3.9.8",
    "symfony/messenger": "8.1.10", "symfony/amqp-messenger": "8.1.9",
    "symfony/console": "8.1.10", "symfony/lock": "8.1.10",
    "symfony/rate-limiter": "8.1.10",
    "phpoffice/phpspreadsheet": "5.10.0", "brick/math": "1.0.0",
    "firebase/php-jwt": "7.2.1", "phpunit/phpunit": "13.4.5",
    "phpstan/phpstan": "2.3.4", "friendsofphp/php-cs-fixer": "3.95.27",
}


def fetch(url):
    request = urllib.request.Request(url, headers={"User-Agent": "product-import-T01-metadata-check"})
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    report = {"checkedAt": datetime.now(timezone.utc).isoformat(), "npm": {}, "composer": {}}
    for name, version in NPM.items():
        url = f"https://registry.npmjs.org/{name}/{version}"
        data = fetch(url)
        assert data["version"] == version
        report["npm"][name] = {"version": version, "source": url,
                               "engines": data.get("engines", {}),
                               "peerDependencies": data.get("peerDependencies", {})}
    for name, version in COMPOSER.items():
        url = f"https://repo.packagist.org/p2/{name}.json"
        data = fetch(url)["packages"][name]
        # Packagist minifies metadata by inheriting omitted keys from preceding releases.
        effective = {}
        found = False
        for release in data:
            for key, value in release.items():
                if value == "__unset":
                    effective.pop(key, None)
                else:
                    effective[key] = value
            if release["version"].lstrip("v") == version:
                report["composer"][name] = {"version": version, "source": url,
                                            "require": effective.get("require", {}),
                                            "conflict": effective.get("conflict", {})}
                found = True
                break
        assert found, (name, version)
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Metadata verified: {len(NPM)} npm and {len(COMPOSER)} Composer releases")
