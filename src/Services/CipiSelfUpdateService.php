<?php

namespace CipiApi\Services;

/**
 * How the server updates Cipi itself (`cipi self-update policy`, Cipi 5.18.0):
 * a release installed only when its tag is signed by a trusted key, and a
 * delay before a new commit is installed. Both off unless turned on.
 *
 * Starting an update is not here: it is the server's own, at the shell or
 * from cron.
 */
class CipiSelfUpdateService
{
    public const MIN_CIPI = '5.18.0';

    public const DELAY_MAX_HOURS = 168;

    public const FINGERPRINT_RE = '#^SHA256:[A-Za-z0-9+/]{43}$#';

    public const RULES = [
        'verify' => ['sometimes', 'boolean'],
        'delay_hours' => ['sometimes', 'integer', 'min:0', 'max:' . self::DELAY_MAX_HOURS],
        'fingerprint' => ['sometimes', 'nullable', 'string', 'max:60'],
    ];

    public function __construct(
        protected CipiCliService $cli,
        protected CipiVersionService $version,
    ) {}

    /** @return array<string, mixed> verify, delay_hours, delay_default_hours, signers, signers_ready, shipped_signers, pending, held, … */
    public function show(): array
    {
        return $this->run('self-update policy --json');
    }

    /**
     * Turn the check and the delay on or off. Trusting the shipped signers the
     * first time takes the fingerprint the person compared with the one
     * published on cipi.sh.
     *
     * @param  array{verify?: bool, delay_hours?: int, fingerprint?: string|null}  $in
     * @return array<string, mixed> the policy after it
     */
    public function update(array $in): array
    {
        $command = 'self-update policy';
        if (array_key_exists('verify', $in)) {
            $command .= ' --verify=' . ($in['verify'] ? 'on' : 'off');
        }
        if (array_key_exists('delay_hours', $in)) {
            $h = (int) $in['delay_hours'];
            if ($h < 0 || $h > self::DELAY_MAX_HOURS) {
                throw new SelfUpdatePolicyRefusedException('delay_hours: 0 (none) to ' . self::DELAY_MAX_HOURS . ' (a week)');
            }
            $command .= ' --delay=' . ($h === 0 ? 'off' : "{$h}h");
        }
        $fp = $in['fingerprint'] ?? null;
        if (is_string($fp) && $fp !== '') {
            if (! preg_match(self::FINGERPRINT_RE, $fp)) {
                throw new SelfUpdatePolicyRefusedException('fingerprint: SHA256:… as ssh-keygen -l prints it');
            }
            $command .= ' ' . escapeshellarg("--fingerprint={$fp}");
        }

        return $this->run($command . ' --json');
    }

    /** @return array<string, mixed> */
    protected function run(string $command): array
    {
        $this->version->require(self::MIN_CIPI, 'The update policy');
        $result = $this->cli->run($command);
        $output = trim((string) preg_replace('/\x1b\[[0-9;]*m/', '', (string) ($result['output'] ?? '')));
        if ($result['exit_code'] !== 0) {
            $message = preg_match_all('/^\s*(?:\[ERROR\]|✗)\s*(.+)$/mu', $output, $m) && $m[1] !== [] ? implode(' ', array_map('trim', $m[1])) : ($output !== '' ? $output : 'cipi self-update policy failed');
            if (preg_match('/fingerprint|No signer|nothing changed|is on or off|a week at most/i', $message)) {
                throw new SelfUpdatePolicyRefusedException($message);
            }
            throw new \RuntimeException($message);
        }
        $decoded = CipiAppJitService::decode($output);
        if (! is_array($decoded) || ! array_key_exists('verify', $decoded)) {
            throw new \RuntimeException('Cipi CLI returned no policy (Cipi CLI ≥ ' . self::MIN_CIPI . ' required)');
        }

        return $decoded;
    }
}
