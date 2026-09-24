<?php

namespace App\Controller;

use App\Regatta\ClientConfig;
use App\Regatta\RegattaNotFoundException;
use App\Regatta\RegattaRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP API. Also serves as the fallback transport when WebSockets are unavailable:
 * clients POST operations and poll for events.
 */
#[Route('/api')]
class ApiController
{
    /** When a client is further behind than this, it gets a fresh snapshot instead of events. */
    private const MAX_EVENT_GAP = 500;

    public function __construct(
        private readonly RegattaRepository $regattas,
        private readonly ClientConfig $config,
    ) {
    }

    #[Route('/config', methods: ['GET'])]
    public function config(Request $request): JsonResponse
    {
        return $this->json([
            'serverUrl' => $this->config->serverUrl($request),
            'wsUrl' => $this->config->webSocketUrl($request),
            'serverTime' => (int) floor(microtime(true) * 1000),
        ]);
    }

    #[Route('/regattas', methods: ['POST'])]
    public function create(): JsonResponse
    {
        return $this->json($this->regattas->create(), Response::HTTP_CREATED);
    }

    #[Route('/regattas/{code}', methods: ['GET'])]
    public function snapshot(string $code): JsonResponse
    {
        try {
            return $this->json($this->regattas->snapshot($code));
        } catch (RegattaNotFoundException) {
            return $this->notFound();
        }
    }

    #[Route('/regattas/{code}/events', methods: ['GET'])]
    public function events(string $code, Request $request): JsonResponse
    {
        $since = max(0, $request->query->getInt('since'));
        try {
            $snapshot = $this->regattas->snapshot($code);
            if ($snapshot['seq'] - $since > self::MAX_EVENT_GAP || $since > $snapshot['seq']) {
                return $this->json(['reset' => true] + $snapshot);
            }

            return $this->json([
                'seq' => $snapshot['seq'],
                'events' => $this->regattas->eventsSince($code, $since, self::MAX_EVENT_GAP),
            ]);
        } catch (RegattaNotFoundException) {
            return $this->notFound();
        }
    }

    #[Route('/regattas/{code}/ops', methods: ['POST'])]
    public function operations(string $code, Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }
        $ops = is_array($body) ? ($body['ops'] ?? null) : null;
        if (!is_array($ops) || !array_is_list($ops)) {
            return $this->json(['error' => 'invalid_ops'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json(['results' => $this->regattas->applyOperations($code, $ops)]);
        } catch (RegattaNotFoundException) {
            return $this->notFound();
        }
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
