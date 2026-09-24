<?php

namespace App\Race;

use Symfony\Component\HttpFoundation\Request;

/** Builds the connection settings handed to browsers. */
class ClientConfig
{
    public function __construct(
        private readonly ?string $wsPublicUrl,
        private readonly int $wsPort,
    ) {
    }

    /** Base URL of this installation as seen by the browser (frontend and API live below it). */
    public function serverUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
    }

    /** WebSocket endpoint, or null when WebSockets are disabled (clients fall back to polling). */
    public function webSocketUrl(Request $request): ?string
    {
        $configured = trim($this->wsPublicUrl ?? '');
        if ('off' === strtolower($configured)) {
            return null;
        }
        if ('' !== $configured) {
            return $configured;
        }

        return sprintf('%s://%s:%d/', $request->isSecure() ? 'wss' : 'ws', $request->getHost(), $this->wsPort);
    }
}
