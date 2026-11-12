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


def category(classes, identity):
    cls, method = identity.split("::", 1)
    method = re.split(r"[#@]| with data set ", method)[0]
    entry = classes[cls]
    return entry.get("methods", {}).get(method, entry)["category"]


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
    for element in ET.parse(args.tests).iter():
        if element.tag.endswith("testClass"):
            name = element.attrib["name"]
            entry = classes[name]  # Unknown runnable classes fail closed.
            if not element.attrib["file"].endswith("/" + entry["file"]):
                raise ValueError(f"Class/file identity mismatch: {name}")
            discovered[name] = element
            for method in element:
                if method.tag.endswith("testMethod"):
                    if category(classes, method.attrib["id"]) not in CATEGORIES:
                        raise ValueError("Invalid category")
    if not args.inspect and set(discovered) != set(classes):
        raise ValueError("Classification contains stale or undiscovered classes")
    for name in discovered:
        entry = classes[name]
        if entry["category"] not in CATEGORIES or not entry["reason"]:
            raise ValueError(f"Incomplete classification: {name}")
        methods = {re.split(r"[#@]", m.attrib["name"])[0] for m in discovered[name]}
        if not set(entry.get("methods", {})) <= methods:
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
$classes = json_decode(file_get_contents('etc/test-categories.json'), true, flags: JSON_THROW_ON_ERROR)['classes'];
$unit = [];
foreach ($d->testIds() as $index => $id) {
    [$class, $method] = explode('::', $id, 2);
    $method = explode('#', $method)[0];
    if (!isset($classes[$class])) { throw new RuntimeException('Unclassified coverage test: '.$id); }
    $entry = $classes[$class];
    if (($entry['methods'][$method]['category'] ?? $entry['category']) === 'Unit') { $unit[$index] = true; }
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
echo json_encode(['build_information' => $c['buildInformation'], 'files' => $files, 'branches' => $branches], JSON_THROW_ON_ERROR);
'''
    result = subprocess.run(["php", "-d", "memory_limit=1G", "-r", exporter, args.coverage],
                            cwd=ROOT, text=True, capture_output=True, check=True)
    report = json.loads(result.stdout)
    source = {str(p.relative_to(ROOT)) for p in (ROOT / "src").rglob("*.php")}
    if source != set(report["files"]):
        raise ValueError(f"Coverage source denominator mismatch: {source ^ set(report['files'])}")
    report.update(source_files=len(source), source_exclusions=[], attribution="Unit test IDs only; single all-suite execution")
    report["covered"] = sum(f["covered"] for f in report["files"].values())
    report["total"] = sum(f["total"] for f in report["files"].values())
    report["categories"] = {c: {"runnable": 0, "skipped": 0, "milliseconds": 0} for c in sorted(CATEGORIES)}

    def attributes(line):
        pairs = re.findall(r"(\w+)='((?:\|.|[^'])*)'", line)
        escapes = {"n": "\n", "r": "\r", "'": "'", "[": "[", "]": "]", "|": "|"}
        return {key: re.sub(r"\|(.)", lambda m: escapes.get(m[1], m[1]), value) for key, value in pairs}

    current = None
    ignored = False
    failures = 0
    for line in pathlib.Path(args.timing).read_text().splitlines():
        if line.startswith("##teamcity[testStarted "):
            fields = attributes(line)
            # Native locationHint includes file, fully-qualified class and expanded method.
            parts = fields["locationHint"].split("::", 2)
            current = category(classes, parts[1].lstrip("\\") + "::" + parts[2])
            ignored = False
        elif line.startswith("##teamcity[testFailed "):
            failures += 1
        elif line.startswith("##teamcity[testIgnored "):
            ignored = True
        elif line.startswith("##teamcity[testFinished "):
            if current is None:
                raise ValueError("Finished test without matching start")
            fields = attributes(line)
            counts = report["categories"][current]
            counts["skipped" if ignored else "runnable"] += 1
            counts["milliseconds"] += int(fields["duration"])
            current = None
    if current is not None:
        raise ValueError("Incomplete timing log")
    expected = sum(1 for e in discovered.values() for m in e if m.tag.endswith("testMethod"))
    actual = sum(c["runnable"] + c["skipped"] for c in report["categories"].values())
    if actual != expected:
        raise ValueError(f"Expanded timing/enumeration mismatch: {actual}/{expected}")
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
        sys.exit(1)
