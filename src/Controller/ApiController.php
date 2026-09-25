<?php

namespace App\Controller;

use App\Race\AccessRevokedException;
use App\Race\ClientConfig;
use App\Race\RaceNotFoundException;
use App\Race\RaceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP API. Also serves as the fallback transport when WebSockets are unavailable:
 * clients POST operations and poll for events. `{code}` is any access code of the race;
 * its rules decide what the client may do and see.
 */
#[Route('/api')]
class ApiController
{
    /** When a client is further behind than this, it gets a fresh snapshot instead of events. */
    private const MAX_EVENT_GAP = 500;

    public function __construct(
        private readonly RaceRepository $races,
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

    #[Route('/races', methods: ['POST'])]
    public function create(): JsonResponse
    {
        return $this->json($this->races->create(), Response::HTTP_CREATED);
    }

    #[Route('/races/{code}', methods: ['GET'])]
    public function snapshot(string $code): JsonResponse
    {
        return $this->withAccess($code, fn ($access) => $this->races->snapshot($access));
    }

    #[Route('/races/{code}/events', methods: ['GET'])]
    public function events(string $code, Request $request): JsonResponse
    {
        $since = max(0, $request->query->getInt('since'));

        return $this->withAccess($code, fn ($access) => $this->races->poll($access, $since, self::MAX_EVENT_GAP));
    }

    #[Route('/races/{code}/ops', methods: ['POST'])]
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

        return $this->withAccess($code, fn ($access) => ['results' => $this->races->applyOperations($access, $ops)]);
    }

    /** Runs $action with the access of $code; unknown codes are 404, revoked ones 403. */
    private function withAccess(string $code, callable $action): JsonResponse
    {
        try {
            return $this->json($action($this->races->resolve($code)));
        } catch (RaceNotFoundException) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        } catch (AccessRevokedException) {
            return $this->json(['error' => 'access_revoked'], Response::HTTP_FORBIDDEN);
        }
    }

    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
