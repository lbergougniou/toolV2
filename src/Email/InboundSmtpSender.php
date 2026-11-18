<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * Remise SMTP d'un message MIME au MTA de réception Oxeva par swaks, éventuellement à travers un
 * tunnel SSH. Le MTA appelle ensuite lui-même le webhook `/email/inbound` de l'instance associée au
 * domaine destinataire (mail-pp.scorimmo.com → PPD).
 *
 * Le tunnel contourne le port 25 sortant bloqué par les box : il doit partir d'un serveur qui joint
 * le MTA (`backup`, hors Oxeva). Les serveurs Oxeva (ppd, relais s2262) ne le joignent pas, les
 * mails y restent en file.
 *
 * Configuration (.env) : INBOUND_SMTP_HOST, INBOUND_SMTP_PORT, INBOUND_SMTP_SSH,
 * INBOUND_SMTP_TUNNEL_PORT, SWAKS_BIN.
 */
final readonly class InboundSmtpSender
{
    private const int SMTP_TIMEOUT_SECONDS = 30;
    private const int TUNNEL_WAIT_ATTEMPTS = 20;
    private const int DEFAULT_TUNNEL_PORT = 12525;

    /**
     * @param string $host Hôte du MTA, vu depuis $ssh si renseigné
     * @param string $ssh Destination SSH du tunnel (alias ~/.ssh/config ou user@hôte), vide = direct
     */
    public function __construct(
        private string $host,
        private int $port = 25,
        private string $ssh = '',
        private int $tunnelPort = self::DEFAULT_TUNNEL_PORT,
        private string $swaksBin = 'swaks',
    ) {
    }

    /**
     * @param array<string, mixed> $env
     * @return self|null null si INBOUND_SMTP_HOST est absent (transport SMTP désactivé)
     */
    public static function fromEnv(array $env): ?self
    {
        $host = trim((string) ($env['INBOUND_SMTP_HOST'] ?? ''));
        if ($host === '') {
            return null;
        }
        return new self(
            $host,
            (int) (($env['INBOUND_SMTP_PORT'] ?? '') ?: 25),
            trim((string) ($env['INBOUND_SMTP_SSH'] ?? '')),
            (int) (($env['INBOUND_SMTP_TUNNEL_PORT'] ?? '') ?: self::DEFAULT_TUNNEL_PORT),
            trim((string) ($env['SWAKS_BIN'] ?? '')) ?: 'swaks',
        );
    }

    /**
     * Libellé de la cible, ex : `158.255.65.253:25 via ssh backup`.
     */
    public function describe(): string
    {
        return sprintf('%s:%d', $this->host, $this->port) . ($this->ssh !== '' ? " via ssh {$this->ssh}" : '');
    }

    /**
     * Ouvre le tunnel si besoin et renvoie le couple [hôte, port] auquel swaks doit se connecter.
     *
     * @return array{string, int}
     */
    public function connect(): array
    {
        return $this->ssh === '' ? [$this->host, $this->port] : ['127.0.0.1', $this->ensureTunnel()];
    }

    /**
     * Remise par swaks (STARTTLS si proposé). $endpoint vient de connect(), appelé une fois par lot.
     * Le message passe par STDIN (`--data -`) : un chemin de fichier sans `@` n'est plus lu de façon
     * fiable par swaks 2024+, qui l'envoyait comme corps du mail. `--no-data-fixup` le transmet tel
     * quel (sans cela swaks remplace `%DATE%`… et les `\n` littéraux, ex : dans l'objet) ; il faut
     * alors faire soi-même le dot-stuffing et la terminaison `.`, swaks n'ajoutant que le CRLF final.
     *
     * @param array{string, int} $endpoint
     * @return array{code: int, success: bool, response: string|null, error: string|null, duration_ms: int}
     */
    public function send(array $endpoint, string $envelopeFrom, string $recipient, string $mime): array
    {
        $data = preg_replace('/^\./m', '..', rtrim($mime, "\r\n")) . "\r\n.";

        $start = hrtime(true);
        [$exitCode, $output] = self::run([
            $this->swaksBin,
            '--server', $endpoint[0],
            '--port', (string) $endpoint[1],
            '--from', $envelopeFrom,
            '--to', $recipient,
            '--data', '-',
            '--no-data-fixup',
            '--tls-optional',
            '--timeout', (string) self::SMTP_TIMEOUT_SECONDS,
            '--suppress-data',
        ], "swaks introuvable ({$this->swaksBin}) : brew install swaks ou SWAKS_BIN dans le .env", $data);

        // Dernière réponse serveur hors QUIT (221) : celle du DATA, ou le refus qui a interrompu la session.
        // Préfixes swaks : `<-` en clair, `<~` sous TLS, `<**` pour une erreur.
        preg_match_all('/^<(?:-|\*\*|~) +(\d{3})[ -](.*)$/m', $output, $matches, PREG_SET_ORDER);
        $replies = array_values(array_filter($matches, static fn(array $m): bool => $m[1] !== '221'));
        $last = $replies === [] ? null : $replies[array_key_last($replies)];

        return [
            'code' => $last !== null ? (int) $last[1] : 0,
            'success' => $exitCode === 0,
            'response' => $last !== null ? trim($last[2]) : null,
            'error' => $exitCode === 0 ? null : mb_substr(trim($output), -2000),
            'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
        ];
    }

    /**
     * Ouvre `ssh -f -N -L <tunnelPort>:<host>:<port> <ssh>` s'il n'existe pas déjà. Le tunnel reste
     * ouvert entre deux envois, comme celui de la base. Un port déjà écouté n'est réutilisé que s'il
     * s'agit bien de ce tunnel : un ancien tunnel vers une autre destination remettrait ailleurs.
     */
    private function ensureTunnel(): int
    {
        $forward = sprintf('%d:%s:%d', $this->tunnelPort, $this->host, $this->port);

        $listener = self::portListener($this->tunnelPort);
        if ($listener !== null) {
            if (!str_contains($listener, "-L $forward") || !str_ends_with($listener, " {$this->ssh}")) {
                throw new RuntimeException("Port {$this->tunnelPort} déjà utilisé par « $listener » : fermez-le ou changez INBOUND_SMTP_TUNNEL_PORT");
            }
            return $this->tunnelPort;
        }

        [$exitCode, $output] = self::run([
            'ssh', '-f', '-N', '-L', $forward,
            '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-o', 'ExitOnForwardFailure=yes',
            $this->ssh,
        ], 'ssh introuvable pour le tunnel SMTP');
        if ($exitCode !== 0) {
            throw new RuntimeException("Tunnel SMTP via {$this->ssh} impossible : " . trim($output));
        }

        for ($i = 0; $i < self::TUNNEL_WAIT_ATTEMPTS; $i++) {
            usleep(250_000);
            if (self::portListener($this->tunnelPort) !== null) {
                return $this->tunnelPort;
            }
        }
        throw new RuntimeException("Tunnel SMTP lancé mais port {$this->tunnelPort} inaccessible");
    }

    /**
     * Ligne de commande du processus qui écoute sur le port local, null si le port est libre.
     */
    private static function portListener(int $port): ?string
    {
        [, $pids] = self::run(['lsof', '-t', '-nP', "-iTCP:$port", '-sTCP:LISTEN'], 'lsof introuvable');
        $pid = strtok($pids, "\n");
        if ($pid === false || !ctype_digit($pid)) {
            return null;
        }
        [, $command] = self::run(['ps', '-o', 'command=', '-p', $pid], 'ps introuvable');
        return trim($command) ?: "PID $pid";
    }

    /**
     * Exécute une commande sans shell (arguments échappés par proc_open).
     *
     * @param list<string> $command
     * @param string|null $stdin Écrit puis fermé avant la lecture de la sortie (swaks lit tout STDIN au démarrage)
     * @return array{int, string} Code de sortie, stdout puis stderr
     */
    private static function run(array $command, string $notFoundMessage, ?string $stdin = null): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($stdin !== null) {
            $descriptors[0] = ['pipe', 'r'];
        }
        // @ : l'avertissement posix_spawn corromprait la réponse JSON, l'exception le remplace.
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException($notFoundMessage);
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
