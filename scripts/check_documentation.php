<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$excluded = ['.git', '.runs', 'vendor'];
$failures = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), $excluded, true)
    )
);

foreach ($iterator as $file) {
    if (
        !$file->isFile()
        || strtolower($file->getExtension()) !== 'md'
        || str_starts_with($file->getFilename(), '_')
    ) {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    preg_match_all('/(?<!!)\[[^]]*]\((?<target>[^)]+)\)/', $contents, $matches);
    foreach ($matches['target'] as $target) {
        $target = trim($target, " <>\t\n\r\0\x0B");
        if ($target === '' || str_starts_with($target, '#') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)) {
            continue;
        }

        $path = rawurldecode(explode('#', $target, 2)[0]);
        if (!file_exists($file->getPath().'/'.$path)) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $failures[] = sprintf('%s links to missing local target %s.', $relative, $target);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "Documentation links are valid.\n");
