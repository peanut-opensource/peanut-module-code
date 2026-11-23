"""Move a stopped instance's legacy storage into the canonical host layout."""

import argparse
import os
from pathlib import Path


def directory(path):
    if path.is_symlink() or (path.exists() and not path.is_dir()):
        raise ValueError(f"protected directory is linked or has an unsafe type: {path}")


def merge_directory(source, destination, apply):
    # Both trees may already contain files after an interrupted upgrade.
    # Inspect both spaces before moving anything. Never overwrite a leaf.
    for entry in source.iterdir():
        target = destination / entry.name
        if not os.path.lexists(target):
            if apply:
                entry.rename(target)
        elif entry.is_dir() and not entry.is_symlink() and target.is_dir() and not target.is_symlink():
            merge_directory(entry, target, apply)
        elif (entry.name == ".gitkeep" and not entry.is_symlink() and not target.is_symlink()
              and entry.is_file() and target.is_file() and entry.stat().st_size == target.stat().st_size == 0):
            if apply:
                entry.unlink()
        else:
            raise ValueError(f"storage migration conflict: {entry} and {target}")
    if apply:
        source.rmdir()


def migrate(server):
    storage = server / "storage"
    public = server / "public"
    private = server / "private"
    link = public / "storage"
    for path in (server, storage, public, private, storage / "public", storage / "private", private / "storage"):
        directory(path)
    if link.is_symlink():
        if os.readlink(link) != "../storage/public":
            raise ValueError(f"protected directory has an unexpected symlink: {link}")
    else:
        directory(link)

    spaces = [(private / "storage", storage / "private")]
    if not link.is_symlink():
        spaces.append((link, storage / "public"))
    for source, destination in spaces:
        if source.is_dir() and destination.is_dir():
            merge_directory(source, destination, False)

    storage.mkdir(exist_ok=True)
    for source, destination in spaces:
        if source.is_dir() and destination.is_dir():
            merge_directory(source, destination, True)
        elif source.is_dir():
            source.rename(destination)
        else:
            destination.mkdir(exist_ok=True)
    (storage / "public").mkdir(exist_ok=True)
    public.mkdir(exist_ok=True)
    if not link.is_symlink():
        link.symlink_to("../storage/public", target_is_directory=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("server", type=Path)
    try:
        migrate(parser.parse_args().server)
    except (OSError, ValueError) as error:
        parser.exit(1, f"storage migration failed: {error}\n")
