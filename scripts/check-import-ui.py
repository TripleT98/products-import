"""T24 real browser import upload and progress against production nginx/FPM and isolated PostgreSQL."""
import argparse
import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import time

ROOT=Path(__file__).resolve().parents[1]
PROJECT='product-import-import-ui-check'


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--docker',default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    args=parser.parse_args()
    prefix=[args.docker,'compose','-p',PROJECT,'--env-file',str(ROOT/'.env.test.example'),
            '-f',str(ROOT/'compose.worker-test.yaml'),'-f',str(ROOT/'compose.upload-test.yaml'),'-f',str(ROOT/'compose.auth-ui-test.yaml'),'-f',str(ROOT/'compose.import-ui-test.yaml')]

    def run(*command):
        result=subprocess.run(prefix+list(command),cwd=ROOT,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=1200)
        if result.returncode:
            raise RuntimeError('Import UI command failed: '+' '.join(command)+'\n'+result.stdout[-4000:]+result.stderr[-4000:])
        return result

    config=json.loads(run('config','--format','json').stdout)
    assert config['name']==PROJECT
    assert set(config['services'])=={'db-test','rabbitmq-test','fixtures','worker','app','nginx'}
    assert all(not s.get('ports') for name,s in config['services'].items() if name!='nginx')
    assert all(p['host_ip']=='127.0.0.1' for p in config['services']['nginx']['ports'])
    assert config['networks']['tests']['internal'] and not config['networks']['browser-http'].get('external')
    for volume in config['volumes'].values():
        assert not volume.get('external') and volume['name'].startswith(PROJECT+'_worker-')
    assert config['services']['db-test']['environment']['POSTGRES_DB']=='product_import_test'
    report={'task':'T24','project':PROJECT,'result':'failed'}
    started=time.monotonic()
    try:
        run('down','-v','--remove-orphans')
        print('Building isolated production upload/progress UI; browser uses installed Microsoft Edge.',flush=True)
        run('build','fixtures','app','nginx','worker')
        run('up','-d','--wait','--wait-timeout','150','db-test','rabbitmq-test','fixtures','app','nginx')
        run('exec','-T','fixtures','php','tests/Support/import-ui-fixture.php')
        port=run('port','nginx','80').stdout.strip().rsplit(':',1)[1]
        tooling=ROOT/'tmp/t20-browser-tooling'
        tooling.mkdir(exist_ok=True)
        node=shutil.which('node'); npm=shutil.which('npm.cmd') or shutil.which('npm')
        if not node or not npm: raise RuntimeError('Node/npm and installed Microsoft Edge are required for this browser check.')
        if not (tooling/'node_modules/@playwright/test/package.json').exists():
            subprocess.run([npm,'install','--prefix',str(tooling),'--save-exact','--ignore-scripts','--no-audit','--no-fund','@playwright/test@1.56.1'],cwd=ROOT,check=True,capture_output=True,timeout=180)
        for filename in ['import-ui.xlsx','import-ui-fatal.xlsx']:
            encoded=run('exec','-T','fixtures','php','-r','echo base64_encode(file_get_contents($argv[1]));','/tmp/'+filename).stdout
            (tooling/filename).write_bytes(base64.b64decode(encoded))
        env={**os.environ,'AUTH_TEST_ORIGIN':'http://127.0.0.1:'+port,'NODE_PATH':str(tooling/'node_modules'),'IMPORT_UI_FILE':str(tooling/'import-ui.xlsx'),'IMPORT_UI_FATAL_FILE':str(tooling/'import-ui-fatal.xlsx'),'IMPORT_UI_DOCKER':args.docker,'IMPORT_UI_COMPOSE_ARGS':json.dumps(prefix[1:])}
        # Copy only the auth spec into ignored tooling so dependency resolution is local and reproducible.
        shutil.copyfile(ROOT/'frontend/browser-tests/import-ui.spec.cjs',tooling/'import-ui.spec.cjs')
        browser=subprocess.run([node,str(tooling/'node_modules/@playwright/test/cli.js'),'test','import-ui.spec.cjs','--workers=1','--reporter=line'],cwd=tooling,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=240)
        print(browser.stdout,flush=True)
        if browser.returncode: raise RuntimeError('Import browser test failed.\n'+browser.stderr)
        report.update(result='passed',browser='Microsoft Edge/Playwright1.56.1',realMultipart=True,
                      workerStoppedQueuedUnknownTotal=True,duplicateClickBlocked=True,pauseResume=True,
                      networkErrorRetry=True,refresh=True,leaveStops=True,workerProcessing=True,
                      finalCounters={'processedRows':12,'createdProducts':11,'failedRows':1,'warningCount':1},
                      terminalStops=True,fatalWorkbook=True,mobile=True)
        print('PASS: T24 real upload/queue/worker, progress, unknown total, pause/retry/refresh/leave/terminal stop.',flush=True)
    finally:
        try:
            run('down','-v','--remove-orphans'); report['cleanup']='passed'
        except Exception:
            report['cleanup']='failed'; report['result']='failed'
            raise
        finally:
            report['elapsedSeconds']=round(time.monotonic()-started,2)
            (ROOT/'tmp').mkdir(exist_ok=True)
            (ROOT/'tmp/verification-import-ui.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')


if __name__=='__main__':
    main()
