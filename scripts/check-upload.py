"""T16 real multipart: curl -> nginx -> FPM/Slim -> RabbitMQ -> separate production worker."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-upload-check'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    prefix = [args.docker, 'compose', '-p', PROJECT, '--env-file', str(ROOT / '.env.test.example'),
              '-f', str(ROOT / 'compose.worker-test.yaml'), '-f', str(ROOT / 'compose.upload-test.yaml')]

    def run(*command, check=True, payload=None, announce=False):
        if announce:
            print('+ upload-check: ' + ' '.join(command), flush=True)
        result = subprocess.run(prefix + list(command), cwd=ROOT, input=payload, capture_output=True, text=True, encoding='utf-8', errors='replace')
        if check and result.returncode:
            raise RuntimeError('Upload check command failed: ' + ' '.join(command) + '\n' + result.stdout[-3000:] + result.stderr[-3000:])
        return result

    def helper(action, **params):
        out = run('exec', '-T', 'fixtures', 'php', 'tests/Support/upload-http.php', payload=json.dumps({'action': action, **params})).stdout
        return json.loads(out)

    def snapshot(job):
        return json.loads(run('exec', '-T', 'fixtures', 'php', 'tests/Support/worker-fixture.php', 'snapshot', job).stdout)

    config = json.loads(run('config', '--format', 'json').stdout)
    assert config['name'] == PROJECT
    assert set(config['services']) == {'db-test', 'rabbitmq-test', 'fixtures', 'worker', 'app', 'nginx'}
    assert all(not s.get('ports') for s in config['services'].values())
    assert all(n.get('internal') for n in config['networks'].values())
    for volume in config['volumes'].values():
        assert not volume.get('external') and volume['name'].startswith(PROJECT + '_worker-')
    assert config['services']['app']['build']['target'] == config['services']['worker']['build']['target'] == 'production'
    assert config['services']['app']['volumes'] == config['services']['worker']['volumes']
    assert config['services']['db-test']['environment']['POSTGRES_DB'] == 'product_import_test'
    report = {'task': 'T16', 'project': PROJECT, 'result': 'failed'}
    started = time.monotonic()
    try:
        run('down', '-v', '--remove-orphans', announce=True)
        run('build', 'fixtures', 'app', 'worker', 'nginx', announce=True)
        run('up', '-d', '--wait', '--wait-timeout', '150', 'db-test', 'rabbitmq-test', 'fixtures', 'app', 'nginx', announce=True)
        helper('prepare')
        deadline = time.monotonic() + 30
        while True:
            try:
                login = helper('login')
                if login['status'] == 200:
                    break
            except (RuntimeError, ValueError):
                pass
            if time.monotonic() >= deadline:
                raise TimeoutError('HTTP login did not become ready')
            time.sleep(.1)
        assert login['body'] == {'ownerId': 1}
        results = []

        def reject(expected, code, **params):
            before = helper('inspect')
            response = helper('upload', **params)
            assert response['status'] == expected, response
            assert response['body']['error']['code'] == code, response
            assert response['body']['error']['details'] == []
            assert response['headers']['content-type'].startswith('application/json')
            rid = response['headers']['x-request-id']
            assert len(rid) == 32 and all(c in '0123456789abcdef' for c in rid)
            after = helper('inspect')
            assert after['jobs'] == before['jobs'] and after['files'] == before['files'] and after['products'] == before['products']
            results.append({'case': params, 'status': expected, 'code': code})

        reject(401, 'UNAUTHORIZED', auth=False)
        reject(400, 'INVALID_REQUEST', kind='missing')
        reject(400, 'INVALID_REQUEST', field='other')
        reject(400, 'INVALID_REQUEST', field='file[]')
        reject(400, 'INVALID_REQUEST', extra={'ownerId': '999'})
        reject(415, 'UNSUPPORTED_FILE_TYPE', filename='file.xls')
        reject(415, 'UNSUPPORTED_FILE_TYPE', kind='text')
        reject(422, 'INVALID_WORKBOOK', kind='corrupt')
        reject(422, 'INVALID_WORKBOOK', kind='bomb')
        reject(422, 'INVALID_WORKBOOK', kind='entries')
        reject(422, 'INVALID_WORKBOOK', kind='encrypted')
        reject(422, 'INVALID_WORKBOOK', kind='rows')
        reject(413, 'UPLOAD_TOO_LARGE', kind='large')
        reject(413, 'UPLOAD_TOO_LARGE', kind='php-limit')
        reject(413, 'UPLOAD_TOO_LARGE', kind='nginx-limit')
        report['rejections'] = results
        print('PASS: real multipart validation/auth/byte/PHP/nginx/ZIP/row limits.', flush=True)

        accepted = helper('upload', mime='text/plain', filename='../../untrusted-name.XLSX')
        assert accepted['status'] == 202, accepted
        body = accepted['body']
        assert set(body) == {'jobId', 'status'} and body['status'] == 'queued'
        assert accepted['headers']['cache-control'] == 'no-store'
        job = body['jobId']
        paused = helper('inspect', id=job)
        assert paused['products'] == 0 and paused['job']['status'] == 'queued' and paused['job']['owner_id'] == 1
        assert len(paused['files']) == 1 and paused['files'][0]['name'] == paused['job']['file_name']
        assert paused['files'][0]['mode'] == '600' and paused['files'][0]['owner'] == 33
        assert paused['files'][0]['sha256'] == paused['sourceSha256']
        assert 'untrusted-name' not in paused['job']['file_name']
        read = run('run', '--rm', '-T', '--no-deps', 'worker', 'php', '-r',
                   '$p="/var/app/uploads/".$argv[1]; echo json_encode(["readable"=>is_readable($p),"owner"=>fileowner($p)]);', paused['job']['file_name'])
        assert json.loads(read.stdout) == {'readable': True, 'owner': 33}
        hidden = helper('get', path='/uploads/' + paused['job']['file_name'])
        assert hidden['status'] == 404 and hidden['sha256'] != paused['sourceSha256']
        stats = json.loads(run('run', '--rm', '-T', '--no-deps', 'worker', 'php', 'bin/console', 'imports:queue-stats').stdout)
        assert stats == {'imports': 1, 'failed': 0}, stats
        report['accepted'] = body
        report['pausedWorker'] = {'products': 0, 'jobStatus': 'queued', 'brokerReady': 1, 'mode': '600', 'ownerUid': 33, 'workerCanRead': True, 'privateUrl': 404}
        run('up', '-d', '--no-deps', 'worker', announce=True)
        deadline = time.monotonic() + 45
        while True:
            state = snapshot(job)
            if state['job']['status'] in {'completed', 'completed_with_errors', 'failed'}:
                break
            assert time.monotonic() < deadline, state
            time.sleep(.1)
        j = state['job']
        assert j['status'] == 'completed_with_errors'
        assert [j[k] for k in ['totalRows', 'processedRows', 'createdProducts', 'updatedProducts', 'failedRows', 'warningCount']] == [3, 3, 2, 0, 1, 1], state
        assert len(state['products']) == state['fileCount'] == len(state['images']) == 2
        for image in state['images']:
            assert image['exists'] and image['mime'] == 'image/png'
            response = helper('get', path=image['path'])
            assert response['status'] == 200 and response['headers']['content-type'] == 'image/png'
        report['processed'] = j
        report['realImagesServed'] = 2
        print('PASS: HTTP 202 without product writes; separate worker imports with consistent counters and real images.', flush=True)

        run('stop', '-t', '10', 'worker', 'rabbitmq-test', announce=True)
        before = helper('inspect')
        failed = helper('upload')
        assert failed['status'] == 503 and failed['body']['error']['code'] == 'SERVICE_UNAVAILABLE', failed
        after = helper('inspect')
        assert after['jobs'] == before['jobs'] + 1 and len(after['files']) == len(before['files']) + 1
        assert after['job']['status'] == 'failed' and after['job']['fatal_code'] == 'PUBLISH_FAILED'
        assert after['products'] == before['products']
        report['brokerUnavailable'] = {'httpStatus': 503, 'jobStatus': 'failed', 'fatalCode': 'PUBLISH_FAILED', 'noNewProducts': True, 'privateCopyRetained': True}
        report['result'] = 'passed'
        print('PASS: broker outage returns 503, never false 202; durable failure retained.', flush=True)
    except Exception:
        for service in ['app', 'worker', 'fixtures']:
            print(run('logs', '--no-color', '--tail=40', service, check=False).stdout, flush=True)
        raise
    finally:
        try:
            run('down', '-v', '--remove-orphans', announce=True)
            report['cleanup'] = 'passed'
        except Exception:
            report['result'] = 'failed'; report['cleanup'] = 'failed'
            raise
        finally:
            report['elapsedSeconds'] = round(time.monotonic() - started, 2)
            (ROOT / 'tmp').mkdir(exist_ok=True)
            (ROOT / 'tmp/verification-upload.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
