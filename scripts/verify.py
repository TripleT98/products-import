"""Run project checks in a separate Compose project, never in application services."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-tests'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['test', 'check'])
    parser.add_argument('--docker', default=shutil.which('docker') or
                        'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    compose = [args.docker, 'compose', '-p', PROJECT, '--env-file', str(ROOT / '.env.test.example'),
               '-f', str(ROOT / 'compose.test.yaml')]

    def run(*command, capture=False):
        print('+ docker compose [isolated tests] ' + ' '.join(command), flush=True)
        return subprocess.run(compose + list(command), cwd=ROOT, check=True, text=True,
                              stdout=subprocess.PIPE if capture else None).stdout

    configuration = json.loads(run('config', '--format', 'json', capture=True))
    def require(condition, message):
        if not condition:
            raise RuntimeError(message)

    require(configuration['name'] == PROJECT, 'Wrong test project')
    require(set(configuration['services']) == {'db-test', 'backend-tests', 'frontend-tests'},
            'Unexpected test services')
    require(not configuration.get('volumes'), 'Persistent volumes forbidden in test project')
    require(all(not service.get('ports') and not service.get('volumes')
                for service in configuration['services'].values()), 'Ports/bind volumes forbidden')
    require(all(network.get('internal') for network in configuration['networks'].values()),
            'Test networks must be internal')
    database = configuration['services']['db-test']
    require(database['environment']['POSTGRES_DB'] == 'product_import_test', 'Wrong database')
    require('/var/lib/postgresql' in database['tmpfs'], 'Test database must use tmpfs')

    report = {'action': args.action, 'project': PROJECT, 'result': 'failed'}
    started = time.monotonic()
    try:
        # Start from a fresh tmpfs database after an interrupted previous test run.
        run('down')
        run('build', 'backend-tests', 'frontend-tests')
        run('up', '-d', '--wait', '--wait-timeout', '120', 'db-test')
        run('run', '--rm', '-T', '--no-deps', 'backend-tests', 'php', 'bin/console',
            'migrations:migrate', '--no-interaction')
        run('run', '--rm', '-T', '--no-deps', 'backend-tests', 'php', 'bin/console',
            'orm:validate-schema')
        if args.action == 'check':
            run('run', '--rm', '-T', '--no-deps', 'backend-tests', 'composer', 'check')
        run('run', '--rm', '-T', '--no-deps', 'backend-tests', 'composer', 'test')
        for _ in range(2):
            run('run', '--rm', '-T', '--no-deps', '-e', 'IMAGE_DIR=/tmp/t06-cli-images',
                'backend-tests', 'php', 'bin/console', 'fixtures:load')
        run('run', '--rm', '-T', '--no-deps', 'frontend-tests', 'npm',
            'run', 'check' if args.action == 'check' else 'test')
        report['result'] = 'passed'
        print('PASS: ' + args.action + '; isolated PostgreSQL; application services untouched.', flush=True)
    finally:
        try:
            run('down')
        except subprocess.CalledProcessError:
            report['result'] = 'failed'
            report['cleanup'] = 'failed'
            raise
        finally:
            report['elapsedSeconds'] = round(time.monotonic() - started, 2)
            (ROOT / 'tmp').mkdir(exist_ok=True)
            (ROOT / 'tmp' / ('verification-' + args.action + '.json')).write_text(
                json.dumps(report, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
