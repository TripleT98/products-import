"""T19 production nginx deep-link fallback and all emitted JavaScript/CSS assets."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-frontend-check'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args = parser.parse_args()
    prefix = [args.docker, 'compose', '-p', PROJECT, '-f', str(ROOT/'compose.frontend-test.yaml')]

    def run(*command):
        result = subprocess.run(prefix+list(command), cwd=ROOT, capture_output=True, text=True,
                                encoding='utf-8', errors='replace', timeout=600)
        if result.returncode:
            raise RuntimeError('Frontend check failed: '+' '.join(command)+'\n'+result.stdout[-2500:]+result.stderr[-2500:])
        return result

    def get(path):
        result = run('exec','-T','web','wget','-S','-O','-','http://127.0.0.1'+path)
        assert '200 OK' in result.stderr, result.stderr
        return result

    config = json.loads(run('config','--format','json').stdout)
    assert config['name'] == PROJECT and set(config['services']) == {'web'}
    assert not config.get('volumes') and not config['services']['web'].get('ports')
    assert all(n.get('internal') for n in config['networks'].values())
    report = {'task':'T19','project':PROJECT,'result':'failed'}
    started = time.monotonic()
    try:
        run('down','--remove-orphans')
        run('build','web')
        run('up','-d','--wait','--wait-timeout','60','web')
        index = get('/').stdout
        assert '<app-root>' in index and 'type="module"' in index
        paths = ['/login','/import','/import/123e4567-e89b-42d3-a456-426614174000','/products','/products/42','/unknown']
        for path in paths:
            response = get(path)
            assert 'Content-Type: text/html' in response.stderr
            assert response.stdout == index, path
        files = run('exec','-T','web','find','/usr/share/nginx/html','-maxdepth','1','-type','f').stdout.splitlines()
        assets = [p.rsplit('/',1)[-1] for p in files if p.endswith(('.js','.css'))]
        assert len([a for a in assets if a.endswith('.js')]) >= 7, assets
        for asset in assets:
            response = get('/'+asset)
            assert 'text/html' not in response.stderr
            assert response.stdout.strip() and '<app-root>' not in response.stdout
        report.update(result='passed',deepLinks={p:200 for p in paths},assets=sorted(assets),
                      indexSha256=hashlib.sha256(index.encode()).hexdigest(),sameIndexForRefresh=True)
        print('PASS: production nginx; six direct/deep links serve index; all JS/CSS including lazy chunks return 200.',flush=True)
    finally:
        try:
            run('down','--remove-orphans')
            report['cleanup']='passed'
        except Exception:
            report['result']='failed'; report['cleanup']='failed'
            raise
        finally:
            report['elapsedSeconds']=round(time.monotonic()-started,2)
            (ROOT/'tmp').mkdir(exist_ok=True)
            (ROOT/'tmp/verification-frontend.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')


if __name__ == '__main__':
    main()
