"""T17 real HTTP status/report, shared rate limiter and bounded readiness checks."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-api-check'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    prefix = [args.docker, 'compose', '-p', PROJECT, '--env-file', str(ROOT / '.env.test.example'),
              '-f', str(ROOT / 'compose.worker-test.yaml'), '-f', str(ROOT / 'compose.upload-test.yaml'),
              '-f', str(ROOT / 'compose.api-test.yaml')]

    def run(*command, check=True, payload=None):
        result = subprocess.run(prefix + list(command), cwd=ROOT, input=payload, capture_output=True,
                                text=True, encoding='utf-8', errors='replace', timeout=600)
        if check and result.returncode:
            raise RuntimeError('API check failed: ' + ' '.join(command) + '\n' + result.stdout[-3000:] + result.stderr[-3000:])
        return result

    def helper(action, **params):
        result = run('exec', '-T', 'fixtures', 'php', 'tests/Support/upload-http.php',
                     payload=json.dumps({'action': action, **params}))
        return json.loads(result.stdout)

    def fixture(action):
        run('exec', '-T', 'fixtures', 'php', 'tests/Support/api-fixture.php', action)

    def get(path, expected=200, **params):
        response = helper('get', path=path, **params)
        assert response['status'] == expected, response
        assert response['headers']['content-type'].startswith('application/json'), response
        assert response['headers']['cache-control'] == 'no-store', response
        assert len(response['headers']['x-request-id']) == 32, response
        return response['body']

    config = json.loads(run('config', '--format', 'json').stdout)
    assert config['name'] == PROJECT
    assert set(config['services']) == {'db-test', 'rabbitmq-test', 'fixtures', 'worker', 'app', 'nginx'}
    assert all(not s.get('ports') for s in config['services'].values())
    assert all(n.get('internal') for n in config['networks'].values())
    for volume in config['volumes'].values():
        assert not volume.get('external') and volume['name'].startswith(PROJECT + '_worker-')
    assert config['services']['app']['environment']['IMPORT_RATE_LIMIT'] == '5'
    report = {'task': 'T17', 'project': PROJECT, 'result': 'failed'}
    started = time.monotonic()
    try:
        run('down', '-v', '--remove-orphans')
        print('Building isolated production API/worker and testing fixtures.', flush=True)
        run('build', 'fixtures', 'app', 'worker', 'nginx')
        run('up', '-d', '--wait', '--wait-timeout', '150', 'db-test', 'rabbitmq-test', 'fixtures', 'app', 'nginx')
        helper('prepare')
        fixture('prepare')
        deadline = time.monotonic() + 30
        while True:
            if helper('login')['status'] == 200:
                break
            assert time.monotonic() < deadline, 'Login timeout'
            time.sleep(.1)
        assert helper('login', username='other-owner')['status'] == 200
        expected_health = {'status': 'ok', 'dependencies': {'database': 'ready', 'broker': 'ready'}}
        assert get('/api/health', auth=False) == expected_health
        accepted = helper('upload')
        assert accepted['status'] == 202, accepted
        job = accepted['body']['jobId']
        path = '/api/imports/' + job
        initial = get(path)
        assert initial['status'] == 'queued' and initial['processedRows'] == 0 and initial['totalRows'] is None, initial
        contract = json.loads((ROOT / 'docs/openapi.json').read_text(encoding='utf-8'))
        assert set(initial) == set(contract['components']['schemas']['ImportJob']['required'])
        assert get(path + '/issues')['pagination'] == {'page': 1, 'limit': 50, 'total': 0, 'totalPages': 0}
        for endpoint in [path, path + '/issues']:
            assert get(endpoint, 401, auth=False)['error']['code'] == 'UNAUTHORIZED'
            foreign = get(endpoint, 404, username='other-owner')
            missing = get(endpoint.replace(job, '00000000-0000-4000-8000-000000000000'), 404)
            assert foreign == missing and foreign['error']['code'] == 'NOT_FOUND'
        get('/api/imports/invalid', 400)
        for query in ['page=0', 'limit=101', 'limit[]=1', 'page=1&page=2', 'limit=', 'page=1.5', 'page=%xx']:
            get(path + '/issues?' + query, 400)
        run('up', '-d', '--no-deps', 'worker')
        samples = [initial]
        deadline = time.monotonic() + 45
        while True:
            state = get(path)
            samples.append(state)
            if state['status'] in {'completed', 'completed_with_errors', 'failed'}:
                break
            assert time.monotonic() < deadline, samples
            time.sleep(.03)
        assert state['status'] == 'completed_with_errors', state
        assert [state[k] for k in ['totalRows', 'processedRows', 'createdProducts', 'updatedProducts', 'failedRows', 'warningCount']] == [3, 3, 2, 0, 1, 1]
        counters = [sample['processedRows'] for sample in samples]
        assert counters == sorted(counters) and any(0 < n < 3 for n in counters), counters
        issues = get(path + '/issues')
        assert issues['pagination'] == {'page': 1, 'limit': 50, 'total': 2, 'totalPages': 1}, issues
        assert [item['severity'] for item in issues['items']] == ['error', 'warning'], issues
        assert [item['rowNumber'] for item in issues['items']] == [3, 4]
        assert all(set(item) == set(contract['components']['schemas']['ImportIssue']['required']) for item in issues['items'])
        first = get(path + '/issues?limit=1')
        second = get(path + '/issues?page=2&limit=1')
        assert first['items'] + second['items'] == issues['items']
        assert first['pagination']['totalPages'] == 2
        assert get(path + '/issues?page=2147483647&limit=100')['items'] == []
        report['polling'] = {'statuses': list(dict.fromkeys(s['status'] for s in samples)), 'processedRows': list(dict.fromkeys(counters)), 'final': state}
        report['issues'] = issues
        print('PASS: actual HTTP progress, owner isolation, stable report pages and OpenAPI keys.', flush=True)

        # One accepted upload + four malformed authenticated attempts consume this user's five slots.
        for _ in range(4):
            response = helper('upload', kind='missing')
            assert response['status'] == 400, response
        before = helper('inspect')
        limited = helper('upload', kind='missing', headers=['X-User-ID: 2', 'X-Forwarded-For: 8.8.8.8'])
        assert limited['status'] == 429 and limited['body']['error']['code'] == 'IMPORT_RATE_LIMITED', limited
        assert 1 <= int(limited['headers']['retry-after']) <= 60
        assert helper('upload', kind='missing', username='other-owner')['status'] == 400
        run('stop', '-t', '10', 'worker')
        run('restart', 'app')
        deadline = time.monotonic() + 20
        while True:
            response = helper('get', path=path)
            if response['status'] == 200:
                break
            assert time.monotonic() < deadline, response
            time.sleep(.1)
        assert response['body'] == state
        assert get(path + '/issues') == issues
        assert helper('upload', kind='missing')['status'] == 429
        after = helper('inspect')
        assert after['jobs'] == before['jobs'] and after['files'] == before['files']
        report['restart'] = {'reportPersisted': True, 'statusPersisted': True, 'limitPersisted': True}
        fixture('expire')
        with ThreadPoolExecutor(max_workers=10) as pool:
            responses = list(pool.map(lambda _: helper('upload', kind='missing'), range(10)))
        statuses = sorted(r['status'] for r in responses)
        assert statuses == [400] * 5 + [429] * 5, responses
        assert all(1 <= int(r['headers']['retry-after']) <= 60 for r in responses if r['status'] == 429)
        report['limiter'] = {'sixthStatus': 429, 'retryAfter': int(limited['headers']['retry-after']), 'concurrentStatuses': statuses, 'otherUserIndependent': True, 'untrustedHeadersIgnored': True, 'expiredWindowReset': True}
        print('PASS: sixth request 429/Retry-After, restart persistence, independent owners and 10 concurrent requests -> exactly five allowed.', flush=True)

        failures = []
        for dependency in ['rabbitmq-test', 'db-test']:
            run('stop', '-t', '10', dependency)
            probe_start = time.monotonic()
            body = get('/api/health', 503, auth=False)
            elapsed = time.monotonic() - probe_start
            assert elapsed < 10, elapsed
            assert body == {'error': {'code': 'SERVICE_UNAVAILABLE', 'message': 'Сервис временно недоступен.', 'details': []}}, body
            failures.append({'dependency': dependency, 'status': 503, 'elapsedSeconds': round(elapsed, 3)})
            run('up', '-d', '--wait', '--wait-timeout', '120', dependency)
            # RabbitMQ node ping can become healthy before its AMQP listener is accepting connections.
            deadline = time.monotonic() + 30
            while True:
                recovered = helper('get', path='/api/health', auth=False)
                if recovered['status'] == 200:
                    assert recovered['body'] == expected_health
                    break
                assert time.monotonic() < deadline, recovered
                time.sleep(.1)
        assert get(path + '/issues') == issues
        report['health'] = {'ready': 200, 'outages': failures, 'recovered': 200}
        report['result'] = 'passed'
        print('PASS: public health 200/503 and recovery for DB/broker; each failed probe under 10 seconds.', flush=True)
    except Exception:
        for service in ['app', 'worker', 'fixtures']:
            print(run('logs', '--no-color', '--tail=30', service, check=False).stdout, flush=True)
        raise
    finally:
        try:
            run('down', '-v', '--remove-orphans')
            report['cleanup'] = 'passed'
        except Exception:
            report['cleanup'] = 'failed'; report['result'] = 'failed'
            raise
        finally:
            report['elapsedSeconds'] = round(time.monotonic() - started, 2)
            (ROOT / 'tmp').mkdir(exist_ok=True)
            (ROOT / 'tmp/verification-import-api.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
