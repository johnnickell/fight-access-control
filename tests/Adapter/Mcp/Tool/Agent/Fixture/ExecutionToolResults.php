<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture;

use DateTimeImmutable;
use Fight\Common\Application\Mcp\Detail\Data\CapturedDetail;
use Fight\Common\Application\Mcp\Detail\Data\DetailReference;
use Fight\Common\Application\Mcp\Detail\Data\MessagePage;
use Fight\Common\Application\Mcp\Observation\Data\ActivityPage;
use Fight\Common\Application\Mcp\Observation\Data\ActivityView;
use Fight\Common\Domain\Mcp\Activity\ActivityExecution;
use Fight\Common\Domain\Mcp\Activity\ActivityResult;
use Fight\Common\Domain\Mcp\Activity\ActivityTarget;
use Fight\Common\Domain\Mcp\Activity\ActivityTiming;
use Fight\Common\Domain\Mcp\Activity\PhaseActivity;
use Fight\Common\Domain\Mcp\Detail\CaptureCompleteness;
use Fight\Common\Domain\Mcp\Detail\DetailCapture;
use Fight\Common\Domain\Mcp\Detail\DetailKind;
use Fight\Common\Domain\Mcp\Observation\Observation;
use Fight\Common\Domain\Mcp\Observation\ObservationId;
use Fight\Common\Domain\Mcp\Observation\ObservationOccurrence;
use Fight\Common\Domain\Mcp\Phase\PhaseReference;
use Fight\Common\Domain\Mcp\Session\SessionReference;

final readonly class ExecutionToolResults
{
    public ActivityPage $activity;

    public CapturedDetail $detail;

    public MessagePage $messages;

    public function __construct()
    {
        $now = new DateTimeImmutable('2026-10-01T12:00:00Z');
        $session = SessionReference::fromStrings('project', 'session', 'consumer');
        $phase = PhaseReference::fromStrings('session', 'phase', null, 'agent', 'work', 1);
        $occurrence = ObservationOccurrence::fromString('2026-10-01T12:00:00Z');
        $execution = ActivityExecution::fromParts(
            ActivityTarget::fromStrings('query', 'GetExecution'),
            ActivityTiming::fromParts($occurrence, null, null),
            ActivityResult::returned()
        );
        $activity = PhaseActivity::create('common/query', 'Safe query evidence', execution: $execution);
        $activityObservation = Observation::forActivity(
            ObservationId::fromString('018f0000-0000-7000-8000-000000000001'),
            'producer',
            $session,
            $phase,
            $occurrence,
            $activity
        )->recorded($now);
        $capture = DetailCapture::create(
            $activityObservation->id(),
            DetailKind::MESSAGE,
            'text/plain',
            CaptureCompleteness::COMPLETE
        );
        $detailObservation = Observation::forDetail(
            ObservationId::fromString('018f0000-0000-7000-8000-000000000002'),
            'producer',
            $session,
            $phase,
            $occurrence,
            $capture
        )->recorded($now);
        // Consumer Query admission supplies metadata only: Tool access is never a body grant.
        $reference = new DetailReference($detailObservation, $capture, true);
        $this->detail = new CapturedDetail($reference, false, null);
        $this->messages = new MessagePage([$this->detail], 1, 1, 25);
        $this->activity = new ActivityPage([
            new ActivityView($activityObservation, $activity, [$reference])
        ], 1, 1, 25);
    }
}
