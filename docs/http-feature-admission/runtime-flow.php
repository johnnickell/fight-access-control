<?php

declare(strict_types=1);

require __DIR__ . '/../engineering/DiagramCanvas.php';

$canvas = new DiagramCanvas(1330, 840);
$canvas->text(35, 50, 'Feature admission | existing implementation', 22);
$canvas->text(35, 86, 'Source: d320838cf055cb57de76c284c544383188c30d6e', 14);

$canvas->box(60, 120, 540, 200, "Consumer native route\nSlim / Yii selected action");
$canvas->box(35, 266, 565, 735, 'Adapter | PSR-15', 0xEEF4FA);
$canvas->box(60, 312, 540, 392, "FeatureMiddleware.process\nValidate exact selected action");
$canvas->box(60, 455, 540, 535, "RequestAuthentication.admit\nRequest-owned authority / finally scope");
$canvas->box(60, 630, 540, 710, "Downstream handler\nProtected validators / action");
$canvas->box(820, 300, 1295, 397, "External Common safe boundary\nOne owner for redacted reporting\nand presentation");
$canvas->box(800, 407, 1315, 735, 'Application | Feature', 0xEFF8EE);
$canvas->box(820, 455, 1295, 535, "FeatureAdmission.admit\nConjunctive restriction, not authorization");
$canvas->box(820, 630, 1295, 710, "FeatureAvailability.isAvailable\nFresh definitions / exact bound identity");

$blue = 0x185B9D;
$failure = 0x9B3829;
$canvas->arrow([[290, 200], [290, 312]], dotted: true, color: $blue);
$canvas->text(308, 241, '1. trusted native association', 14);
$canvas->arrow([[290, 392], [290, 455]], dotted: true, color: $blue);
$canvas->text(308, 429, '2. enter scope', 14);
$canvas->arrow([[540, 495], [820, 495]], dotted: true, color: $blue);
$canvas->text(583, 459, "3. invoke gate callback\nwith authority", 14);
$canvas->arrow([[950, 535], [950, 630]], dotted: true, color: $blue);
$canvas->text(966, 574, '4. check every required Feature', 14);
$canvas->arrow([[290, 535], [290, 630]], dotted: true, color: $blue);
$canvas->text(308, 574, "5. only after admission\nreturns; sanitized context", 14);
$canvas->arrow([[540, 352], [820, 352]], dotted: true, color: $failure);
$canvas->text(583, 313, "F. ordinary refusal 404\n/ fault 500", 14, $failure);

$canvas->text(35, 777, 'Legend: dotted arrows are ordered runtime interactions, not static dependencies.', 14);
$canvas->text(35, 810, 'Scope: selected native request only; storage, provider internals and bootstrap omitted.', 14);
$canvas->save(__DIR__ . '/runtime-flow.png');
