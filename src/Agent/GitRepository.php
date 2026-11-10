<?php

declare(strict_types=1);

/*
 * pdir Workmate agent for Contao Open Source CMS
 *
 * Copyright (C) 2026 pdir / digital agentur // pdir GmbH
 *
 * @package    contao-workmate-agent-bundle
 * @license    LGPL-3.0-or-later
 * @author     pdir GmbH <develop@pdir.de>
 */

namespace Pdir\ContaoWorkmateAgentBundle\Agent;

/**
 * runs git in the project directory (the user of the web server must be able to push, e.g. with a deploy key).
 *
 * Required env variables: WORKMATE_AGENT_AUTHOR_NAME and WORKMATE_AGENT_AUTHOR_EMAIL (author of the commits).
 * Optional env variables: WORKMATE_AGENT_GIT (binary), WORKMATE_AGENT_SSH_KEY (key for the push).
 */
class GitRepository
{

    /**
     * @var string
     */
    private $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function getDir(): string
    {
        return $this->projectDir;
    }

    /**
     * @return array{0: int, 1: string} exit code and output
     */
    public function run(array $arguments): array
    {
        $git = self::env('WORKMATE_AGENT_GIT') ?: 'git';
        $process = proc_open(array_merge([$git, '-C', $this->projectDir], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectDir, $this->environment());

        if (!\is_resource($process)) {
            return [127, 'git konnte nicht gestartet werden.'];
        }

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim((string) $output)];
    }

    /**
     * @throws AgentException
     */
    public function mustRun(array $arguments): string
    {
        [$code, $output] = $this->run($arguments);

        if (0 !== $code) {
            throw new AgentException(sprintf('git %s: %s', $arguments[0] ?? '', $output));
        }

        return $output;
    }

    /**
     * the author of the commits from WORKMATE_AGENT_AUTHOR_NAME and WORKMATE_AGENT_AUTHOR_EMAIL, null if not set.
     *
     * @return array{name: string, email: string}|null
     */
    public function author(): ?array
    {
        $name = self::env('WORKMATE_AGENT_AUTHOR_NAME');
        $email = self::env('WORKMATE_AGENT_AUTHOR_EMAIL');

        if ('' === $name || preg_match('/[\x00-\x1F\x7F<>]/', $name) || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return ['name' => $name, 'email' => $email];
    }

    /**
     * -c user.name/user.email for the commit, the env variables take precedence over the git config of the server.
     *
     * @throws AgentException if the author is not set
     *
     * @return list<string>
     */
    public function authorOptions(): array
    {
        if (null === ($author = $this->author())) {
            throw new AgentException('WORKMATE_AGENT_AUTHOR_NAME und WORKMATE_AGENT_AUTHOR_EMAIL (gültige E-Mail-Adresse) müssen in der .env.local gesetzt sein.');
        }

        return ['-c', 'user.name='.$author['name'], '-c', 'user.email='.$author['email']];
    }

    private function environment(): array
    {
        $home = getenv('HOME');

        if (!\is_string($home) || '' === $home) {
            $home = \function_exists('posix_getpwuid') && \function_exists('posix_geteuid') ? (string) (posix_getpwuid(posix_geteuid())['dir'] ?? $this->projectDir) : $this->projectDir;
        }

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $home,
            'LANG' => 'C',
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        if ('' !== ($key = self::env('WORKMATE_AGENT_SSH_KEY'))) {
            $env['GIT_SSH_COMMAND'] = 'ssh -i '.escapeshellarg($key).' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new';
        }

        foreach (['GIT_CONFIG_NOSYSTEM', 'GIT_CONFIG_GLOBAL'] as $name) {
            if (\is_string($value = getenv($name)) && '' !== $value) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        return \is_string($value) ? trim($value) : '';
    }
}
