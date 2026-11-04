"""T18: SIGKILL/redelivery, two actual consumers and transactional mid-row faults."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-recovery-check'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    prefix = [args.docker, 'compose', '-p', PROJECT, '--env-file', str(ROOT / '.env.test.example'),
              '-f', str(ROOT / 'compose.worker-test.yaml'), '-f', str(ROOT / 'compose.recovery-test.yaml')]

    def run(*command, check=True):
        result = subprocess.run(prefix + list(command), cwd=ROOT, capture_output=True, text=True,
                                encoding='utf-8', errors='replace', timeout=600)
        if check and result.returncode:
            raise RuntimeError('Recovery command failed: ' + ' '.join(command) + '\n' + result.stdout[-2500:] + result.stderr[-2500:])
        return result

    def data(*command):
        output = run(*command).stdout
        for line in reversed(output.splitlines()):
            if line.startswith('{'):
                return json.loads(line)
        raise RuntimeError('Missing fixture JSON: ' + output[-2000:])

    def fixture(action, *params):
        return data('exec', '-T', 'fixtures', 'php', 'tests/Support/recovery-fixture.php', action, *params)

    def snapshot(job):
        return data('exec', '-T', 'fixtures', 'php', 'tests/Support/worker-fixture.php', 'snapshot', job)

    def publish(job):
        return data('exec', '-T', 'fixtures', 'php', 'tests/Support/worker-fixture.php', 'publish', job)

    def enqueue(source='recovery.xlsx'):
        return data('run', '--rm', '-T', '--no-deps', 'worker', 'php', 'bin/console', 'imports:enqueue',
                    '/var/app/uploads/' + source, '--owner=1')['jobId']

    def workers(count):
        run('up', '-d', '--no-deps', '--scale', 'worker=' + str(count), 'worker')

    def stop():
        run('stop', '-t', '15', 'worker')

    def until(job, predicate, seconds=65):
        deadline = time.monotonic() + seconds
        while True:
            state = snapshot(job)
            if predicate(state):
                return state
            assert state['job']['status'] not in {'completed', 'completed_with_errors', 'failed'}, state
            assert time.monotonic() < deadline, state
            time.sleep(.08)

    def logs():
        return run('logs', '--no-color', 'worker').stdout

    def drained():
        deadline = time.monotonic() + 30
        while True:
            result = run('exec', '-T', 'rabbitmq-test', 'rabbitmqctl', 'list_queues', '--formatter=json',
                         'name', 'messages_ready', 'messages_unacknowledged')
            queues = json.loads(result.stdout)
            # Includes retry delay queues and unacked deliveries, unlike CLI ready-only stats.
            active = [q for q in queues if q['name'] != 'worker_check.failed' and
                      (int(q['messages_ready']) or int(q['messages_unacknowledged']))]
            if not active:
                return queues
            assert time.monotonic() < deadline, queues
            time.sleep(.1)

    def verify(job, state, created):
        j = state['job']
        assert j['status'] == 'completed_with_errors', state
        assert [j[k] for k in ['totalRows','processedRows','createdProducts','updatedProducts','failedRows','warningCount']] == [16,16,created,13-created,3,1], state
        assert state['results'] == 16 and len(state['products']) == len(state['images']) == 13
        assert all(i['exists'] and i['mime'] == 'image/png' for i in state['images'])
        audit = fixture('audit', job)
        assert audit['duplicateResults'] == 0 and len(audit['rows']) == 16
        assert sum(int(v) for v in audit['counts'].values()) == j['processedRows']
        assert int(audit['counts'].get('failed', 0)) == j['failedRows'] and audit['warnings'] == j['warningCount']
        assert len(audit['issues']) == 4 and len(audit['attributes']) == 26
        assert all(p['discount'] == '25.00' for p in audit['products'])
        return audit

    config = json.loads(run('config', '--format', 'json').stdout)
    assert config['name'] == PROJECT
    assert set(config['services']) == {'db-test','rabbitmq-test','fixtures','worker'}
    assert all(not s.get('ports') for s in config['services'].values())
    assert all(n.get('internal') for n in config['networks'].values())
    assert config['services']['worker']['build']['target'] == 'production'
    assert config['services']['db-test']['environment']['POSTGRES_DB'] == 'product_import_test'
    for volume in config['volumes'].values():
        assert not volume.get('external') and volume['name'].startswith(PROJECT + '_worker-')
    report = {'task': 'T18', 'project': PROJECT, 'result': 'failed'}
    started = time.monotonic()
    try:
        run('down', '-v', '--remove-orphans')
        print('Building isolated production recovery worker and fixture server.', flush=True)
        run('build', 'fixtures', 'worker')
        run('up', '-d', '--wait', '--wait-timeout', '150', 'db-test','rabbitmq-test','fixtures')
        fixture('prepare')

        job = enqueue()
        workers(1)
        until(job, lambda s: s['job']['processedRows'] >= 4 and s['job']['processedRows'] < 16)
        run('kill', '-s', 'SIGKILL', 'worker')
        stopped = [json.loads(line) for line in run('ps','-a','--format','json').stdout.splitlines() if line.strip()]
        assert [c['ExitCode'] for c in stopped if c['Service'] == 'worker'] == [137], stopped
        partial = snapshot(job)
        before = fixture('audit', job)
        assert 4 <= partial['job']['processedRows'] < 16 and partial['job']['status'] == 'processing'
        assert partial['results'] == partial['job']['processedRows']
        assert partial['job']['failedRows'] >= 2 and partial['job']['warningCount'] == 1
        # The original unacked delivery MUST resume without manual republish.
        workers(1)
        resumed = until(job, lambda s: s['job']['status'] == 'completed_with_errors')
        final = verify(job, resumed, 13)
        assert final['generation'] >= 2
        assert final['rows'][:len(before['rows'])] == before['rows']
        assert final['issues'][:len(before['issues'])] == before['issues']
        drained()
        report['hardKill'] = {'exitCode':137,'partial':partial['job'],'final':resumed['job'],
                              'generation':final['generation'],'checkpointPrefixUnchanged':True,
                              'originalDeliveryRecoveredWithoutRepublish':True,'unreferencedPng':len(final['unreferencedPng'])}
        print('PASS: real SIGKILL -> original Rabbit delivery -> expired lease reclaimed; committed rows/issues unchanged.', flush=True)

        # Two duplicate messages while the first delivery is actively processing, two consumers.
        stop()
        concurrent = enqueue()
        publish(concurrent)
        workers(2)
        competing = until(concurrent, lambda s: s['job']['status'] == 'completed_with_errors')
        concurrent_audit = verify(concurrent, competing, 0)
        drained()
        stable = snapshot(concurrent)
        publish(concurrent)
        publish(concurrent)
        drained()
        assert snapshot(concurrent) == stable
        assert fixture('audit', concurrent)['rows'] == concurrent_audit['rows']
        assert 'import.retry' in logs() and concurrent in logs()
        report['twoConsumers'] = {'consumers':2,'duplicateMessages':2,'job':competing['job'],'generation':concurrent_audit['generation'],
                                  'terminalRedeliveryUnchanged':True,'failedQueue':0}
        print('PASS: two consumers, active duplicate + terminal redelivery; counters/results/errors do not double.', flush=True)

        stop()
        transient = enqueue()
        fixture('fault', transient, 'once')
        workers(1)
        recovered = until(transient, lambda s: s['job']['status'] == 'completed_with_errors')
        verify(transient, recovered, 0)
        assert any('import.retry' in line and transient in line for line in logs().splitlines()), logs()
        drained()
        report['midRowTransient'] = {'job':recovered['job'],'transactionRolledBackAndRetried':True,'noProductWriteFailedIssue':True}
        print('PASS: mid-row SQLSTATE08006 rolls back product/links/checkpoint and retries instead of losing the row.', flush=True)

        stop()
        persistent = enqueue()
        fixture('fault', persistent, 'always')
        workers(1)
        exhausted = until(persistent, lambda s: s['job']['status'] == 'failed')
        assert exhausted['job']['fatalError']['code'] == 'IMPORT_FAILED'
        audit = fixture('audit', persistent)
        assert exhausted['results'] == exhausted['job']['processedRows'] == 5
        assert int(audit['counts']['failed']) == exhausted['job']['failedRows'] == 2
        assert audit['warnings'] == exhausted['job']['warningCount'] == 1
        assert len(audit['issues']) == 4 and audit['issues'][-1]['code'] == 'IMPORT_FAILED'
        stats = data('run','--rm','-T','--no-deps','worker','php','bin/console','imports:queue-stats')
        assert stats['failed'] == 1, stats
        retries = sum('import.retry' in line and persistent in line for line in logs().splitlines())
        assert retries == 3, retries
        drained()
        report['exhausted'] = {'job':exhausted['job'],'retries':retries,'failedQueue':1,'partialIssuesPreserved':True}
        fixture('fault', persistent, 'clear')
        broken = enqueue('broken.xlsx')
        invalid = until(broken, lambda s: s['job']['status'] == 'failed')
        assert invalid['job']['fatalError']['code'] == 'WORKBOOK_INVALID' and invalid['results'] == 0
        assert invalid['issues']['pagination']['total'] == 1
        assert not any('import.retry' in line and broken in line for line in logs().splitlines())
        drained()
        report['invalidWorkbook'] = {'job':invalid['job'],'reportPersisted':True,'retried':False}
        assert data('run','--rm','-T','--no-deps','worker','php','bin/console','imports:queue-stats')['failed'] == 1
        report['result'] = 'passed'
        print('PASS: exhausted retries retain failed message and partial issues; fatal workbook ends without retry loop.', flush=True)
    except Exception:
        print(run('logs','--no-color','--tail=60','worker',check=False).stdout, flush=True)
        raise
    finally:
        try:
            run('down','-v','--remove-orphans')
            report['cleanup'] = 'passed'
        except Exception:
            report['result'] = 'failed'; report['cleanup'] = 'failed'
            raise
        finally:
            report['elapsedSeconds'] = round(time.monotonic() - started, 2)
            (ROOT/'tmp').mkdir(exist_ok=True)
            (ROOT/'tmp/verification-recovery.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')


if __name__ == '__main__':
    main()
