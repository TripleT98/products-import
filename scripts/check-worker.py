"""T15: real RabbitMQ + producer CLI + separate production worker, isolated from the application."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-worker-check'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    prefix = [args.docker, 'compose', '-p', PROJECT, '--env-file', str(ROOT / '.env.test.example'), '-f', str(ROOT / 'compose.worker-test.yaml')]

    def run(*command, check=True, announce=True):
        if announce:
            print('+ worker-check: ' + ' '.join(command), flush=True)
        result = subprocess.run(prefix + list(command), cwd=ROOT, capture_output=True, text=True, encoding='utf-8', errors='replace')
        if check and result.returncode:
            raise RuntimeError('Worker check command failed: ' + ' '.join(command) + '\n' + result.stdout[-3000:] + result.stderr[-3000:])
        return result

    def data(*command):
        output = run(*command, announce=False).stdout
        for line in reversed(output.splitlines()):
            if line.startswith('{'):
                return json.loads(line)
        raise RuntimeError('Missing JSON result: ' + output[-1500:])

    config = json.loads(run('config', '--format', 'json', announce=False).stdout)
    assert config['name'] == PROJECT
    assert set(config['services']) == {'db-test', 'rabbitmq-test', 'fixtures', 'worker'}
    assert all(not s.get('ports') for s in config['services'].values())
    assert all(n.get('internal') for n in config['networks'].values())
    assert config['services']['worker']['network_mode'] == 'service:fixtures'
    for volume in config['volumes'].values():
        assert not volume.get('external') and volume['name'].startswith(PROJECT + '_worker-')
    assert config['services']['db-test']['environment']['POSTGRES_DB'] == 'product_import_test'

    report = {'task': 'T15', 'project': PROJECT, 'result': 'failed'}
    started = time.monotonic()
    try:
        # Explicit disposable project only: its two volumes hold exclusively generated fixtures.
        run('down', '-v', '--remove-orphans')
        run('build', 'fixtures', 'worker')
        run('up', '-d', '--wait', '--wait-timeout', '150', 'db-test', 'rabbitmq-test', 'fixtures')

        def snapshot(job_id):
            return data('exec', '-T', 'fixtures', 'php', 'tests/Support/worker-fixture.php', 'snapshot', job_id)

        def enqueue(source='source.xlsx', unavailable=False):
            result = run('run', '--rm', '-T', '--no-deps', 'worker', 'php', 'bin/console', 'imports:enqueue', '/var/app/uploads/' + source, '--owner=1', check=not unavailable)
            payload = json.loads(result.stdout.strip().splitlines()[-1])
            assert bool(result.returncode) == unavailable
            return payload['jobId']

        def wait_terminal(job_id, expected):
            end = time.monotonic() + 45
            while True:
                state = snapshot(job_id)
                if state['job']['status'] == expected:
                    return state
                if state['job']['status'] in {'failed', 'completed', 'completed_with_errors'}:
                    raise AssertionError('Unexpected terminal state: ' + json.dumps(state))
                if time.monotonic() >= end:
                    raise TimeoutError('Worker did not finish: ' + json.dumps(state))
                time.sleep(.15)

        job = enqueue()
        paused = snapshot(job)
        assert paused['job']['status'] == 'queued' and paused['products'] == [] and paused['results'] == 0
        stats = data('run', '--rm', '-T', '--no-deps', 'worker', 'php', 'bin/console', 'imports:queue-stats')
        assert stats == {'imports': 1, 'failed': 0}, stats
        report['pausedWorker'] = {'jobStatus': 'queued', 'products': 0, 'brokerReadyMessages': 1}
        run('up', '-d', '--no-deps', 'worker')
        state = wait_terminal(job, 'completed_with_errors')
        j = state['job']
        assert [j[k] for k in ['totalRows', 'processedRows', 'createdProducts', 'updatedProducts', 'failedRows', 'warningCount']] == [3, 3, 2, 0, 1, 1], state
        assert len(state['products']) == 2 and state['results'] == 3
        assert [p['external_code'] for p in state['products']] == ['000001', '000002']
        assert all(p['discount'] == '25.00' for p in state['products'])
        assert len(state['images']) == 2 and state['fileCount'] == 2
        assert all(i['exists'] and i['mime'] == 'image/png' for i in state['images'])
        issues = state['issues']['items']
        assert [(i['severity'], i['rowNumber']) for i in issues] == [('error', 3), ('warning', 4)], issues
        report['processed'] = j
        report['issues'] = issues
        report['images'] = {'links': 2, 'files': 2, 'realPng': True}

        run('exec', '-T', 'fixtures', 'php', 'tests/Support/worker-fixture.php', 'publish', job)
        end = time.monotonic() + 10
        while data('run', '--rm', '-T', '--no-deps', 'worker', 'php', 'bin/console', 'imports:queue-stats')['imports']:
            assert time.monotonic() < end
            time.sleep(.1)
        assert snapshot(job)['job'] == j
        report['terminalRedelivery'] = 'no duplicate counters/products'

        run('stop', '-t', '10', 'worker')
        broken = enqueue('broken.xlsx')
        run('up', '-d', '--no-deps', 'worker')
        broken_state = wait_terminal(broken, 'failed')
        assert broken_state['job']['fatalError']['code'] == 'WORKBOOK_INVALID'
        assert broken_state['job']['processedRows'] == 0
        report['invalidWorkbook'] = 'failed safely, no row results'

        run('stop', '-t', '10', 'worker')
        transient = enqueue()
        run('exec', '-T', 'fixtures', 'php', 'tests/Support/worker-fixture.php', 'fault', transient, 'once')
        run('up', '-d', '--no-deps', 'worker')
        recovered = wait_terminal(transient, 'completed_with_errors')
        assert [recovered['job'][k] for k in ['processedRows', 'createdProducts', 'updatedProducts', 'failedRows', 'warningCount']] == [3, 0, 2, 1, 1]
        assert recovered['results'] == 3 and recovered['fileCount'] == 2
        logs = run('logs', '--no-color', 'worker', announce=False).stdout
        assert any('import.retry' in line and transient in line for line in logs.splitlines())
        report['temporaryFailure'] = 'real AMQP delayed retry, completed once with correct counters'

        run('stop', '-t', '10', 'worker')
        exhausted = enqueue()
        run('exec', '-T', 'fixtures', 'php', 'tests/Support/worker-fixture.php', 'fault', exhausted, 'always')
        run('up', '-d', '--no-deps', 'worker')
        exhausted_state = wait_terminal(exhausted, 'failed')
        assert exhausted_state['job']['fatalError']['code'] == 'IMPORT_FAILED'
        assert exhausted_state['job']['processedRows'] == 0
        stats = data('run', '--rm', '-T', '--no-deps', 'worker', 'php', 'bin/console', 'imports:queue-stats')
        assert stats['failed'] == 1, stats
        logs = run('logs', '--no-color', 'worker', announce=False).stdout
        assert sum('import.retry' in line and exhausted in line for line in logs.splitlines()) == 3
        report['failedTransport'] = {'maxRetries': 3, 'retainedMessages': 1, 'jobStatus': 'failed'}

        run('stop', '-t', '10', 'worker')
        inspect = run('ps', '-a', '--format', 'json', announce=False).stdout
        workers = [json.loads(line) for line in inspect.splitlines() if line and json.loads(line)['Service'] == 'worker']
        assert len(workers) == 1 and workers[0]['ExitCode'] == 0, workers
        report['sigterm'] = 'worker stopped cleanly with exit 0'
        run('stop', '-t', '10', 'rabbitmq-test')
        refused = enqueue(unavailable=True)
        refused_state = snapshot(refused)
        assert refused_state['job']['status'] == 'failed' and refused_state['job']['fatalError']['code'] == 'PUBLISH_FAILED'
        report['brokerUnavailable'] = 'nonzero CLI exit; durable PUBLISH_FAILED job; no false queued success'
        report['result'] = 'passed'
        print('PASS: real RabbitMQ; separate production worker; isolated PostgreSQL/uploads/images.', flush=True)
    except Exception:
        print(run('logs', '--no-color', '--tail=60', 'worker', check=False, announce=False).stdout, flush=True)
        raise
    finally:
        try:
            run('down', '-v', '--remove-orphans')
            report['cleanup'] = 'passed'
        finally:
            report['elapsedSeconds'] = round(time.monotonic() - started, 2)
            (ROOT / 'tmp').mkdir(exist_ok=True)
            (ROOT / 'tmp/verification-worker.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
