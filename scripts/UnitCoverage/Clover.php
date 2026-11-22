<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\UnitCoverage;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class Clover
{
    public static function read(string $root, string $path): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!is_readable($path) || !$document->load($path, LIBXML_NONET) || $document->doctype !== null) {
                throw new RuntimeException('Missing or invalid PHPUnit Clover report: '.$path);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $projects = $xpath->query('/coverage/project');
        if ($projects->length !== 1) {
            throw new RuntimeException('Invalid PHPUnit Clover project: '.$path);
        }

        $files = [];
        $prefix = $root.'/src/';
        foreach ($xpath->query('/coverage/project/file | /coverage/project/package/file') as $file) {
            $name = $file->getAttribute('name');
            if (!str_starts_with($name, $prefix)) {
                throw new RuntimeException('Unexpected Clover source file: '.$name);
            }

            $relative = substr($name, strlen($prefix));
            $metrics = $xpath->query('metrics', $file);
            $statements = filter_var(
                $metrics->item(0)?->getAttribute('statements'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );
            if (isset($files[$relative]) || $metrics->length !== 1 || $statements === false) {
                throw new RuntimeException('Invalid or duplicate Clover file metrics: '.$name);
            }

            $rows = [];
            foreach ($xpath->query('line', $file) as $row) {
                $line = filter_var($row->getAttribute('num'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $type = $row->getAttribute('type');
                if ($line === false || isset($rows[$line]) || !in_array($type, ['stmt', 'method'], true)) {
                    throw new RuntimeException('Invalid or duplicate Clover line: '.$name);
                }

                $rows[$line] = $type;
            }

            $files[$relative] = ['statements' => $statements, 'rows' => $rows];
        }

        return $files;
    }

    public static function verifyLines(string $path, array $lines, array $file): void
    {
        $rows = $file['rows'];
        foreach ($rows as $line => $type) {
            if ($type === 'stmt' && !isset($lines[$line])) {
                throw new RuntimeException('Missing PHPUnit executable line from Clover: src/'.$path.':'.$line);
            }
        }

        $executableLines = 0;
        foreach ($lines as $line => $hits) {
            if ($hits !== null) {
                if (!isset($rows[$line])) {
                    throw new RuntimeException('Unexpected executable line not in Clover: src/'.$path.':'.$line);
                }

                ++$executableLines;
            }
        }

        // A method row can mask a statement at the same line. Clover still counts it in the file metrics.
        if ($executableLines !== $file['statements']) {
            throw new RuntimeException('Executable line count does not match Clover: src/'.$path);
        }
    }
}
