<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname(__DIR__).'/vendor/autoload.php';

// Parse, never include, production inputs. A parser error aborts the entire inventory.
$paths = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$parser = new ParserFactory()->createForHostVersion();
$traverser = new NodeTraverser(new NameResolver());
$files = [];
foreach ($paths as $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unreadable inventory input');
    }

    $nodes = $parser->parse($source);
    if ($nodes === null) {
        throw new RuntimeException('Incomplete inventory parse');
    }

    $files[$path] = $traverser->traverse($nodes);
}

echo json_encode($files, JSON_THROW_ON_ERROR);
