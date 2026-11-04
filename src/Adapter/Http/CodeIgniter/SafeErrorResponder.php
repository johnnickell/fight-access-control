<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use Closure;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Http\JSend\JSendEnvelope;
use Throwable;

/**
 * Class SafeErrorResponder
 *
 * Adapts Common's policy to fresh native responses on an explicitly selected JSON path.
 */
final readonly class SafeErrorResponder
{
    /**
     * Constructs SafeErrorResponder
     *
     * The optional response factory must return a fresh, empty response on every call.
     *
     * @phpstan-param null|Closure(): ResponseInterface $responses
     */
    public function __construct(
        private App $config,
        private SafeHttpDiagnostics $diagnostics,
        private ?SafeHttpProjector $projector = null,
        private ?Closure $responses = null,
        private SafeHttpErrorPolicy $policy = new SafeHttpErrorPolicy()
    ) {
    }

    /**
     * Returns a safe response after exactly one redaction-aware diagnostic attempt
     */
    public function respond(Throwable $failure): ResponseInterface
    {
        try {
            $this->diagnostics->report($failure);
            $status = $this->policy->status($failure);
            $projection = $this->projector?->project($failure, $status);
            [$status, $envelope, $headers] = $this->policy->present($failure, $projection);

            return $this->render($envelope, $status, $headers);
        } catch (Throwable) {
            // No recursive report and no raw native exception handler fallback.
        }

        try {
            [$status, $envelope, $headers] = $this->policy->fallback();

            return $this->render($envelope, $status, $headers);
        } catch (Throwable) {
            // Even a failed fallback factory/encoder must not release the private failure.
        }

        return new Response($this->config)
            ->setStatusCode(500)
            ->setContentType('application/json')
            ->setBody('{"status":"error","message":"Internal server error."}');
    }

    /**
     * Applies Common's finite encoded envelope without native double encoding
     *
     * @phpstan-param array<string, string> $headers
     */
    private function render(JSendEnvelope $envelope, int $status, array $headers): ResponseInterface
    {
        $json = $envelope->toJson();
        $response = $this->freshResponse()->setStatusCode($status);
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response->setJSON($json);
    }

    /**
     * Creates an empty native response independent of earlier headers and partial output
     */
    private function freshResponse(): ResponseInterface
    {
        if ($this->responses !== null) {
            return ($this->responses)();
        }

        return new Response($this->config);
    }
}
