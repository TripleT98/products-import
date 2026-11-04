"""Validate GitHub workflow with pinned actionlint/ShellCheck in a read-only Docker container."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
IMAGE = 'rhysd/actionlint@sha256:887a259a5a534f3c4f36cb02dca341673c6089431057242cdc931e9f133147e9'


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    report = {'task': 'T27', 'action': 'workflow-validation', 'validator': IMAGE, 'result': 'failed'}
    try:
        subprocess.run([args.docker, 'run', '--rm', '--network', 'none', '--volume', str(ROOT) + ':/repo:ro',
                        '--workdir', '/repo', IMAGE, '-oneline', '.github/workflows/ci.yml'], cwd=ROOT, check=True)
        report['result'] = 'passed'
        print('PASS: workflow YAML, GitHub Actions expressions and embedded shell (actionlint/ShellCheck).', flush=True)
    finally:
        (ROOT / 'tmp').mkdir(exist_ok=True)
        (ROOT / 'tmp/verification-workflow.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
