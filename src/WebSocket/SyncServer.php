<?php

namespace App\WebSocket;

use App\Race\RaceNotFoundException;
use App\Race\RaceRepository;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Socket\ConnectionInterface;

/**
 * Real-time synchronisation over WebSockets.
 *
 * Protocol (JSON text messages):
 *   client → server  {"type":"hello","code":"ABC123"}     subscribe to a race
 *   server → client  {"type":"snapshot","code","seq","state"}
 *   client → server  {"type":"ops","ops":[{opId,type,...}]}
 *   server → client  {"type":"events","events":[{seq,op}]}   pushed to every subscriber
 *   server → client  {"type":"presence","code","clients"}    subscriber count of this race
 *   server → client  {"type":"ack","opId"}                   op had already been applied
 *   server → client  {"type":"rejected","opId","error"}
 *   client ↔ server  {"type":"ping"} / {"type":"pong"}
 *   server → client  {"type":"error","error"}                e.g. not_found
 */
final class SyncServer
{
    private const MAX_HANDSHAKE_BYTES = 16 * 1024;
    private const MAX_MESSAGE_BYTES = 8 * 1024 * 1024;
    private const IDLE_TIMEOUT = 75.0;

    /** @var array<int, ClientSession> */
    private array $sessions = [];
    /** @var array<string, array<int, ClientSession>> code => sessions */
    private array $subscribers = [];
    /** @var array<string, int> last sequence number pushed per race */
    private array $pushedSeq = [];
    private ServerNegotiator $negotiator;
    private \Closure $log;

