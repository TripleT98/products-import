"""T26: one command, real browser/API/worker/PG/RabbitMQ, synthetic data only."""
import argparse
import base64
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time
from uuid import uuid4

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-e2e-check'


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    node = shutil.which('node')
    npm = shutil.which('npm.cmd') or shutil.which('npm')
    if not node or not npm:
        raise RuntimeError('Node/npm are required; default browser is installed Microsoft Edge.')
    prefix = [args.docker, 'compose', '-p', PROJECT, '--env-file', str(ROOT / '.env.test.example')]
    for name in ['worker', 'upload', 'auth-ui', 'e2e']:
        prefix += ['-f', str(ROOT / ('compose.' + name + '-test.yaml'))]
    work = ROOT / 'tmp/t26-e2e'
    runtime = work / 'runtime'
    run_id = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + uuid4().hex[:8]
    artifacts = work / 'runs' / run_id
    runtime.mkdir(parents=True, exist_ok=True)
    artifacts.mkdir(parents=True, exist_ok=True)

    def run(*command, check=True, timeout=1200):
        result = subprocess.run(prefix + list(command), cwd=ROOT, capture_output=True, text=True,
                                encoding='utf-8', errors='replace', timeout=timeout)
        if check and result.returncode:
            raise RuntimeError('E2E compose command failed: ' + ' '.join(command) + '\n' + result.stdout[-4000:] + result.stderr[-4000:])
        return result

    config = json.loads(run('config', '--format', 'json').stdout)
    # Validate isolation before any compose mutation, including cleanup.
    assert config['name'] == PROJECT
    assert set(config['services']) == {'db-test', 'rabbitmq-test', 'fixtures', 'worker', 'app', 'nginx'}
    assert all(not s.get('ports') for name, s in config['services'].items() if name != 'nginx')
    assert all(p['host_ip'] == '127.0.0.1' for p in config['services']['nginx']['ports'])
    assert config['networks']['tests']['internal']
    assert all(not n.get('external') for n in config['networks'].values())
    assert all(not v.get('external') and v['name'].startswith(PROJECT + '_worker-') for v in config['volumes'].values())
    assert all(v['type'] == 'volume' for s in config['services'].values() for v in s.get('volumes', []))
    assert config['services']['db-test']['environment']['POSTGRES_DB'] == 'product_import_test'
    assert config['services']['db-test']['tmpfs'] and config['services']['rabbitmq-test']['tmpfs']
    report = {'task': 'T26', 'project': PROJECT, 'result': 'failed', 'artifacts': artifacts.relative_to(ROOT).as_posix()}
    started = time.monotonic()
    try:
        for name in ['package.json', 'package-lock.json', 'playwright.config.cjs', 'import-flow.spec.cjs']:
            shutil.copyfile(ROOT / 'e2e' / name, runtime / name)
        print('Installing locked Playwright tooling in ignored tmp; application dependencies unchanged.', flush=True)
        installed = subprocess.run([npm, 'ci', '--ignore-scripts', '--no-audit', '--no-fund'], cwd=runtime,
                                   capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=180)
        (artifacts / 'npm.log').write_text(installed.stdout + '\n' + installed.stderr, encoding='utf-8')
        if installed.returncode:
            raise RuntimeError('Locked e2e npm ci failed; see npm.log')
        run('down', '-v', '--remove-orphans')
        print('Building production app/worker/nginx with isolated PostgreSQL/RabbitMQ.', flush=True)
        built = run('build', 'fixtures', 'app', 'nginx', 'worker')
        (artifacts / 'build.log').write_text(built.stdout + '\n' + built.stderr, encoding='utf-8')
        run('up', '-d', '--wait', '--wait-timeout', '150', 'db-test', 'rabbitmq-test', 'fixtures', 'app', 'nginx')
        run('exec', '-T', 'fixtures', 'php', 'tests/Support/e2e-fixture.php')
        for name in ['e2e-initial.xlsx', 'e2e-update.xlsx']:
            encoded = run('exec', '-T', 'fixtures', 'php', '-r', 'echo base64_encode(file_get_contents($argv[1]));', '/tmp/' + name).stdout
            (work / name).write_bytes(base64.b64decode(encoded))
        port = run('port', 'nginx', '80').stdout.strip().rsplit(':', 1)[1]
        env = {**os.environ, 'E2E_ORIGIN': 'http://127.0.0.1:' + port, 'E2E_ARTIFACT_DIR': str(artifacts),
               'E2E_WORKBOOK_DIR': str(work), 'E2E_COMPOSE_ARGS': json.dumps(prefix[1:]), 'E2E_DOCKER': args.docker}
        print('Running real login/upload/worker/report/list/card/repeat/update; no import mocks. Artifacts: ' + report['artifacts'], flush=True)
        try:
            browser = subprocess.run([node, str(runtime / 'node_modules/@playwright/test/cli.js'), 'test', '--config=playwright.config.cjs'],
                                     cwd=runtime, env=env, capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=240)
        except subprocess.TimeoutExpired as error:
            def decoded(value):
                return value.decode('utf-8', errors='replace') if isinstance(value, bytes) else value or ''
            (artifacts / 'browser.log').write_text(decoded(error.stdout) + '\n' + decoded(error.stderr), encoding='utf-8')
            raise
        (artifacts / 'browser.log').write_text(browser.stdout + '\n' + browser.stderr, encoding='utf-8')
        print(browser.stdout, flush=True)
        if browser.returncode:
            raise RuntimeError('E2E failed; inspect artifacts/browser.log, results.json, html and test-results.')
        result = json.loads((artifacts / 'results.json').read_text(encoding='utf-8'))
        assert result['stats']['expected'] == 1 and result['stats']['unexpected'] == 0 and result['stats']['skipped'] == 0
        report.update(result='passed', browser='Playwright1.56.1/' + env.get('E2E_BROWSER_CHANNEL', 'msedge'),
                      realImportChain=True, mocks=False, guardLoginLogout=True, stoppedWorkerEmpty=True,
                      initial={'rows': 25, 'created': 25}, repeat={'created': 0, 'updated': 25, 'total': 25},
                      update={'created': 1, 'updated': 1, 'failed': 1, 'warnings': 1, 'total': 26},
                      pages=[20, 5], filtersAndRefresh=True, localImage=True, attributesReplacement=True,
                      importContext=True, persistedDataAndReport=True, playwrightStats=result['stats'])
        print('PASS: T26 real end-to-end import, repeat/update, persisted data/report, pages/filters/card/context.', flush=True)
    except Exception as error:
        report['error'] = str(error)
        (artifacts / 'runner-error.txt').write_text(str(error) + '\n', encoding='utf-8')
        raise
    finally:
        # Preserve diagnostics before removing only this verified disposable project's resources.
        try:
            logs = run('logs', '--no-color', '--tail', '250', check=False, timeout=60)
            (artifacts / 'services.log').write_text(logs.stdout + '\n' + logs.stderr, encoding='utf-8')
        finally:
            try:
                run('down', '-v', '--remove-orphans', timeout=120)
                report['cleanup'] = 'passed'
            except Exception:
                report.update(cleanup='failed', result='failed')
                raise
            finally:
                report['elapsedSeconds'] = round(time.monotonic() - started, 2)
                encoded = json.dumps(report, indent=2) + '\n'
                (artifacts / 'verification.json').write_text(encoded, encoding='utf-8')
                (work / 'latest.json').write_text(encoded, encoding='utf-8')
                (ROOT / 'tmp/verification-e2e.json').write_text(encoded, encoding='utf-8')


if __name__ == '__main__':
    main()
