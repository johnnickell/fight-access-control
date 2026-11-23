<?php

declare(strict_types=1);

require __DIR__ . '/../engineering/DiagramCanvas.php';

$canvas = new DiagramCanvas(1280, 1130);
$canvas->text(35, 50, 'CI4 Feature admission: selected ordinary JSON path', 22);
$canvas->text(35, 86, 'Existing implementation: 07719c1d40f59c52dc40c47ea0be30ff5e4997c9', 14);

$steps = [
    [120, 210, "Consumer entry reset, then native explicit Router\nCurrent matched controller or closure and route options"],
    [270, 370, "FeatureFilter.before / FeatureRequirements\nBegin scope, verify effective selected code"],
    [430, 530, "RequestAuthentication.authority\nRequest + router identity, one real User/Agent provider"],
    [590, 680, "Application FeatureAdmission.admit\nConjunction using a concrete snapshot or deliberate null"],
    [740, 835, "Application FeatureAvailability.isAvailable\nFresh stored Feature + exact Permission binding"],
    [900, 1000, "Native validator / controller / action\nOnly after both enabled restrictions pass"],
];
foreach ($steps as [$top, $bottom, $label]) {
    $canvas->box(45, $top, 800, $bottom, $label, 0xE8F0FE);
}
$canvas->box(865, 270, 1245, 1000, <<<'LABEL'
SafeErrorResponder
+ Common policy

Feature OFF / unmet
PREVIEW: generic 404

Configuration / storage /
provider faults: safe 500

Known auth refusal:
401 + valid challenge

SafeCodeIgniter catches
downstream ordinary
loop faults before native
raw logger / formatter

One diagnostic attempt,
finite JSON, fresh response
LABEL, 0xFFF2DF);

$labels = [
    '1. trusted selected target',
    '2. dispatch-owned selection',
    '3. resolve then admit (compressed)',
    '4. every effective Feature',
    '5. all pass, native filter returns null',
];
foreach ($labels as $index => $label) {
    $from = $steps[$index][1];
    $to = $steps[$index + 1][0];
    $canvas->arrow([[420, $from], [420, $to]], dotted: true);
    $canvas->text(445, $from + 32, $label, 14);
}
$canvas->arrow([[800, 320], [865, 320]], dotted: true);
$canvas->text(820, 306, 'F', 14);
$canvas->text(35, 1048, 'Legend: dotted numbered arrows are runtime interactions, not static dependencies.', 14);
$canvas->text(35, 1082, 'Scope: native ordinary before-filter path. Omits outer lifecycle, provider/storage internals, nested global loops.', 14);
$canvas->save(__DIR__ . '/runtime-flow.png');
