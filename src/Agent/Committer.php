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
 * Creates a database backup, writes it to _db/latest.sql and commits it with composer.json and composer.lock, then
 * pushes the commit. Other changes of the working tree are not committed.
 */
class Committer
{
    public const VERSION = '1.0.2';

    public const DUMP = '_db/latest.sql';

    public const FILES = [self::DUMP, 'composer.json', 'composer.lock'];

    /**
     * @var GitRepository
     */
    private $git;

    /**
     * @var BackupExporter
     */
    private $exporter;

    /**
     * @var string
     */
    private $projectDir;

    public function __construct(GitRepository $git, BackupExporter $exporter, string $projectDir)
    {
        $this->git = $git;
        $this->exporter = $exporter;
        $this->projectDir = $projectDir;
    }

    public function check(): array
    {
        [$code, $output] = $this->git->run(['rev-parse', '--git-dir']);

        if (0 !== $code) {
            throw new AgentException('Kein Git-Repository oder git nicht verfügbar: '.$output);
        }

        // symbolic-ref also works for a branch without commits, null = detached HEAD
        [$code, $branch] = $this->git->run(['symbolic-ref', '--short', '-q', 'HEAD']);
        $branch = 0 === $code && '' !== $branch ? $branch : null;

        [, $remote] = $this->git->run(['remote', 'get-url', 'origin']);
        [, $status] = $this->git->run(['status', '--porcelain', '--untracked-files=no']);
        [, $ignored] = $this->git->run(['check-ignore', self::DUMP]);
        $author = $this->git->author();

        return [
            'ok' => true,
            'agent' => self::VERSION,
            'branch' => $branch,
            'remote' => $remote,
            'clean' => '' === $status,
            'dumpIgnored' => '' !== $ignored,
            'author' => null !== $author ? $author['name'].' <'.$author['email'].'>' : null,
        ];
    }

    /**
     * @return array{ok: true, commit: string, backup: string, message?: string}
     */
    public function commit(string $message): array
    {
        if (null === $this->check()['branch']) {
            throw new AgentException('Kein Branch ausgecheckt (detached HEAD), der Commit könnte nicht gepusht werden (git switch <branch>).');
        }

        // before the backup, so a missing author fails fast
        $authorOptions = $this->git->authorOptions();

        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));
        $message = '' !== $message ? substr($message, 0, 200) : 'workmate backup';

        $backup = $this->exporter->export($this->projectDir.'/'.self::DUMP);
        $files = array_values(array_filter(self::FILES, function (string $file): bool {
            return is_file($this->projectDir.'/'.$file);
        }));

        $this->git->mustRun(array_merge(['add', '--'], $files));

        // nothing changed
        [$code] = $this->git->run(array_merge(['diff', '--cached', '--quiet', '--'], $files));

        if (0 === $code) {
            return ['ok' => true, 'commit' => '', 'backup' => $backup, 'message' => 'Keine Änderungen, kein Commit.'];
        }

        $this->git->mustRun(array_merge($authorOptions, ['commit', '-m', $message, '--'], $files));
        $commit = $this->git->mustRun(['rev-parse', 'HEAD']);
        $this->git->mustRun(['push', 'origin', 'HEAD']);

        return ['ok' => true, 'commit' => $commit, 'backup' => $backup];
    }
}
