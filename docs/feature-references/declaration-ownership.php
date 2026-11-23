<?php

declare(strict_types=1);

require __DIR__ . '/../engineering/DiagramCanvas.php';

$canvas = new DiagramCanvas(1260, 640);
$canvas->text(35, 50, 'Effective Feature metadata — existing implementation', 22);
$canvas->text(35, 86, 'Source: 46343392bfc8f4a0151424d82f863d1be80e2f69', 14);

$canvas->box(35, 115, 1225, 535, 'Application / AccessControl', 0xE2F2FA);
$canvas->box(60, 300, 430, 380, "Feature/FeatureRequirements\ncumulative interpretation");
$canvas->box(810, 155, 1200, 235, "Action/SelectedAction\npure association + ancestry");
$canvas->box(810, 300, 1200, 380, "Feature/Attribute/FeatureFlag\nrepeatable class + method");
$canvas->box(810, 425, 1200, 520, "Feature/Discovery/\nFeatureReferences\nvalidated distinct names");

$canvas->arrow([[245, 300], [245, 195], [810, 195]]);
$canvas->arrow([[430, 340], [810, 340]]);
$canvas->arrow([[245, 380], [245, 475], [810, 475]]);
$canvas->text(475, 182, 'S1: reads sites / checks match', 14);
$canvas->text(475, 327, 'S2: constructs native Attributes', 14);
$canvas->text(475, 462, 'S3: builds / merges references', 14);

$canvas->text(35, 572, 'Legend: solid arrow = static reference from consumer to referenced type, not execution order.', 14);
$canvas->text(35, 605, 'Scope: metadata only; no admission or scanner.', 14);
$canvas->save(__DIR__ . '/declaration-ownership.png');
