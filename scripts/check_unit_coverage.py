#!/usr/bin/env python3
"""Qualify Unit-attributed executable lines from the one canonical PHPUnit run.

Uses PHPUnit's serialized per-test coverage, never all-suite Clover hits as Unit
proof. Classification is reviewed source data in etc/test-categories.json.
"""
import argparse
import json
import pathlib
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
CATEGORIES = {"Unit", "Integration", "Functional"}


def category(classes, cls, method):
    # Only call after admitting the exact expanded identity from enumeration.
    entry = classes[cls]
    return entry.get("methods", {}).get(method, entry)["category"]


def expanded_identity(cls, name):
    """Normalize native TeamCity display names to XML/coverage's lossless #case IDs.

    PHPUnit uses 'method with data set #0' or 'method with data set "label"'
    for display, and 'method#0' or 'method#label' for XML/raw coverage. Preserve
    the complete case label (including punctuation); never collapse to a method.
    """
    match = re.fullmatch(r'(\w+) with data set (?:#(\d+)|"(.*)")', name)
    if match:
        name = match[1] + "#" + (match[2] if match[2] is not None else match[3])
    return cls.lstrip("\\") + "::" + name


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--coverage", default="var/reports/coverage/coverage.php")
    parser.add_argument("--tests", default="var/reports/coverage/tests.xml")
    parser.add_argument("--timing", default="var/reports/coverage/teamcity.log")
    parser.add_argument("--output", default="var/reports/coverage/unit.json")
    parser.add_argument("--inspect", action="store_true", help="Report gaps without awarding a gate pass")
    args = parser.parse_args()
    classes = json.loads((ROOT / "etc/test-categories.json").read_text())["classes"]
    discovered = {}
    identities = {}
    for element in ET.parse(args.tests).iter():
        if element.tag.endswith("testClass"):
            name = element.attrib["name"]
            entry = classes[name]  # Unknown runnable classes fail closed.
            if not element.attrib["file"].endswith("/" + entry["file"]):
                raise ValueError(f"Class/file identity mismatch: {name}")
            if name in discovered:
                raise ValueError(f"Duplicate enumerated class: {name}")
            discovered[name] = element
            for method in element:
                if method.tag.endswith("testMethod"):
                    identity = method.attrib["id"]
                    if re.fullmatch(r"\w+", method.attrib["name"]) is None:
                        raise ValueError("Malformed enumerated method name")
                    base = name + "::" + method.attrib["name"]
                    if identity != base and not identity.startswith(base + "#"):
                        raise ValueError(f"Inconsistent enumerated identity: {identity}")
                    if identity in identities:
                        raise ValueError(f"Duplicate enumerated identity: {identity}")
                    assigned = category(classes, name, method.attrib["name"])
                    if assigned not in CATEGORIES:
                        raise ValueError("Invalid category")
                    identities[identity] = assigned
    if not args.inspect and set(discovered) != set(classes):
        raise ValueError("Classification contains stale or undiscovered classes")
    for name in discovered:
        entry = classes[name]
        if entry["category"] not in CATEGORIES or not entry["reason"]:
            raise ValueError(f"Incomplete classification: {name}")
        methods = {m.attrib["name"] for m in discovered[name] if m.tag.endswith("testMethod")}
        if not args.inspect and not set(entry.get("methods", {})) <= methods:
            raise ValueError(f"Stale method classification: {name}")

    # Keep this adapter small and pinned to the installed serialized format. It
    # runs only locally generated, trusted PHPUnit coverage (a PHP executable).
    exporter = r'''
require 'vendor/autoload.php';
$c = require $argv[1];
if (($c['buildInformation']['phpCodeCoverage']['serializationFormat'] ?? null) !== 4) {
    throw new RuntimeException('Unsupported coverage serialization format');
}
$d = $c['codeCoverage'];
$identities = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$unit = []; $seen = [];
foreach ($d->testIds() as $index => $id) {
    if (!isset($identities[$id])) { throw new RuntimeException('Unknown expanded coverage test: '.$id); }
    if (isset($seen[$id])) { throw new RuntimeException('Duplicate coverage test: '.$id); }
    $seen[$id] = true;
    if ($identities[$id] === 'Unit') { $unit[$index] = true; }
}
$files = [];
foreach ($d->lineCoverage() as $path => $lines) {
    $missing = []; $total = 0;
    foreach ($lines as $line => $hits) {
        if (!is_array($hits)) { continue; }
        ++$total;
        if (array_intersect_key($hits, $unit) === []) { $missing[] = $line; }
    }
    $files['src/'.$path] = ['covered' => $total-count($missing), 'total' => $total, 'missing' => $missing];
}
$branches = ['covered' => 0, 'total' => 0];
foreach ($d->functionCoverage() as $functions) {
    foreach ($functions as $function) {
        foreach ($function->branches as $branch) {
            ++$branches['total'];
            if (array_intersect_key($branch->hit, $unit) !== []) { ++$branches['covered']; }
        }
    }
}
echo json_encode(['build_information' => $c['buildInformation'], 'files' => $files, 'branches' => $branches, 'coverage_test_ids' => count($seen)], JSON_THROW_ON_ERROR);
'''
    result = subprocess.run(["php", "-d", "memory_limit=1G", "-r", exporter, args.coverage],
                            cwd=ROOT, input=json.dumps(identities), text=True, capture_output=True, check=True)
    report = json.loads(result.stdout)
    source = {str(p.relative_to(ROOT)) for p in (ROOT / "src").rglob("*.php")}
    if source != set(report["files"]):
        raise ValueError(f"Coverage source denominator mismatch: {source ^ set(report['files'])}")
    report.update(source_files=len(source), source_exclusions=[], attribution="Unit test IDs only; single all-suite execution")
    report["covered"] = sum(f["covered"] for f in report["files"].values())
    report["total"] = sum(f["total"] for f in report["files"].values())
    report["categories"] = {c: {"runnable": 0, "skipped": 0, "milliseconds": 0} for c in sorted(CATEGORIES)}

    def attributes(text):
        fields = {}
        escapes = {"n": "\n", "r": "\r", "'": "'", "[": "[", "]": "]", "|": "|"}
        while text:
            match = re.match(r" (\w+)='((?:\|[nr'\[\]|]|[^'|\r\n])*)'", text)
            if match is None or match[1] in fields:
                raise ValueError("Malformed or duplicate TeamCity attribute")
            fields[match[1]] = re.sub(r"\|(.)", lambda m: escapes[m[1]], match[2])
            text = text[match.end():]
        return fields

    current = None
    seen = set()
    ignored = False
    failures = 0
    events = {"testStarted", "testFailed", "testIgnored", "testFinished"}
    for line in pathlib.Path(args.timing).read_text().splitlines():
        if not any(line.startswith("##teamcity[" + event) for event in events):
            continue
        match = re.fullmatch(r"##teamcity\[(\w+)( .*)\]", line)
        if match is None or match[1] not in events:
            raise ValueError("Malformed TeamCity test event")
        event = match[1]
        fields = attributes(match[2])
        if event == "testStarted":
            if current is not None:
                raise ValueError("Nested test start without matching finish")
            # Native locationHint includes exact file, class and expanded display name.
            parts = fields["locationHint"].split("::", 2)
            if len(parts) != 3:
                raise ValueError("Malformed timing locationHint")
            identity = expanded_identity(parts[1], parts[2])
            if identity not in identities:
                raise ValueError(f"Unknown expanded timing test: {identity}")
            # Inherited/trait methods may name their declaring file, not the
            # enumerated concrete class file. Class + expanded case is the ID.
            if not parts[0].startswith("php_qn:///") or fields["name"] != parts[2]:
                raise ValueError(f"Timing name/location identity mismatch: {identity}")
            if identity in seen:
                raise ValueError(f"Duplicate timing execution: {identity}")
            current = (identity, fields["name"], fields["flowId"])
            ignored = False
        else:
            if current is None or (fields["name"], fields["flowId"]) != current[1:]:
                raise ValueError(f"Unpaired or mismatched timing event: {event}")
            if event == "testFailed":
                failures += 1
            elif event == "testIgnored":
                if ignored:
                    raise ValueError("Duplicate ignored event")
                ignored = True
            else:
                duration = int(fields["duration"])
                if duration < 0:
                    raise ValueError("Negative test duration")
                counts = report["categories"][identities[current[0]]]
                counts["skipped" if ignored else "runnable"] += 1
                counts["milliseconds"] += duration
                seen.add(current[0])
                current = None
    if current is not None:
        raise ValueError("Incomplete timing log")
    if seen != set(identities):
        raise ValueError(f"Expanded timing/enumeration membership mismatch: {len(seen)}/{len(identities)}")
    report["identity_admission"] = {"enumerated": len(identities), "executed": len(seen),
                                    "coverage_subset_verified": True, "timing_membership_verified": True}
    report["failed_cases"] = failures
    report["branches"]["collected"] = report["branches"]["total"] > 0
    skipped = sum(c["skipped"] for c in report["categories"].values())
    qualified = report["covered"] == report["total"] and not failures and not skipped
    report["result"] = "inspection" if args.inspect else ("passed" if qualified else "failed")
    pathlib.Path(args.output).write_text(json.dumps(report, indent=2) + "\n")
    print(f"Unit-only executable lines: {report['covered']}/{report['total']}; {len(source)} source files; no exclusions")
    if report["branches"]["collected"]:
        print("Unit branch blocks (separate, no percentage mandate):", report["branches"])
    else:
        print("Branch data not collected in this line-only execution; report separate qualification and limits")
    print("Expanded cases and measured per-test milliseconds (includes setup/teardown):", report["categories"])
    for path, data in report["files"].items():
        if data["missing"]:
            print(path, "missing Unit lines:", ", ".join(map(str, data["missing"])))
    if not args.inspect and report["result"] != "passed":
        return 1
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (KeyError, ValueError, OSError, ET.ParseError, subprocess.CalledProcessError) as error:
        print(f"Unit coverage qualification failed: {error}", file=sys.stderr)
        if isinstance(error, subprocess.CalledProcessError) and error.stderr:
            print(error.stderr, file=sys.stderr)
        sys.exit(1)
