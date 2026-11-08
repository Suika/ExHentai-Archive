#!/usr/bin/env python3
"""Writes a small zip that uses the ZIP64 format (like every archive over 4 GiB does).

Usage: python3 tests/tools/make-zip64.py <out.zip> <file>...

zipfile only writes ZIP64 records for entries/offsets over 4 GiB; lowering its limit to 0 while
writing makes it use ZIP64 extra fields for every entry plus the ZIP64 end of central directory.
Used to create tests/fixtures/archives/zip64.zip (pages: 1.jpg, 2.png, 3.jpg).
"""
import os
import sys
import zipfile

out, files = sys.argv[1], sys.argv[2:]
zipfile.ZIP64_LIMIT = 0
with zipfile.ZipFile(out, 'w', compression=zipfile.ZIP_STORED, allowZip64=True) as zf:
    for path in files:
        with open(path, 'rb') as src, zf.open(os.path.basename(path), 'w', force_zip64=True) as dst:
            dst.write(src.read())
