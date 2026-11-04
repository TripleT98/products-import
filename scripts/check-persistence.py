"""T03: isolated database row survives ordinary Compose down/up."""
import argparse
import subprocess
import uuid


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--docker', default='docker')
    args = parser.parse_args()

    def docker(*command, stdin=None, capture=False):
        return subprocess.run(
            [args.docker, *command], input=stdin, text=True, encoding='utf-8',
            stdout=subprocess.PIPE if capture else None, check=True,
        ).stdout

    username = docker('compose', 'exec', '-T', 'db', 'printenv', 'POSTGRES_USER', capture=True).strip()

    def sql(database, statement):
        return docker('compose', 'exec', '-T', 'db', 'psql', '-X', '-v', 'ON_ERROR_STOP=1',
                      '-U', username, '-d', database, '-tA', stdin=statement + '\n', capture=True)

    probe = 't03_probe_' + uuid.uuid4().hex
    created = False
    try:
        sql('postgres', f'CREATE DATABASE {probe}')
        created = True
        sql(probe, "CREATE TABLE marker (value text NOT NULL); INSERT INTO marker VALUES ('t03-persisted');")
        docker('compose', 'down')
        docker('compose', 'up', '-d', '--no-build', '--wait', '--wait-timeout', '180')
        value = sql(probe, 'SELECT value FROM marker').strip()
        if value != 't03-persisted':
            raise RuntimeError('Persistence check failed: marker differs.')
        print('PASS: database row survived ordinary docker compose down/up.')
    finally:
        if created:
            try:
                sql('postgres', f'DROP DATABASE {probe}')
            except subprocess.CalledProcessError:
                print(f'Warning: probe database {probe} remains; app databases and volumes preserved.')


if __name__ == '__main__':
    main()
