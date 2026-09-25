<?php

namespace App\WebSocket;

use App\Access\Access;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\Socket\ConnectionInterface;

/** One browser connection: HTTP upgrade buffer, WebSocket framing and its subscription. */
final class ClientSession
{
    public string $handshakeBuffer = '';
    public ?MessageBuffer $messages = null;
    /** Access of the code this client subscribed with (null: not subscribed). */
    public ?Access $access = null;
    /** Workset the device works on, as it announced it (only for the presence counts). */
    public ?string $worksetId = null;
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
