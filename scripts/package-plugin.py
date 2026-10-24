#!/usr/bin/env python3
"""Build a review ZIP from an immutable Git tree. Never publish or contact a host."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import zipfile

ROOTS = (
    "dsquared-hub-connector.php", "uninstall.php", "readme.txt", "README.md",
    "CHANGELOG.md", "LICENSE", "includes", "admin", "assets",
)
SLUG = "dsquared-hub-connector"


def git(*args):
    return subprocess.check_output(["git", *args])


def build(commit, output):
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("Provide the exact 40-character commit SHA, not a branch or tag.")
    if git("cat-file", "-t", commit).strip() != b"commit":
        raise ValueError("Candidate must identify a commit.")
    files = {}
    for entry in git("ls-tree", "-rz", commit, "--", *ROOTS).split(b"\0"):
        if not entry:
            continue
        metadata, raw_path = entry.split(b"\t", 1)
        mode, kind, oid = metadata.decode("ascii").split()
        name = raw_path.decode("utf-8")
        path = PurePosixPath(name)
        if (kind != "blob" or mode not in ("100644", "100755")
                or path.is_absolute() or ".." in path.parts or "\\" in name
                or any(ord(c) < 32 for c in name)):
            raise ValueError("Unsupported package entry: " + name)
        files[name] = git("cat-file", "blob", oid)
    if {PurePosixPath(name).parts[0] for name in files} != set(ROOTS):
        raise ValueError("A required package file or directory is missing.")

    main = files[SLUG + ".php"].decode("utf-8")
    readme = files["readme.txt"].decode("utf-8")
    patterns = (
        (r"(?m)^\s*\* Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", main),
        (r"define\(\s*'DHC_VERSION',\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)", main),
        (r"(?m)^Stable tag:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", readme),
    )
    versions = [re.search(pattern, text) for pattern, text in patterns]
    if not all(versions) or len({match.group(1) for match in versions}) != 1:
        raise ValueError("Plugin header, DHC_VERSION and Stable tag must agree.")
    version = versions[0].group(1)
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    archive = output / (SLUG + ".zip")
    manifest_path = output / "manifest.json"
    checksum_path = output / (SLUG + ".zip.sha256")
    if any(path.exists() for path in (archive, manifest_path, checksum_path)):
        raise ValueError("Refusing to overwrite an existing review artifact.")

    # Stored entries avoid compressor/version differences across CI and macOS.
    # Stable metadata and ordering make the same tree produce identical bytes.
    with zipfile.ZipFile(archive, "x", compression=zipfile.ZIP_STORED) as package:
        for name, data in sorted(files.items()):
            info = zipfile.ZipInfo(SLUG + "/" + name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            package.writestr(info, data)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    manifest = {
        "commit": commit, "version": version, "archive": archive.name,
        "sha256": digest, "file_count": len(files),
        "files": [{"path": SLUG + "/" + name, "bytes": len(data),
                   "sha256": hashlib.sha256(data).hexdigest()}
                  for name, data in sorted(files.items())],
    }
    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    checksum_path.write_text(digest + "  " + archive.name + "\n", encoding="ascii")
    print(json.dumps({key: manifest[key] for key in
                      ("commit", "version", "archive", "sha256", "file_count")}))
    return manifest


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--output-dir", required=True)
    args = parser.parse_args()
    try:
        build(args.commit, args.output_dir)
    except (ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, str(error) + "\n")
