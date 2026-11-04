"""Read-only inventory/token/private-file scan of worktree, index and reachable Git history."""
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
SOURCE_HASH = '3209584a1b22c7948ec43ff9083381dfb6a0d24bc0c1ecb1f6fd72c4db9b2588'
PATTERNS = [rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', rb'gh[pousr]_[A-Za-z0-9]{36,}',
            rb'github_pat_[A-Za-z0-9_]{50,}', rb'AKIA[0-9A-Z]{16}']


def inventory():
    raw = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT)
    return sorted(set(raw.decode('utf-8').strip('\0').split('\0')))


def scan():
    findings = []

    def inspect(name, data, location):
        path = Path(name)
        forbidden = path.name in {'.env', 'auth.json', '.npmrc', '.git-credentials'} or (
            path.name.startswith('.env.') and not path.name.endswith('.example'))
        forbidden |= path.suffix.lower() in {'.xlsx', '.xls', '.xlsm', '.pem', '.key', '.p12', '.pfx', '.dump', '.sqlite'}
        forbidden |= any(p in {'node_modules', 'vendor', 'uploads', 'storage', 'tmp', 'logs'} for p in path.parts)
        if forbidden or hashlib.sha256(data).hexdigest() == SOURCE_HASH:
            findings.append({'path': name, 'location': location, 'kind': 'private/runtime file'})
        if any(re.search(pattern, data) for pattern in PATTERNS):
            findings.append({'path': name, 'location': location, 'kind': 'credential/private-key pattern'})

    files = inventory()
    for name in files:
        if (ROOT / name).is_symlink():
            findings.append({'path': name, 'location': 'worktree', 'kind': 'symlink requires review'})
        else:
            inspect(name, (ROOT / name).read_bytes(), 'worktree')
    indexed = subprocess.check_output(['git', 'ls-files', '--cached', '-z'], cwd=ROOT).decode().strip('\0').split('\0')
    for name in filter(None, indexed):
        inspect(name, subprocess.check_output(['git', 'show', ':' + name], cwd=ROOT), 'index')
    objects = subprocess.check_output(['git', 'rev-list', '--objects', '--all'], cwd=ROOT, text=True).splitlines()
    blobs = 0
    for row in objects:
        parts = row.split(' ', 1)
        if len(parts) != 2:
            continue
        oid, name = parts
        if subprocess.check_output(['git', 'cat-file', '-t', oid], cwd=ROOT, text=True).strip() == 'blob':
            inspect(name, subprocess.check_output(['git', 'cat-file', 'blob', oid], cwd=ROOT), 'history')
            blobs += 1
    report = {'task': 'T29', 'result': 'failed' if findings else 'passed', 'worktreeFiles': len(files),
              'indexFiles': len(list(filter(None, indexed))), 'historyBlobs': blobs, 'findings': findings,
              'scope': 'Private paths/source hash and known credential/private-key patterns; not exhaustive entropy detection.'}
    (ROOT / 'tmp').mkdir(exist_ok=True)
    (ROOT / 'tmp/verification-git-safety.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    if findings:
        raise RuntimeError('Git safety findings require review; see verification-git-safety.json (no secret values printed).')
    print(f'PASS: Git inventory/worktree/index/history scan; {len(files)} files, {blobs} history blobs; no findings.')
    return files


if __name__ == '__main__':
    scan()
