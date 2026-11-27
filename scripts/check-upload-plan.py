"""Check a `hostinger up --dry-run --format json` plan against release.manifest.

Used by .github/workflows/deploy.yml before the real upload. Fails (exit 1) when:
  - the plan would deploy as a Node.js app (the site is PHP), or
  - the plan lists a skipped file that the release needs (any path in release.manifest), or
  - the plan lists the uploaded files and a needed file is missing from them.

The CLI's JSON field names are not documented in detail, so the plan is searched for any list
under a key containing "skip" or "upload" (strings, or objects with a path-like field).
If the plan has no file lists at all, the check says so and passes: cron.sh still refuses to
migrate an incomplete upload, and the workflow's live check runs after the upload.

    python3 scripts/check-upload-plan.py plan.json build/deploy/deploy/hostinger/release.manifest
"""

import json
import sys

PATH_KEYS = ("path", "file", "name", "relativePath", "relative_path")


def paths_in(value):
    out = []
    if isinstance(value, list):
        for item in value:
            if isinstance(item, str):
                out.append(item)
            elif isinstance(item, dict):
                for key in PATH_KEYS:
                    if isinstance(item.get(key), str):
                        out.append(item[key])
                        break
    clean = []
    for p in out:
        p = p.replace("\\", "/")
        clean.append(p[2:] if p.startswith("./") else p)
    return clean


def walk(node, found):
    if isinstance(node, dict):
        for key, value in node.items():
            low = key.lower()
            if isinstance(value, list):
                if "skip" in low:
                    found["skipped"].extend(paths_in(value))
                elif "upload" in low:
                    found["uploaded"].extend(paths_in(value))
            if isinstance(value, str) and low in ("type", "apptype", "deploytype") and "node" in value.lower():
                found["node"] = True
            walk(value, found)
    elif isinstance(node, list):
        for item in node:
            walk(item, found)


def main():
    plan_path, manifest_path = sys.argv[1], sys.argv[2]
    with open(plan_path, encoding="utf-8") as fh:
        plan = json.load(fh)
    with open(manifest_path, encoding="utf-8") as fh:
        lines = [line.rstrip("\n") for line in fh if line.strip()]
    needed = set(lines[1:])  # first line is "release <id>"

    found = {"skipped": [], "uploaded": [], "node": False}
    walk(plan, found)

    summary = {k: v for k, v in plan.items() if not isinstance(v, (list, dict))} if isinstance(plan, dict) else {}
    print("Plan:", json.dumps(summary))
    print(f"Release needs {len(needed)} files; plan lists {len(found['uploaded'])} uploaded, {len(found['skipped'])} skipped.")

    errors = []
    if found["node"]:
        errors.append("The plan would deploy a Node.js app; this site is PHP.")
    bad_skips = sorted(set(found["skipped"]) & needed)
    if bad_skips:
        errors.append(f"{len(bad_skips)} needed files would be skipped, e.g. {bad_skips[:10]}")
    if found["uploaded"]:
        missing = sorted(needed - set(found["uploaded"]))
        if missing:
            errors.append(f"{len(missing)} needed files are not in the upload list, e.g. {missing[:10]}")
    if not found["skipped"] and not found["uploaded"]:
        print("::warning::The plan has no file lists to compare; relying on cron.sh's manifest check.")

    for err in errors:
        print(f"::error::{err}")
    sys.exit(1 if errors else 0)


if __name__ == "__main__":
    main()
