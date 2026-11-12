"""Official Module catalog IO shared by the existing producer and publisher.

V2 keeps a complete dependency catalog but references the original immutable
Release for unchanged archives. It is not a second package manager.
"""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
from urllib.parse import quote

REPO = "peanut-opensource/peanut-module-code"
INDEX = "module-release-index.json"
API = f"repos/{REPO}"
KEY = re.compile(r"^peanut\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*$")
SEMVER = re.compile(r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)"
                    r"(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)"
                    r"(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?"
                    r"(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$")
TAG = re.compile(r"^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.(0|[1-9][0-9]*))?$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
SHA1 = re.compile(r"^[a-f0-9]{40}$")


def require(condition, code):
    if not condition:
        raise RuntimeError("MODULE_RELEASE_" + code)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def compare_versions(left, right):
    """Strict SemVer precedence; never compare publication timestamps."""
    a, b = SEMVER.fullmatch(left), SEMVER.fullmatch(right)
    require(a is not None and b is not None, "VERSION_INVALID")
    nums_a, nums_b = tuple(map(int, a.groups()[:3])), tuple(map(int, b.groups()[:3]))
    if nums_a != nums_b:
        return 1 if nums_a > nums_b else -1
    aa, bb = a.group(4), b.group(4)
    if aa == bb:
        return 0
    if aa is None or bb is None:
        return 1 if aa is None else -1
    for x, y in zip(aa.split("."), bb.split(".")):
        if x == y:
            continue
        if x.isdigit() and y.isdigit():
            return 1 if int(x) > int(y) else -1
        if x.isdigit() != y.isdigit():
            return -1 if x.isdigit() else 1
        return 1 if x > y else -1
    return 1 if len(aa.split(".")) > len(bb.split(".")) else -1


def artifact_tag(index, row):
    return row.get("artifact_tag", index["tag"])


def module_identity(row):
    """Transport location/catalog revision are not an independently versioned Module."""
    fields = ("key", "version", "asset", "sha256", "bytes", "license", "editions",
              "kernel_constraint", "dependencies")
    return {name: row[name] for name in fields}


def read_index(data, tag):
    require(len(data) <= 4 * 1024 * 1024, "INDEX_TOO_LARGE")
    index = json.loads(data)
    require(isinstance(index, dict), "INDEX_INVALID")
    schema = index.get("schema_version")
    require(type(schema) is int and schema in (1, 2)
            and index.get("protocol") == f"peanut.module-release-index.v{schema}"
            and index.get("repository") == REPO and index.get("tag") == tag
            and isinstance(tag, str) and TAG.fullmatch(tag)
            and isinstance(index.get("commit"), str) and SHA1.fullmatch(index["commit"])
            and isinstance(index.get("modules"), list) and 0 < len(index["modules"]) <= 100,
            "INDEX_INVALID")
    seen = set()
    for row in index["modules"]:
        require(isinstance(row, dict), "INDEX_ROW_INVALID")
        key, version = row.get("key"), row.get("version")
        require(isinstance(key, str) and KEY.fullmatch(key) and key not in seen
                and isinstance(version, str) and SEMVER.fullmatch(version)
                and row.get("asset") == f"{key}-{version}.tar"
                and isinstance(row.get("sha256"), str) and SHA256.fullmatch(row["sha256"])
                and type(row.get("bytes")) is int and 0 < row["bytes"] <= 67108864
                and row.get("license") == "Apache-2.0" and isinstance(row.get("editions"), list)
                and 0 < len(row["editions"]) <= 2
                and all(isinstance(x, str) and x in ("standalone", "multi-tenant") for x in row["editions"])
                and len(set(row["editions"])) == len(row["editions"])
                and isinstance(row.get("kernel_constraint"), str) and row["kernel_constraint"]
                and isinstance(row.get("dependencies"), list)
                and ("-rc." in tag or "-" not in version.split("+")[0]), "INDEX_ROW_INVALID")
        if schema == 2:
            require(isinstance(row.get("artifact_tag"), str) and TAG.fullmatch(row["artifact_tag"])
                    and isinstance(row.get("source_sha256"), str) and SHA256.fullmatch(row["source_sha256"]),
                    "INDEX_REFERENCE_INVALID")
        else:
            require("artifact_tag" not in row, "INDEX_REFERENCE_REQUIRES_V2")
        if "payload_sha256" in row:
            require(isinstance(row["payload_sha256"], str) and SHA256.fullmatch(row["payload_sha256"]), "INDEX_PAYLOAD_INVALID")
        seen.add(key)
    for row in index["modules"]:
        dependencies = set()
        for dependency in row["dependencies"]:
            require(isinstance(dependency, dict) and dependency.get("module_key") in seen
                    and dependency["module_key"] not in dependencies
                    and isinstance(dependency.get("version"), str) and dependency["version"],
                    "DEPENDENCY_MISSING_OR_INVALID")
            dependencies.add(dependency["module_key"])
    return index


def gh(*arguments):
    result = subprocess.run(["gh", *arguments], capture_output=True, check=False, timeout=120)
    if result.returncode:
        raise RuntimeError(f"MODULE_RELEASE_GITHUB_FAILED ({result.returncode}): "
                           + result.stderr.decode("utf8", errors="replace")[-1200:])
    return result.stdout


class ReleaseStore:
    """One bounded read snapshot. Network errors never become empty history."""

    def __init__(self, request=gh):
        self.request = request
        self.release_cache = {}
        self.asset_cache = {}
        self.index_cache = {}

    def api(self, path):
        return json.loads(self.request("api", path))

    def pages(self, path):
        for page in range(1, 21):
            rows = self.api(f"{path}?per_page=100&page={page}")
            require(isinstance(rows, list) and len(rows) <= 100, "GITHUB_RESPONSE_INVALID")
            yield from rows
            if len(rows) < 100:
                return
        raise RuntimeError("MODULE_RELEASE_GITHUB_PAGINATION_LIMIT")

    def release(self, tag, immutable=True):
        require(isinstance(tag, str) and TAG.fullmatch(tag), "REFERENCE_TAG_INVALID")
        if tag not in self.release_cache:
            self.release_cache[tag] = self.api(f"{API}/releases/tags/{quote(tag, safe='')}")
        release = self.release_cache[tag]
        require(release.get("tag_name") == tag and release.get("draft") is False
                and release.get("published_at") and release.get("prerelease") == ("-rc." in tag)
                and release.get("html_url") == f"https://github.com/{REPO}/releases/tag/{quote(tag, safe='')}"
                and type(release.get("id")) is int and release["id"] > 0
                and release.get("url") == f"https://api.github.com/{API}/releases/{release['id']}"
                and (not immutable or release.get("immutable") is True), "REFERENCE_RELEASE_INVALID")
        return release

    def assets(self, release):
        ident = release["id"]
        if ident not in self.asset_cache:
            self.asset_cache[ident] = list(self.pages(f"{API}/releases/{ident}/assets"))
        return self.asset_cache[ident]

    def asset(self, release, name):
        rows = [item for item in self.assets(release) if item.get("name") == name]
        require(len(rows) == 1, "ASSET_MISSING_OR_DUPLICATE: " + name)
        item = rows[0]
        expected = f"https://github.com/{REPO}/releases/download/{quote(release['tag_name'], safe='')}/{quote(name, safe='')}"
        draft = re.fullmatch(rf"https://github\.com/{re.escape(REPO)}/releases/download/untagged-[a-f0-9]+/{re.escape(quote(name, safe=''))}", item.get("browser_download_url", ""))
        require((bool(draft) if release.get("draft") is True else item.get("browser_download_url") == expected)
                and type(item.get("id")) is int and item["id"] > 0 and item.get("state") == "uploaded"
                and item.get("url") == f"https://api.github.com/{API}/releases/assets/{item['id']}"
                and isinstance(item.get("digest"), str) and re.fullmatch(r"sha256:[a-f0-9]{64}", item["digest"])
                and type(item.get("size")) is int and 0 < item["size"] <= 67108864, "ASSET_IDENTITY_INVALID: " + name)
        return item

    def bytes(self, release, name):
        asset = self.asset(release, name)
        data = self.request("api", f"{API}/releases/assets/{asset['id']}", "-H", "Accept: application/octet-stream")
        require(len(data) == asset["size"] and "sha256:" + sha256(data) == asset["digest"], "API_DIGEST_MISMATCH: " + name)
        return data

    def index(self, release):
        if release["id"] not in self.index_cache:
            data = self.bytes(release, INDEX)
            self.index_cache[release["id"]] = (read_index(data, release["tag_name"]), data)
        return self.index_cache[release["id"]]

    def reference(self, index, row):
        tag = artifact_tag(index, row)
        release = self.release(tag)
        origin, _ = self.index(release)
        originals = [item for item in origin["modules"] if item["key"] == row["key"]]
        require(len(originals) == 1 and module_identity(originals[0]) == module_identity(row)
                and artifact_tag(origin, originals[0]) == tag, "REFERENCE_IDENTITY_MISMATCH: " + row["key"])
        asset = self.asset(release, row["asset"])
        require(asset["digest"] == "sha256:" + row["sha256"] and asset["size"] == row["bytes"], "REFERENCE_DIGEST_MISMATCH")
        return release

    def baseline(self, channel, tag=None):
        require(channel in ("stable", "prerelease"), "CHANNEL_INVALID")
        if tag:
            release = self.release(tag)
            require(channel == "prerelease" or release.get("prerelease") is False, "BASELINE_CHANNEL_MISMATCH")
            return self.index(release)
        from functools import cmp_to_key
        releases = [r for r in self.pages(f"{API}/releases") if r.get("draft") is False
                    and r.get("published_at") and isinstance(r.get("tag_name"), str) and TAG.fullmatch(r["tag_name"])
                    and (channel == "prerelease" or r.get("prerelease") is False)]
        releases.sort(key=cmp_to_key(lambda a, b: compare_versions(b["tag_name"][1:], a["tag_name"][1:])))
        if not releases:
            return None, None
        release = self.release(releases[0]["tag_name"])
        return self.index(release)
