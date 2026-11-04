"""Run the development XLSX diagnostic in an offline production container; never copy the source into Git."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--xlsx', required=True)
    parser.add_argument('--sample', action='store_true', help='Check the original T01 sample mapping and 40 rows')
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    parser.add_argument('--validate', action='store_true', help='Also check T10 normalization/DTO/issue counts')
    args = parser.parse_args()
    tag = 'product-import-workbook:t10' if args.validate else 'product-import-workbook:t09'
    path = Path(args.xlsx).resolve(strict=True)
    before = hashlib.sha256(path.read_bytes()).hexdigest()
    report = {'task': 'T10' if args.validate else 'T09', 'result': 'failed', 'sourceBytes': path.stat().st_size, 'sha256Before': before}
    try:
        subprocess.run([args.docker, 'build', '--target', 'production', '-f', 'docker/backend/Dockerfile', '-t', tag, '.'], cwd=ROOT, check=True)
        command = [args.docker, 'run', '--rm', '--network', 'none', '--volume', str(path) + ':/input/workbook.xlsx:ro',
                   '-e', 'APP_ENV=development', '-e', 'DB_HOST=must-not-connect.invalid',
                   tag, 'php', 'bin/console', 'workbook:inspect', '/input/workbook.xlsx']
        if args.validate:
            command.append('--validate')
        result = subprocess.run(command, cwd=ROOT, text=True, encoding='utf-8', stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        require(result.returncode == 0, 'Workbook diagnostic failed: ' + result.stdout + result.stderr)
        data = json.loads(result.stdout)
        require(data['rowCount'] >= 0, 'Invalid row count')
        if args.sample:
            require(before == '3209584a1b22c7948ec43ff9083381dfb6a0d24bc0c1ecb1f6fd72c4db9b2588', 'Sample hash differs from T01')
            require(data['rowCount'] == 40 and data['firstRow'] == 2 and data['lastRow'] == 41, 'Sample row numbers/count differ')
            require(data['sheetName'] == 'Лист1', 'Sample sheet differs')
            require(data['mapping']['fields'] == {'name': 'E', 'externalCode': 'F', 'price': 'I', 'description': 'K', 'purchasePrice': 'L'}, 'Sample field mapping differs')
            require(len(data['mapping']['attributes']) == 19 and len(data['mapping']['ignored']) == 26, 'Sample column counts differ')
        if args.validate and args.sample:
            expected = {'validRows': 40, 'invalidRows': 0, 'attributeCount': 695, 'imageCandidateCount': 131,
                        'uniqueImageUrls': 28, 'discounts': {'33.33': 40}, 'errors': 0, 'warnings': 0, 'issuesByCode': {}}
            require(data['validation'] == expected, 'Sample validation counts differ')
        after = hashlib.sha256(path.read_bytes()).hexdigest()
        require(after == before == data['sha256'], 'Source XLSX changed')
        report.update(result='passed', diagnostic=data, sha256After=after, sourceUnchanged=True, network='none', databaseUsed=False)
        print(json.dumps(data, ensure_ascii=True, indent=2))
        print('PASS: XLSX ' + ('40 valid DTO / 695 attributes / 131 candidates / 28 URLs / discount 33.33; ' if args.validate and args.sample else 'mapping/count; ') + 'offline container; no database; original SHA-256 unchanged.')
    finally:
        (ROOT / 'tmp').mkdir(exist_ok=True)
        (ROOT / 'tmp' / ('workbook-validation-check.json' if args.validate else 'workbook-check.json')).write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
