"""Build production Compose targets with distinct CI tags; never start application services."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-ci-build'


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    env = {**os.environ, 'COMPOSE_PROJECT_NAME': PROJECT}
    prefix = [args.docker, 'compose', '-p', PROJECT, '--env-file', str(ROOT / '.env.example'), '-f', str(ROOT / 'compose.yaml')]
    config = json.loads(subprocess.check_output(prefix + ['config', '--format', 'json'], cwd=ROOT, env=env, text=True))
    if config['name'] != PROJECT:
        raise RuntimeError('Wrong build project')
    images = {}
    for name in ['app', 'worker', 'nginx']:
        service = config['services'][name]
        if service['build']['target'] != 'production' or not service['image'].startswith(PROJECT + '-'):
            raise RuntimeError('Refusing to overwrite an application image: ' + name)
        images[name] = service['image']
    report = {'task': 'T27', 'action': 'docker-build', 'project': PROJECT, 'result': 'failed', 'images': images}
    started = time.monotonic()
    try:
        subprocess.run(prefix + ['build', 'app', 'worker', 'nginx'], cwd=ROOT, env=env, check=True)
        report['result'] = 'passed'
        print('PASS: production app/worker/nginx build; no containers started, no registry push.', flush=True)
    finally:
        report['elapsedSeconds'] = round(time.monotonic() - started, 2)
        (ROOT / 'tmp').mkdir(exist_ok=True)
        (ROOT / 'tmp/verification-docker-build.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
