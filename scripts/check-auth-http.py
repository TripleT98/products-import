"""Verify T07 using real production nginx/FPM and a disposable PostgreSQL project."""
import argparse
import base64
import hashlib
import hmac
import json
from pathlib import Path
import shutil
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
PROJECT = 'product-import-auth-check'
KEY = 'public-test-only-http-signing-key-at-least-32-bytes'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--docker', default=shutil.which('docker') or 'C:/Program Files/Docker/Docker/resources/bin/docker.exe')
    parser.add_argument('--products', action='store_true', help='Also verify T08 product API and shared local PNGs')
    args = parser.parse_args()
    project = 'product-import-products-check' if args.products else PROJECT
    backend_tag = 'product-import-products-backend:t08' if args.products else 'product-import-auth-backend:t07'
    frontend_tag = 'product-import-products-frontend:t08' if args.products else 'product-import-auth-frontend:t07'
    docker = args.docker
    compose = [docker, 'compose', '-p', project, '--env-file', str(ROOT / '.env.test.example'), '-f', str(ROOT / 'compose.test.yaml')]
    suffix = uuid.uuid4().hex[:12]
    app = project + '-app-' + suffix
    nginx = project + '-nginx-' + suffix
    created = []
    http_network = project + '-http-' + suffix
    network_created = False
    volume = project + '-images-' + suffix
    volume_created = False
    app_mount = ['--mount', 'type=volume,source=' + volume + ',target=/var/app/images'] if args.products else []
    nginx_mount = ['--mount', 'type=volume,source=' + volume + ',target=/var/app/images,readonly'] if args.products else []
    report = {'task': 'T08' if args.products else 'T07', 'result': 'failed', 'project': project}

    def run(command, capture=False, input_text=None):
        return subprocess.run(command, cwd=ROOT, check=True, text=True, encoding='utf-8',
                              input=input_text, stdout=subprocess.PIPE if capture else None,
                              stderr=subprocess.STDOUT if capture else None).stdout

    def http(path, data=None, raw=None, content_type='application/json', authorization=None):
        body = json.dumps(data).encode() if data is not None else raw
        request = urllib.request.Request(base + path, data=body)
        if authorization is not None:
            request.add_header('Authorization', authorization)
        if body is not None:
            request.add_header('Content-Type', content_type)
        try:
            response = urllib.request.urlopen(request, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()

    try:
        print('Building production images for isolated HTTP verification.', flush=True)
        for dockerfile, tag in [('docker/backend/Dockerfile', backend_tag), ('docker/frontend/Dockerfile', frontend_tag)]:
            run([docker, 'build', '--target', 'production', '-f', dockerfile, '-t', tag, '.'])
        run(compose + ['down'])
        run(compose + ['up', '-d', '--wait', '--wait-timeout', '120', 'db-test'])
        if args.products:
            run([docker, 'volume', 'create', volume])
            volume_created = True
        run([docker, 'network', 'create', http_network])
        network_created = True
        run([docker, 'run', '-d', '--name', app, '--network', project + '_tests', '--network-alias', 'app',
             '-e', 'APP_ENV=development', '-e', 'APP_DIAGNOSTICS_ENABLED=false', '-e', 'DB_HOST=db-test',
             '-e', 'POSTGRES_DB=product_import_test', '-e', 'POSTGRES_USER=product_import_test',
             '-e', 'POSTGRES_PASSWORD=test-only-local-password', '-e', 'JWT_SECRET=' + KEY,
             ] + app_mount + [backend_tag])
        created.append(app)
        run([docker, 'network', 'connect', '--alias', 'app', http_network, app])
        for command in [['migrations:migrate', '--no-interaction'], ['fixtures:load']]:
            run([docker, 'exec', '--user', 'www-data', app, 'php', 'bin/console'] + command)
        run([docker, 'run', '-d', '--name', nginx, '--network', http_network,
             '--publish', '127.0.0.1::80'] + nginx_mount + [frontend_tag])
        created.append(nginx)
        ports = json.loads(run([docker, 'inspect', '--format', '{{json .NetworkSettings.Ports}}', nginx], capture=True))
        base = 'http://127.0.0.1:' + ports['80/tcp'][0]['HostPort']
        deadline = time.monotonic() + 30
        while True:
            try:
                status, _, _ = http('/')
                if status == 200:
                    break
            except (OSError, urllib.error.URLError):
                pass
            require(time.monotonic() < deadline, 'HTTP stack did not become ready')
            time.sleep(0.1)
        status, headers, body = http('/api/auth/login', {'username': 'fixture-user', 'password': 'password'})
        require(status == 200, 'Login failed: status ' + str(status))
        require(headers.get('Cache-Control') == 'no-store', 'Token response must not be cached')
        request_id = headers.get('X-Request-ID', '')
        require(len(request_id) == 32 and all(c in '0123456789abcdef' for c in request_id), 'Missing request ID')
        data = json.loads(body)
        require(set(data) == {'accessToken', 'tokenType', 'expiresIn', 'user'}, 'Login response differs from contract')
        require(data['tokenType'] == 'Bearer' and data['expiresIn'] == 3600 and data['user']['username'] == 'fixture-user', 'Invalid login metadata')
        token = data['accessToken']
        head, payload, signature = token.split('.')
        decode = lambda value: base64.urlsafe_b64decode(value + '=' * (-len(value) % 4))
        require(json.loads(decode(head))['alg'] == 'HS256', 'Unexpected token algorithm')
        require(hmac.compare_digest(decode(signature), hmac.new(KEY.encode(), (head + '.' + payload).encode(), hashlib.sha256).digest()), 'JWT signature mismatch')
        claims = json.loads(decode(payload))
        require(claims['sub'] == str(data['user']['id']) and claims['exp'] - claims['iat'] == 3600, 'Invalid token subject or expiry')
        require(claims['iss'] == 'product-import' and claims['aud'] == 'product-import-web', 'Invalid token scope')
        # Verify the very same HTTP-issued token with the production PHP validator; stdin avoids logging it.
        code = "require 'vendor/autoload.php'; $id = (new App\\Security\\TokenService())->subject(trim(stream_get_contents(STDIN))); echo $id;"
        result = run([docker, 'exec', '-i', '--user', 'www-data', app, 'php', '-r', code], capture=True, input_text=token)
        require(result == str(data['user']['id']), 'Server rejected its HTTP token')
        unauthorized = []
        for username, password in [('fixture-user', 'wrong-password-sentinel'), ('unknown-user', 'password')]:
            status, response_headers, response_body = http('/api/auth/login', {'username': username, 'password': password})
            require(status == 401 and response_headers.get('WWW-Authenticate') == 'Bearer', 'Invalid login did not return 401')
            unauthorized.append(response_body)
        require(unauthorized[0] == unauthorized[1], 'Credential errors expose account existence')
        require(http('/api/auth/login', raw=b'{')[0] == 400, 'Invalid JSON accepted')
        require(http('/api/auth/login', raw=b'x', content_type='text/plain')[0] == 415, 'Invalid Content-Type accepted')
        if args.products:
            def product(path, authorization='Bearer ' + token, status=200):
                code, response_headers, response_body = http(path, authorization=authorization)
                require(code == status, 'Product request returned ' + str(code) + ': ' + path)
                require(response_headers.get('Content-Type', '').startswith('application/json'), 'Product response must be JSON')
                return json.loads(response_body)

            first = product('/api/products')
            second = product('/api/products?page=2')
            require(first['pagination'] == {'page': 1, 'limit': 20, 'total': 48, 'totalPages': 3}, 'Wrong pagination')
            a = [item['id'] for item in first['items']]
            b = [item['id'] for item in second['items']]
            require(len(a) == 20 and len(b) == 20 and not set(a).intersection(b) and max(a) < min(b), 'Pages overlap or have unstable order')
            require(product('/api/products')['items'] == first['items'], 'Repeated page changed')
            detail = product('/api/products/' + str(a[0]))
            require(detail['externalCode'] == 'FIX-0001' and detail['price'] == '100.00' and detail['purchasePrice'] == '75.00', 'Decimal DTO mismatch')
            require(detail['createdAt'] == '2026-01-01T00:00:00Z' and len(detail['attributes']) == 2 and len(detail['images']) == 1, 'Detail relations/timestamps missing')
            filtered = product('/api/products?' + urllib.parse.urlencode({'name': 'фУтБоЛкА', 'price_min': '100', 'price_max': '700', 'limit': '2'}))
            require(filtered['pagination']['total'] == 3 and filtered['pagination']['totalPages'] == 2, 'Filtered count mismatch')
            require([item['price'] for item in filtered['items']] == ['100.00', '400.00'], 'Inclusive price/name mismatch')
            last = product('/api/products?' + urllib.parse.urlencode({'name': 'ФУТБОЛКА', 'price_min': '100', 'price_max': '700', 'limit': '2', 'page': '2'}))
            require([item['price'] for item in last['items']] == ['700.00'], 'Upper bound excluded')
            require(product('/api/products?page=2147483647')['items'] == [], 'Far page is not empty')
            empty = product('/api/products?price_min=9999999999999999.99')
            require(empty['items'] == [] and empty['pagination']['totalPages'] == 0, 'Empty result metadata mismatch')
            for path in ['/api/products', '/api/products/' + str(a[0])]:
                product(path, authorization=None, status=401)
                product(path, authorization='Bearer invalid.jwt.signature', status=401)
            for path in ['/api/products?page=0', '/api/products?price_min=5&price_max=4', '/api/products?page=1&page=2', '/api/products/abc']:
                product(path, status=400)
            product('/api/products/2147483647', status=404)
            image_path = detail['images'][0]['path']
            code, image_headers, image_bytes = http(image_path)
            require(code == 200 and image_headers.get('Content-Type') == 'image/png', 'Local PNG not served by nginx')
            require(image_headers.get('X-Content-Type-Options') == 'nosniff', 'Missing PNG nosniff')
            expected_png = (ROOT / 'backend' / 'fixtures' / 'images' / Path(image_path).name).read_bytes()
            require(image_bytes == expected_png, 'Local PNG content mismatch')
            report['products'] = {'pagesDistinct': True, 'total': 48, 'filteredTotal': 3, 'inclusiveBounds': True,
                                  'decimalStrings': True, 'detailRelations': True, 'statuses': [200, 400, 401, 404],
                                  'localPngHttp': 200, 'sharedIsolatedImageVolume': True}
            print('PASS: T08 protected list/detail, SQL pagination behavior, filters, 400/401/404 and local PNG via nginx.', flush=True)
        logs = ''.join(run([docker, 'logs', container], capture=True) for container in created)
        for secret in [token, KEY, 'wrong-password-sentinel']:
            require(secret not in logs, 'Sensitive data appeared in logs')
        report.update(result='passed', login=200, invalidCredentials=401, invalidJson=400, unsupportedContentType=415,
                      realNginxFpm=True, signatureVerifiedIndependently=True, serverValidatedToken=True,
                      lifetimeSeconds=3600, requestId=True, secretsAbsentFromLogs=True)
        print('PASS: real nginx/FPM login, PostgreSQL fixture user, signed JWT, server validation, safe errors/logs.', flush=True)
    finally:
        cleanup_errors = []
        commands = [[docker, 'rm', '-f', container] for container in reversed(created)]
        commands.append(compose + ['down'])
        if network_created:
            commands.append([docker, 'network', 'rm', http_network])
        for command in commands:
            try:
                run(command)
            except subprocess.CalledProcessError:
                cleanup_errors.append('Disposable resource cleanup failed')
        if volume_created:
            try:
                run([docker, 'volume', 'rm', volume])
            except subprocess.CalledProcessError:
                cleanup_errors.append('Disposable image volume cleanup failed')
        report['cleanup'] = 'failed' if cleanup_errors else 'passed'
        if cleanup_errors:
            report['result'] = 'failed'
        (ROOT / 'tmp').mkdir(exist_ok=True)
        (ROOT / 'tmp' / ('products-http-check.json' if args.products else 'auth-http-check.json')).write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
        if cleanup_errors:
            raise RuntimeError('Disposable resource cleanup failed')



if __name__ == '__main__':
    main()
