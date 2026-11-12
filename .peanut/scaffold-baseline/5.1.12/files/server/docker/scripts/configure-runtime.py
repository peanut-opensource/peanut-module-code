#!/usr/bin/env python3
"""Create a new instance's private Compose settings; never rewrite an instance."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import sys

IMAGE = re.compile(r"(?:sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})\Z")


def configure(docker_root, php_image, project=None, http_bind=None, http_port=None, mysql_host_port=None,
              database_source='bundled'):
    docker_root = Path(docker_root)
    if not docker_root.is_absolute() or docker_root.resolve() != docker_root or not docker_root.is_dir():
        raise ValueError("Docker directory must be a canonical ordinary directory")
    template = docker_root / "runtime-configuration.example.json"
    if template.is_symlink() or not template.is_file() or template.stat().st_nlink != 1:
        raise ValueError("public runtime configuration template is unavailable")
    data = json.loads(template.read_text(encoding="utf-8"))
    keys = {"schema_version", "protocol", "COMPOSE_PROJECT_NAME", "HTTP_BIND", "HTTP_PORT",
            "MYSQL_HOST_PORT", "TZ", "NGINX_IMAGE", "MYSQL_IMAGE"}
    if set(data) != keys or data["schema_version"] != 1 or data["protocol"] != "peanut.runtime-configuration-template.v1":
        raise ValueError("unsupported runtime configuration template")
    values = {key: data[key] for key in keys - {"schema_version", "protocol"}}
    values["PHP_IMAGE"] = php_image
    if database_source not in ('bundled', 'existing'):
        raise ValueError('invalid database source')
    if database_source == 'existing' and mysql_host_port is not None:
        raise ValueError('existing database uses its configured connection, not a local MySQL port')
    for key, value in (("COMPOSE_PROJECT_NAME", project), ("HTTP_BIND", http_bind),
                       ("HTTP_PORT", http_port), ("MYSQL_HOST_PORT", mysql_host_port)):
        if value is not None:
            values[key] = value
    if not isinstance(values["COMPOSE_PROJECT_NAME"], str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,62}", values["COMPOSE_PROJECT_NAME"]):
        raise ValueError("Compose project must be an explicit lowercase project identity")
    address = ipaddress.ip_address(values["HTTP_BIND"])
    if address.version != 4:
        raise ValueError("this Compose port template requires an explicit IPv4 bind address")
    for key in (("HTTP_PORT", "MYSQL_HOST_PORT") if database_source == 'bundled' else ("HTTP_PORT",)):
        if type(values[key]) is not int or not 1024 <= values[key] <= 65535:
            raise ValueError("ports must be integer values from 1024 through 65535")
    if database_source == 'bundled' and values["HTTP_PORT"] == values["MYSQL_HOST_PORT"]:
        raise ValueError("HTTP and local MySQL ports must differ")
    for key in (("PHP_IMAGE", "NGINX_IMAGE", "MYSQL_IMAGE") if database_source == 'bundled' else ("PHP_IMAGE", "NGINX_IMAGE")):
        if not isinstance(values[key], str) or not IMAGE.fullmatch(values[key]):
            raise ValueError(key + " must name a prepared immutable image")
    if not isinstance(values["TZ"], str) or not re.fullmatch(r"[A-Za-z_]+(?:/[A-Za-z0-9_+-]+)*", values["TZ"]):
        raise ValueError("invalid timezone identifier")
    # This path is for new instance configuration, not migration or recovery.
    for relative in (".env",):
        path = docker_root / relative
        if path.is_symlink() or path.exists():
            raise ValueError("instance configuration or data already exists; preserve it")
    # Full source archives keep their original empty directory placeholders.
    # These are not an initialized database or generated secret store.
    for relative in ('mysql', 'secrets'):
        path = docker_root / relative
        if path.is_symlink() or (path.exists() and not path.is_dir()):
            raise ValueError('instance data directory is unsafe; preserve it')
        if path.exists():
            for child in path.iterdir():
                if (child.name not in ('.gitkeep', '.gitignore') or child.is_symlink()
                        or not child.is_file() or child.stat().st_nlink != 1 or child.stat().st_size != 0):
                    raise ValueError('instance data already exists; preserve it')
    installed = docker_root.parent / "private/installation"
    if installed.is_symlink() or (installed.exists() and any(installed.iterdir())):
        raise ValueError("installation state already exists; refuse initial configuration")
    order = ("COMPOSE_PROJECT_NAME", "HTTP_BIND", "HTTP_PORT", "MYSQL_HOST_PORT", "PHP_IMAGE", "NGINX_IMAGE", "MYSQL_IMAGE")
    if database_source == 'existing':
        order = tuple(key for key in order if key not in ('MYSQL_HOST_PORT', 'MYSQL_IMAGE'))
    payload = "# Generated from the public template. Private Docker orchestration configuration.\n"
    payload += "".join(key + "=" + str(values[key]) + "\n" for key in order)
    if database_source == 'bundled':
        payload += "MYSQL_ROOT_PASSWORD=" + secrets.token_hex(32) + "\n"
    payload += "TZ=" + str(values["TZ"]) + "\n"
    target = docker_root / ".env"
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as output:
            output.write(payload)
            output.flush()
            os.fsync(output.fileno())
        directory = os.open(docker_root, os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    except BaseException:
        # Leave an existing or partially persisted configuration for diagnosis.
        raise
    return {"status": "created", "path": str(target), "project": values["COMPOSE_PROJECT_NAME"],
            "database_source": database_source,
            "start_command": [str(docker_root / "scripts/start.sh"),
                              *(['-f', 'compose.registered-database.yaml'] if database_source == 'existing' else [])]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--docker-root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--php-image", required=True)
    parser.add_argument("--project")
    parser.add_argument("--http-bind")
    parser.add_argument("--http-port", type=int)
    parser.add_argument("--mysql-host-port", type=int)
    parser.add_argument("--database-source", choices=('bundled', 'existing'), default='bundled',
                        help='existing first startup requires start.sh -f compose.registered-database.yaml; see start_command in the result')
    args = parser.parse_args()
    print(json.dumps(configure(args.docker_root, args.php_image, args.project, args.http_bind,
                               args.http_port, args.mysql_host_port, args.database_source)))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, TypeError, KeyError) as error:
        print("configure-runtime: " + str(error), file=sys.stderr)
        sys.exit(1)
