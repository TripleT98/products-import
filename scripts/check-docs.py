"""T28 OpenAPI and real Swagger JWT/multipart verification against production nginx/FPM and isolated PostgreSQL."""
import argparse
import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

ROOT=Path(__file__).resolve().parents[1]
PROJECT='product-import-docs-check'


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    parser=argparse.ArgumentParser()
    parser.add_argument('--docker',default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args=parser.parse_args()
    prefix=[args.docker,'compose','-p',PROJECT,'--env-file',str(ROOT/'.env.test.example'),
            '-f',str(ROOT/'compose.worker-test.yaml'),'-f',str(ROOT/'compose.upload-test.yaml'),'-f',str(ROOT/'compose.auth-ui-test.yaml'),'-f',str(ROOT/'compose.docs-test.yaml')]

    def run(*command):
        result=subprocess.run(prefix+list(command),cwd=ROOT,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=1200)
        if result.returncode:
            raise RuntimeError('Docs UI command failed: '+' '.join(command)+'\n'+result.stdout[-4000:]+result.stderr[-4000:])
        return result

    validator=ROOT/'tmp/t28-openapi-venv'/('Scripts/python.exe' if os.name=='nt' else 'bin/python')
    if not validator.exists():
        subprocess.run([sys.executable,'-m','venv',str(validator.parent.parent)],check=True)
        subprocess.run([str(validator),'-m','pip','install','openapi-spec-validator==0.7.2'],check=True)
    subprocess.run([str(validator),str(ROOT/'scripts/validate-docs.py')],cwd=ROOT,check=True)
    config=json.loads(run('config','--format','json').stdout)
    assert config['name']==PROJECT
    assert set(config['services'])=={'db-test','rabbitmq-test','fixtures','worker','app','nginx'}
    assert all(not s.get('ports') for name,s in config['services'].items() if name!='nginx')
    assert all(p['host_ip']=='127.0.0.1' for p in config['services']['nginx']['ports'])
    assert config['networks']['tests']['internal'] and not config['networks']['browser-http'].get('external')
    for volume in config['volumes'].values():
        assert not volume.get('external') and volume['name'].startswith(PROJECT+'_worker-')
    assert config['services']['db-test']['environment']['POSTGRES_DB']=='product_import_test'
    report={'task':'T28','project':PROJECT,'result':'failed'}
    started=time.monotonic()
    try:
        run('down','-v','--remove-orphans')
        print('Building isolated production Swagger UI; browser uses installed Microsoft Edge.',flush=True)
        run('build','fixtures','app','nginx','worker')
        run('up','-d','--wait','--wait-timeout','150','db-test','rabbitmq-test','fixtures','app','nginx')
        run('exec','-T','fixtures','php','tests/Support/docs-ui-fixture.php')
        for _ in range(2):
            run('exec','-T','--user','www-data','-e','APP_ENV=development','app','php','bin/console','fixtures:load')
        port=run('port','nginx','80').stdout.strip().rsplit(':',1)[1]
        tooling=ROOT/'tmp/t20-browser-tooling'
        tooling.mkdir(exist_ok=True)
        node=shutil.which('node'); npm=shutil.which('npm.cmd') or shutil.which('npm')
        if not node or not npm: raise RuntimeError('Node/npm and installed Microsoft Edge are required for this browser check.')
        if not (tooling/'node_modules/@playwright/test/package.json').exists():
            subprocess.run([npm,'install','--prefix',str(tooling),'--save-exact','--ignore-scripts','--no-audit','--no-fund','@playwright/test@1.56.1'],cwd=ROOT,check=True,capture_output=True,timeout=180)
        for filename in ['source.xlsx']:
            encoded=run('exec','-T','fixtures','php','-r','echo base64_encode(file_get_contents($argv[1]));','/var/app/uploads/'+filename).stdout
            (tooling/filename).write_bytes(base64.b64decode(encoded))
        env={**os.environ,'AUTH_TEST_ORIGIN':'http://127.0.0.1:'+port,'NODE_PATH':str(tooling/'node_modules'),'DOCS_XLSX':str(tooling/'source.xlsx'),'DOCS_RESPONSES':str(ROOT/'tmp/t28-live-responses.json'),'DOCS_DOCKER':args.docker,'DOCS_COMPOSE_ARGS':json.dumps(prefix[1:])}
        # Copy only the Swagger browser spec into ignored tooling; no application source mutation.
        shutil.copyfile(ROOT/'frontend/browser-tests/docs-ui.spec.cjs',tooling/'docs-ui.spec.cjs')
        browser=subprocess.run([node,str(tooling/'node_modules/@playwright/test/cli.js'),'test','docs-ui.spec.cjs','--workers=1','--reporter=line','--output=test-results-docs-ui'],cwd=tooling,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=240)
        (ROOT/'tmp/t28-docs-browser.log').write_text(browser.stdout+'\n'+browser.stderr,encoding='utf-8')
        print(browser.stdout,flush=True)
        if browser.returncode: raise RuntimeError('Docs browser test failed.\n'+browser.stderr)
        subprocess.run([str(validator),str(ROOT/'scripts/validate-docs.py'),'--responses',str(ROOT/'tmp/t28-live-responses.json')],cwd=ROOT,check=True)
        report.update(result='passed',browser='Edge/Playwright1.56.1',swaggerVersion='5.34.1',
                      publicDocs=True,localAssets=True,jwtExecute=True,realMultipart=True,fixturesTwice=True,
                      realResponseValidation=True,ephemeralAuthorization=True)
        print('PASS: T28 OpenAPI/examples/env, real Swagger login/JWT/upload/status/report; no external requests.',flush=True)
    finally:
        try:
            run('down','-v','--remove-orphans'); report['cleanup']='passed'
        except Exception:
            report['cleanup']='failed'; report['result']='failed'
            raise
        finally:
            report['elapsedSeconds']=round(time.monotonic()-started,2)
            (ROOT/'tmp').mkdir(exist_ok=True)
            (ROOT/'tmp/verification-docs.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')


if __name__=='__main__':
    main()
