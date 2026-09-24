<?php

namespace App\Controller;

use App\Regatta\ClientConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the single-file frontend with this server's URL pre-filled, so the page
 * connects back to the server it was loaded from by default.
 */
class AppController
{
    private const CONFIG_PLACEHOLDER = '/*SERVER_CONFIG*/null';

    public function __construct(
        private readonly string $projectDir,
        private readonly ClientConfig $config,
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $html = file_get_contents($this->projectDir.'/frontend/index.html');
        $config = json_encode(
            ['serverUrl' => $this->config->serverUrl($request)],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
        );

        return new Response(str_replace(self::CONFIG_PLACEHOLDER, $config, $html), Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
