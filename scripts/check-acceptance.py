"""T29 clean local copy, README startup, original workbook acceptance and all check commands."""
import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    parser.add_argument('--xlsx', type=Path, required=True, help='Path to the control XLSX workbook (not included in the repository).')
    args = parser.parse_args()
    source = Path(args.xlsx).resolve(strict=True)
    original_hash = hashlib.sha256(source.read_bytes()).hexdigest()
    module_spec = importlib.util.spec_from_file_location('safety', ROOT / 'scripts/check-git-safety.py')
    safety = importlib.util.module_from_spec(module_spec)
    module_spec.loader.exec_module(safety)
    files = safety.scan()
    run_id = secrets.token_hex(4)
    work = ROOT / 'tmp/t29-acceptance' / run_id
    checkout = work / 'checkout'
    checkout.mkdir(parents=True)
    manifest = {}
    for name in files:
        destination = checkout / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, destination)
        manifest[name] = hashlib.sha256(destination.read_bytes()).hexdigest()
    (work / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    assert not any((checkout / p).exists() for p in ['.env', 'backend/vendor', 'frontend/node_modules', 'tmp', 'uploads', '.git'])
    report = {'task': 'T29', 'result': 'failed', 'runDirectory': str(work.relative_to(ROOT)).replace('\\', '/'),
              'copy': {'files': len(files), 'cleanAtStart': True, 'remoteUsed': False}, 'checks': [], 'sourceSha256': original_hash}
    started = time.monotonic()
    projects = []
    env = {**os.environ, 'PYTHONUTF8': '1', 'PYTHONUNBUFFERED': '1'}
    env.pop('COMPOSE_PROJECT_NAME', None)

    def command(name, argv, timeout=1500, command_env=None, command_cwd=None):
        print('START: ' + name, flush=True)
        with (work / (name + '.log')).open('w', encoding='utf-8') as log:
            result = subprocess.run(argv, cwd=command_cwd or checkout, env=command_env or env, stdout=log, stderr=subprocess.STDOUT, timeout=timeout)
        report['checks'].append({'name': name, 'exitCode': result.returncode})
        if result.returncode:
            raise RuntimeError(name + ' failed; see acceptance run log (no working data changed).')
        print('PASS: ' + name, flush=True)

    def project(kind):
        name = 'product-import-acceptance-' + kind + '-' + run_id
        settings = (checkout / '.env.example').read_text(encoding='utf-8')
        changes = {'COMPOSE_PROJECT_NAME': name, 'HTTP_PORT': '0', 'POSTGRES_PASSWORD': secrets.token_hex(32),
                   'RABBITMQ_DEFAULT_PASS': secrets.token_hex(32), 'JWT_SECRET': secrets.token_hex(48)}
        import re
        for key, value in changes.items():
            settings = re.sub('(?m)^' + key + '=.*$', key + '=' + value, settings)
        envfile = checkout / '.env' if kind == 'demo' else work / 'source.env'
        envfile.write_text(settings, encoding='utf-8')
        child_env = {**env, 'COMPOSE_PROJECT_NAME': name, 'HTTP_PORT': '0'}
        prefix = [args.docker, 'compose', '-p', name, '--env-file', str(envfile), '-f', str(checkout / 'compose.yaml')]

        def run(*argv, payload=None, timeout=1200):
            result = subprocess.run(prefix + list(argv), cwd=checkout, env=child_env, input=payload, capture_output=True,
                                    text=True, encoding='utf-8', errors='replace', timeout=timeout)
            with (work / (kind + '-compose.log')).open('a', encoding='utf-8') as log:
                log.write(result.stdout + result.stderr)
            if result.returncode:
                raise RuntimeError(kind + ' compose failed: ' + ' '.join(argv[:3]) + '; see private run log')
            return result.stdout

        cfg = json.loads(run('config', '--format', 'json'))
        # Do not retain interpolated secrets from config output.
        (work / (kind + '-compose.log')).write_text('', encoding='utf-8')
        assert cfg['name'] == name and name.startswith('product-import-acceptance-')
        assert set(cfg['services']) == {'app', 'worker', 'db', 'nginx', 'rabbitmq'}
        assert all(not s.get('ports') for n, s in cfg['services'].items() if n != 'nginx')
        assert all(p['host_ip'] == '127.0.0.1' for p in cfg['services']['nginx']['ports'])
        assert all(not v.get('external') and v['name'].startswith(name + '_') for v in cfg['volumes'].values())
        assert all(v['type'] == 'volume' for s in cfg['services'].values() for v in s.get('volumes', []))
        projects.append((name, run))
        run('config', '--quiet')
        run('build', 'app', 'worker', 'nginx')
        run('up', '-d', '--wait', '--wait-timeout', '180', 'app', 'db', 'rabbitmq', 'nginx')
        run('exec', '-T', 'app', 'php', 'bin/console', 'migrations:migrate', '--no-interaction')
        run('exec', '-T', 'app', 'php', 'bin/console', 'orm:validate-schema')
        port = run('port', 'nginx', '80').strip().rsplit(':', 1)[1]
        return name, run, child_env, 'http://127.0.0.1:' + port

    def sql(run, query):
        return run('exec', '-T', 'db', 'psql', '-X', '-U', 'product_import', '-d', 'product_import', '-tA', '-c', query).strip()

    def snapshot(run):
        data = json.loads(sql(run, "SELECT json_build_object('products',(SELECT count(*) FROM products),'attributes',(SELECT count(*) FROM product_attributes),'images',(SELECT count(*) FROM product_images),'discount3333',(SELECT count(*) FROM products WHERE discount=33.33),'users',(SELECT count(*) FROM users))"))
        identities = sql(run, "SELECT id||':'||external_code FROM products ORDER BY id")
        data['identityDigest'] = hashlib.sha256(identities.encode()).hexdigest()
        return data

    def api(origin, path, token=None, data=None, content_type='application/json'):
        headers = {'Authorization': 'Bearer ' + token} if token else {}
        if data is not None:
            headers['Content-Type'] = content_type
        request = urllib.request.Request(origin + path, data=data, headers=headers)
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read())

    def original_acceptance():
        print('START: separate original XLSX database (no catalog fixtures)', flush=True)
        _, run, _, origin = project('source')
        assert snapshot(run)['products'] == 0
        # Only seed an authenticated test owner; never add product fixtures to this database.
        seed = "<?php require 'vendor/autoload.php'; $db=App\\Infrastructure\\DoctrineFactory::connection(); $db->insert('users',['username'=>'acceptance-owner','password_hash'=>password_hash('acceptance-only-password',PASSWORD_BCRYPT)]);"
        run('exec', '-T', 'app', 'php', payload=seed)
        token = api(origin, '/api/auth/login', data=json.dumps({'username': 'acceptance-owner', 'password': 'acceptance-only-password'}).encode())['accessToken']
        boundary = 'acceptance-' + secrets.token_hex(12)
        body = ('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="acceptance.xlsx"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n').encode() + source.read_bytes() + ('\r\n--' + boundary + '--\r\n').encode()
        results = []
        for number in [1, 2]:
            if number == 2:
                run('stop', 'worker')
            accepted = api(origin, '/api/imports', token, body, 'multipart/form-data; boundary=' + boundary)
            assert accepted['status'] == 'queued'
            job_id = accepted['jobId']
            assert api(origin, '/api/imports/' + job_id, token)['status'] == 'queued'
            if number == 1:
                assert snapshot(run)['products'] == 0
            run('up', '-d', 'worker')
            deadline = time.monotonic() + 2400
            last_progress = None
            while time.monotonic() < deadline:
                job = api(origin, '/api/imports/' + job_id, token)
                progress = (job['status'], job['processedRows'], job['warningCount'])
                if progress != last_progress:
                    print(f'ORIGINAL pass{number}: {progress[0]} rows={progress[1]}/40 warnings={progress[2]}', flush=True)
                    last_progress = progress
                if job['status'] in ['completed', 'completed_with_errors', 'failed']:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Original XLSX worker timed out; incomplete, not accepted.')
            assert job['status'] != 'failed', 'Original XLSX job failed'
            assert job['processedRows'] == job['totalRows'] == 40 and job['failedRows'] == 0
            assert job['createdProducts'] == (40 if number == 1 else 0)
            assert job['updatedProducts'] == (0 if number == 1 else 40)
            counts = snapshot(run)
            assert counts['products'] == counts['discount3333'] == 40 and counts['attributes'] == 695
            if results:
                assert counts['identityDigest'] == results[0]['counts']['identityDigest']
            warnings = json.loads(sql(run, "SELECT COALESCE(json_object_agg(code,n),'{}'::json) FROM (SELECT code,count(*) n FROM import_issues WHERE job_id='" + job_id + "' GROUP BY code) t"))
            results.append({'job': job, 'counts': counts, 'issueCodes': warnings})
        assert hashlib.sha256(source.read_bytes()).hexdigest() == original_hash
        print('PASS: original 40/695/33.33 and repeat40 updated; original unchanged.', flush=True)
        return {'result': 'passed', 'fixturesMixed': False, 'passes': results, 'sourceUnchanged': True, 'imageLimits': 'production defaults, real external URLs'}

    try:
        _, demo, demo_env, origin = project('demo')
        for _ in range(2):
            demo('exec', '-T', '--user', 'www-data', '-e', 'APP_ENV=development', 'app', 'php', 'bin/console', 'fixtures:load')
        demo('up', '-d', 'worker')
        before = snapshot(demo)
        assert (before['products'], before['attributes'], before['images'], before['users']) == (48, 96, 48, 1)
        command('persistence', [sys.executable, '-X', 'utf8', 'scripts/check-persistence.py', '--docker', args.docker], command_env=demo_env)
        after = snapshot(demo)
        assert before == after
        report['startup'] = {'result': 'passed', 'fixturesTwice': True, 'demoCounts': after, 'persistence': True}
        # Browser verifies real same-origin production deep link and reload after restart.
        tooling = work / 'browser'
        tooling.mkdir()
        for name in ['package.json', 'package-lock.json']:
            shutil.copyfile(checkout / 'e2e' / name, tooling / name)
        shutil.copyfile(checkout / 'frontend/browser-tests/acceptance-demo.spec.cjs', tooling / 'acceptance-demo.spec.cjs')
        command('browser-tooling', [shutil.which('npm.cmd') or shutil.which('npm'), 'ci', '--prefix', str(tooling), '--ignore-scripts', '--no-audit', '--no-fund'])
        # Persistence may assign a different random host port on up.
        origin = 'http://127.0.0.1:' + demo('port', 'nginx', '80').strip().rsplit(':', 1)[1]
        command('demo-deep-link', [shutil.which('node'), str(tooling / 'node_modules/@playwright/test/cli.js'), 'test', 'acceptance-demo.spec.cjs', '--workers=1', '--reporter=line'], command_env={**env, 'AUTH_TEST_ORIGIN': origin}, command_cwd=tooling)
        report['startup']['deepLinkReloadLocalPng'] = True
        with ThreadPoolExecutor(max_workers=1) as pool:
            source_future = pool.submit(original_acceptance)
            suite = [('quality', 'verify.py', ['check']), ('test', 'verify.py', ['test']),
                     ('auth-api', 'check-auth-http.py', ['--products']), ('worker', 'check-worker.py', []),
                     ('upload', 'check-upload.py', []), ('import-api', 'check-import-api.py', []),
                     ('recovery', 'check-recovery.py', []), ('frontend', 'check-frontend.py', []),
                     ('auth-ui', 'check-auth-ui.py', []), ('products-ui', 'check-products-ui.py', []),
                     ('detail-ui', 'check-product-detail-ui.py', []), ('import-ui', 'check-import-ui.py', []),
                     ('report-ui', 'check-report-ui.py', []), ('e2e', 'check-e2e.py', []),
                     ('workflow', 'check-workflow.py', []), ('docker-build', 'check-docker-build.py', []),
                     ('docs', 'check-docs.py', []),
                     ('workbook', 'check-workbook.py', ['--xlsx', str(source), '--sample', '--validate'])]
            failures = []
            for name, script, extra in suite:
                try:
                    command(name, [sys.executable, '-X', 'utf8', 'scripts/' + script, *extra, '--docker', args.docker])
                except RuntimeError as error:
                    failures.append(str(error))
            report['original'] = source_future.result()
            if failures:
                raise RuntimeError('; '.join(failures))
        report['result'] = 'passed'
        print('PASS: T29 clean copy/startup, persistence/deep links, original/repeat and all checks.', flush=True)
    except Exception as error:
        report['error'] = str(error)
        raise
    finally:
        clean = True
        for name, run in reversed(projects):
            try:
                run('down', '-v', '--remove-orphans')
            except Exception:
                clean = False
        report['cleanup'] = 'passed' if clean else 'failed'
        if not clean:
            report['result'] = 'failed'
        report['elapsedSeconds'] = round(time.monotonic() - started, 2)
        report['sourceUnchanged'] = hashlib.sha256(source.read_bytes()).hexdigest() == original_hash
        encoded = json.dumps(report, ensure_ascii=False, indent=2) + '\n'
        (work / 'verification.json').write_text(encoded, encoding='utf-8')
        (ROOT / 'tmp/verification-acceptance.json').write_text(encoded, encoding='utf-8')
        if not clean:
            raise RuntimeError('Acceptance cleanup failed; see project names in private run logs.')


if __name__ == '__main__':
    main()
