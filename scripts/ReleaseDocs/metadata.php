<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\ReleaseDocs;

use JsonException;
use stdClass;

const VERSION_PATTERN = '(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)';
const REPOSITORY = 'https://github.com/johnnickell/fight-access-control';
const PACKAGE = 'johnnickell/fight-access-control';
const PACKAGE_COMMON = 'johnnickell/fight-common';

/** Reads local metadata with the same newline normalization as the previous checker */
function readMetadata(string $root, string $relative, array &$errors): string
{
    $path = $root.'/'.$relative;
    $contents = is_file($path) ? @file_get_contents($path) : false;
    if ($contents === false) {
        $reason = match (true) {
            is_dir($path) => '[Errno 21] Is a directory',
            !file_exists($path) => '[Errno 2] No such file or directory',
            !is_readable($path) => '[Errno 13] Permission denied',
            default => 'Cannot read file',
        };
        $errors[] = "$relative: cannot read release metadata ($reason: '$path').";

        return '';
    }

    if (preg_match('//u', $contents) !== 1) {
        $errors[] = "$relative: cannot read release metadata (invalid UTF-8).";

        return '';
    }

    return str_replace(["\r\n", "\r"], "\n", $contents);
}

/** Validates the README panel and its Composer metadata */
function validatePanel(string $root, string $version): array
{
    $errors = [];
    $readme = readMetadata($root, 'README.md', $errors);
    $start = '<!-- release:start -->';
    $end = '<!-- release:end -->';
    if (substr_count($readme, $start) !== 1 || substr_count($readme, $end) !== 1) {
        $errors[] = 'README.md: exactly one release:start/release:end block is required.';
    }

    preg_match('~<!-- release:start -->(.*?)<!-- release:end -->~s', $readme, $matches);
    $panel = $matches[1] ?? '';
    $release = '**Release: [v'.$version.']('.REPOSITORY.'/releases/tag/v'.$version.')**';
    if (substr_count($panel, $release) !== 1) {
        $errors[] = "README.md: release label/tag URL must identify v$version.";
    }

    preg_match_all('~^\*\*Status: (.+)\.\*\*$~m', $panel, $statuses);
    if ($statuses[1] !== ['published'] && $statuses[1] !== ['prepared (not yet published)']) {
        $message = 'README.md: status must be published or prepared (not yet published);';
        $errors[] = "$message this check verifies neither claim.";
    }

    preg_match_all('~^composer require .+$~m', $readme, $commands);
    $install = 'composer require '.PACKAGE.':"~'.$version.'"';
    if ($commands[0] !== [$install] || !str_contains($panel, $install)) {
        $errors[] = "README.md: the sole install command must be $install in the release block.";
    }

    if (substr_count($panel, "[Release overview](docs/release-$version.md)") !== 1) {
        $errors[] = "README.md: release overview must link to docs/release-$version.md.";
    }

    if (substr_count($panel, '[Tagged documentation]('.REPOSITORY."/tree/v$version/docs)") !== 1) {
        $errors[] = "README.md: tagged documentation must identify v$version.";
    }

    // Match a version bounded by non-word characters, including beside non-ASCII text.
    preg_match_all('~(?<![\p{L}\p{N}_])v('.VERSION_PATTERN.')(?![\p{L}\p{N}_])~u', $panel, $versions);
    if (array_values(array_unique($versions[1])) !== [$version]) {
        $errors[] = 'README.md: release block contains a missing or inconsistent version.';
    }

    $metadata = readMetadata($root, 'composer.json', $errors);
    try {
        $composer = json_decode($metadata, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $errors[] = 'composer.json: invalid JSON.';

        return $errors;
    }

    if (!$composer instanceof stdClass) {
        $errors[] = 'composer.json: expected a JSON object.';

        return $errors;
    }

    if (property_exists($composer, 'version')) {
        $errors[] = 'composer.json: omit version; signed Git tags own package versions.';
    }

    if (($composer->name ?? null) !== PACKAGE) {
        $errors[] = 'composer.json: package name must be '.PACKAGE.'.';
    }

    $requirements = $composer->require ?? null;
    $php = $requirements->php ?? null;
    $common = $requirements->{PACKAGE_COMMON} ?? null;
    if (
        !is_string($php) || !str_starts_with($php, '>=') || !str_contains($panel, '**PHP '.substr($php, 2).'+**')
        || !is_string($common) || $common === '' || !str_contains($panel, "**Fight Common $common**")
    ) {
        $message = 'README.md: runtime requirements must match composer.json';
        $errors[] = "$message (review the checker if constraint syntax changes).";
    }

    return $errors;
}

/** Validates the dated release entry and comparison links */
function validateChangelog(string $root, string $version): array
{
    $errors = [];
    $changelog = readMetadata($root, 'CHANGELOG.md', $errors);
    preg_match_all('~^## \[([^\]]+)\](.*)$~m', $changelog, $headings, PREG_SET_ORDER);
    $dates = [];
    foreach ($headings as [, $heading, $suffix]) {
        if ($heading === $version) {
            $dates[] = $suffix;
        }
    }

    if (
        ($headings[0][1] ?? null) !== 'Unreleased' || $headings[0][2] !== ''
        || ($headings[1][1] ?? null) !== $version || count($dates) !== 1
    ) {
        $errors[] = "CHANGELOG.md: v$version must be the first and unique release after [Unreleased].";
    }

    if (
        count($dates) !== 1 || preg_match('~\A - ([0-9]{4})-([0-9]{2})-([0-9]{2})\z~', $dates[0], $date) !== 1
        || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])
    ) {
        $errors[] = "CHANGELOG.md: [$version] needs one valid YYYY-MM-DD release date.";
    }

    $target = REPOSITORY."/compare/v$version...HEAD";
    preg_match_all('~^\[Unreleased\]: (.+)$~m', $changelog, $links);
    if ($links[1] !== [$target]) {
        $errors[] = "CHANGELOG.md: [Unreleased] link must be $target.";
    }

    $previous = $headings[2][1] ?? null;
    if ($previous !== null && preg_match('~\A'.VERSION_PATTERN.'\z~', $previous) !== 1) {
        $errors[] = 'CHANGELOG.md: previous release heading is malformed.';
    }

    $target = REPOSITORY.($previous === null ? "/releases/tag/v$version" : "/compare/v$previous...v$version");
    preg_match_all('~^\['.preg_quote($version, '~').'\]: (.+)$~m', $changelog, $links);
    if ($links[1] !== [$target]) {
        $errors[] = "CHANGELOG.md: [$version] link must be $target.";
    }

    return $errors;
}

/** Returns release metadata errors without writing to the checkout */
function validate(string $root, string $version): array
{
    if (preg_match('~\A'.VERSION_PATTERN.'\z~', $version) !== 1) {
        return ['Version must be X.Y.Z without a v prefix or leading zeroes.'];
    }

    $errors = [...validatePanel($root, $version), ...validateChangelog($root, $version)];
    $path = "docs/release-$version.md";
    $overview = readMetadata($root, $path, $errors);
    $firstLine = preg_split('~[\n\x0b\x0c\x1c-\x1e\x{85}\x{2028}\x{2029}]~u', $overview, 2)[0];
    if ($firstLine !== "# v$version release and integration overview") {
        $errors[] = "$path: overview title must identify v$version.";
    }

    return $errors;
}