    public function __construct(private readonly RaceRepository $races)
    {
        $this->negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());
        $this->log = static function (string $message): void {};
    }

    public function setLogger(callable $log): void
    {
        $this->log = \Closure::fromCallable($log);
    }

    public function accept(ConnectionInterface $connection): void
    {
        $session = new ClientSession($connection);
        $this->sessions[$session->id()] = $session;
        $connection->on('data', fn (string $data) => $this->onData($session, $data));
        $connection->on('close', fn () => $this->onClose($session));
        $connection->on('error', fn (\Throwable $e) => ($this->log)('connection error: '.$e->getMessage()));
    }

    /** Pushes events written by other processes (HTTP fallback API) to subscribers. */
    public function broadcastChanges(): void
    {
        if (!$this->subscribers) {
            return;
        }
        try {
            $current = $this->races->currentSeqs(array_keys($this->subscribers));
            foreach ($current as $code => $seq) {
                if ($seq > ($this->pushedSeq[$code] ?? 0)) {
                    $this->broadcast($code);
                }
            }
        } catch (\Throwable $e) {
            ($this->log)('broadcast failed: '.$e->getMessage());
        }
    }

    /** Keeps connections alive through proxies and drops dead ones. */
    public function pingClients(): void
    {
        $now = microtime(true);
        foreach ($this->sessions as $session) {
            if (null === $session->messages) {
                continue;
            }
            if ($now - $session->lastSeen > self::IDLE_TIMEOUT) {
                $session->connection->close();
                continue;
            }
            $session->messages->sendFrame(new Frame('', true, Frame::OP_PING));
        }
    }

    private function onData(ClientSession $session, string $data): void
    {
        $session->lastSeen = microtime(true);
        if (null !== $session->messages) {
            try {
                $session->messages->onData($data);
            } catch (\Throwable $e) {
                ($this->log)('protocol error: '.$e->getMessage());
                $session->connection->close();
            }

            return;
        }

        // Still in the HTTP upgrade phase.
        $session->handshakeBuffer .= $data;
        $end = strpos($session->handshakeBuffer, "\r\n\r\n");
        if (false === $end) {
            if (strlen($session->handshakeBuffer) > self::MAX_HANDSHAKE_BYTES) {
                $session->connection->end("HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");
            }

            return;
        }
        $head = substr($session->handshakeBuffer, 0, $end + 4);
        $rest = substr($session->handshakeBuffer, $end + 4);
        $session->handshakeBuffer = '';

        try {
            $response = $this->negotiator->handshake(Message::parseRequest($head));
        } catch (\Throwable) {
            $session->connection->end("HTTP/1.1 400 Bad Request\r\n\r\n");

            return;
        }
        $session->connection->write(Message::toString($response));
        if (101 !== $response->getStatusCode()) {
            $session->connection->end();

            return;
        }

        $connection = $session->connection;
        $session->messages = new MessageBuffer(
            new CloseFrameChecker(),
            fn (MessageInterface $message) => $this->onMessage($session, $message->getPayload()),
            fn (FrameInterface $frame) => $this->onControlFrame($session, $frame),
            true,
            null,
            self::MAX_MESSAGE_BYTES,
            self::MAX_MESSAGE_BYTES,
            static fn (string $bytes) => $connection->write($bytes),
        );
        ($this->log)(sprintf('client #%d connected from %s', $session->id(), $connection->getRemoteAddress()));
        if ('' !== $rest) {
            $this->onData($session, $rest);
        }
    }

    private function onControlFrame(ClientSession $session, FrameInterface $frame): void
    {
        switch ($frame->getOpcode()) {
            case Frame::OP_PING:
                $session->messages?->sendFrame(new Frame($frame->getPayload(), true, Frame::OP_PONG));
                break;
            case Frame::OP_CLOSE:
                $session->messages?->sendFrame(new Frame($frame->getPayload(), true, Frame::OP_CLOSE));
                $session->connection->end();
                break;
        }
    }

    private function onMessage(ClientSession $session, string $payload): void
    {
        try {
            $message = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $session->send(['type' => 'error', 'error' => 'invalid_message']);

            return;
        }
        if (!is_array($message)) {
            $session->send(['type' => 'error', 'error' => 'invalid_message']);

            return;
        }

        try {
            match ($message['type'] ?? null) {
                'hello' => $this->onHello($session, (string) ($message['code'] ?? '')),
                'ops' => $this->onOperations($session, $message['ops'] ?? null),
                'ping' => $session->send(['type' => 'pong']),
                default => $session->send(['type' => 'error', 'error' => 'invalid_message']),
            };
        } catch (\Throwable $e) {
            ($this->log)('error handling message: '.$e->getMessage());
            $session->send(['type' => 'error', 'error' => 'server_error']);
        }
    }

    private function onHello(ClientSession $session, string $code): void
    {
        $code = RaceRepository::normalizeCode($code);
        try {
            $snapshot = $this->races->snapshot($code);
        } catch (RaceNotFoundException) {
            $session->send(['type' => 'error', 'error' => 'not_found']);

            return;
        }

        $this->unsubscribe($session);
        $session->code = $code;
        $this->subscribers[$code][$session->id()] = $session;
        $this->pushedSeq[$code] ??= $snapshot['seq'];
        $session->send(['type' => 'snapshot'] + $snapshot);
        ($this->log)(sprintf('client #%d subscribed to %s', $session->id(), $code));
        $this->broadcastPresence($code);
    }

    private function onOperations(ClientSession $session, mixed $ops): void
    {
        if (null === $session->code) {
            $session->send(['type' => 'error', 'error' => 'not_subscribed']);

            return;
        }
        if (!is_array($ops) || !array_is_list($ops)) {
            $session->send(['type' => 'error', 'error' => 'invalid_ops']);

            return;
        }

        try {
            $results = $this->races->applyOperations($session->code, $ops);
        } catch (RaceNotFoundException) {
            $session->send(['type' => 'error', 'error' => 'not_found']);

            return;
        }
        foreach ($results as $result) {
            if ('rejected' === $result['status']) {
                $session->send(['type' => 'rejected', 'opId' => $result['opId'], 'error' => $result['error']]);
            } elseif ('duplicate' === $result['status']) {
                $session->send(['type' => 'ack', 'opId' => $result['opId']]);
            }
        }
        $this->broadcast($session->code);
    }

    /**
     * Tells every subscriber how many clients watch this race. Only this process's
     * WebSocket connections are counted; HTTP polling clients are invisible here.
     */
    private function broadcastPresence(string $code): void
    {
        $clients = count($this->subscribers[$code] ?? []);
        foreach ($this->subscribers[$code] ?? [] as $subscriber) {
            $subscriber->send(['type' => 'presence', 'code' => $code, 'clients' => $clients]);
        }
    }

    private function broadcast(string $code): void
    {
        $events = $this->races->eventsSince($code, $this->pushedSeq[$code] ?? 0);
        if (!$events) {
            return;
        }
        $this->pushedSeq[$code] = end($events)['seq'];
        foreach ($this->subscribers[$code] ?? [] as $subscriber) {
            $subscriber->send(['type' => 'events', 'events' => $events]);
        }
    }

    private function onClose(ClientSession $session): void
    {
        $this->unsubscribe($session);
        unset($this->sessions[$session->id()]);
        ($this->log)(sprintf('client #%d disconnected', $session->id()));
    }

    private function unsubscribe(ClientSession $session): void
    {
        $code = $session->code;
        if (null === $code) {
            return;
        }
        unset($this->subscribers[$code][$session->id()]);
        $session->code = null;
        if (empty($this->subscribers[$code])) {
            unset($this->subscribers[$code], $this->pushedSeq[$code]);

            return;
        }
        $this->broadcastPresence($code);
    }
}
