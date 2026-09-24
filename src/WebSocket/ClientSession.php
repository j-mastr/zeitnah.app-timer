<?php

namespace App\WebSocket;

use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\Socket\ConnectionInterface;

/** One browser connection: HTTP upgrade buffer, WebSocket framing and its subscription. */
final class ClientSession
{
    public string $handshakeBuffer = '';
    public ?MessageBuffer $messages = null;
    /** Race code this client is subscribed to. */
    public ?string $code = null;
    public float $lastSeen;

    public function __construct(public readonly ConnectionInterface $connection)
    {
        $this->lastSeen = microtime(true);
    }

    public function id(): int
    {
        return spl_object_id($this);
    }

    public function send(array $message): void
    {
        $this->messages?->sendMessage(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
