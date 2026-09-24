<?php

namespace App\Command;

use App\WebSocket\SyncServer;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:websocket-server', description: 'Runs the WebSocket server that pushes race changes to all connected clients in real time.')]
final class WebSocketServerCommand extends Command
{
    public function __construct(
        private readonly SyncServer $server,
        private readonly int $wsPort,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Interface to bind to', '0.0.0.0')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on (default: WS_PORT)')
            ->addOption('poll-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between checks for changes made through the HTTP API', '0.25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $port = (int) ($input->getOption('port') ?? $this->wsPort);
        $address = sprintf('%s:%d', $input->getOption('host'), $port);

        $socket = new SocketServer($address);
        $socket->on('connection', fn (ConnectionInterface $connection) => $this->server->accept($connection));

        $loop = Loop::get();
        // Changes can also arrive through the HTTP fallback API (another process), so the
        // server watches the database for new events and pushes them to subscribers.
        $loop->addPeriodicTimer(max(0.05, (float) $input->getOption('poll-interval')), fn () => $this->server->broadcastChanges());
        $loop->addPeriodicTimer(25, fn () => $this->server->pingClients());

        $this->server->setLogger(static function (string $message) use ($output): void {
            if ($output->isVerbose()) {
                $output->writeln(sprintf('[%s] %s', date('H:i:s'), $message));
            }
        });

        $io->success(sprintf('WebSocket server listening on ws://%s (run with -v to log connections)', $address));
        $loop->run();

        return Command::SUCCESS;
    }
}
