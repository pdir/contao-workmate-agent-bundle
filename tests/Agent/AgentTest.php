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

namespace Pdir\ContaoWorkmateAgentBundle\Tests\Agent;

use Pdir\ContaoWorkmateAgentBundle\Agent\AgentException;
use Pdir\ContaoWorkmateAgentBundle\Agent\BackupExporter;
use Pdir\ContaoWorkmateAgentBundle\Agent\Committer;
use Pdir\ContaoWorkmateAgentBundle\Agent\GitRepository;
use Pdir\ContaoWorkmateAgentBundle\Agent\TokenVerifier;
use Pdir\ContaoWorkmateAgentBundle\Controller\AgentController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * runs the agent against a temporary project (Git checkout with a bare remote), the backup is simulated.
 */
class AgentTest extends TestCase
{
    private const TOKEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @var string
     */
    private $dir;

    /**
     * @var string
     */
    private $project;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/workmate-agent-'.bin2hex(random_bytes(4));
        $this->project = $this->dir.'/project';
        mkdir($this->project, 0777, true);

        putenv('HOME='.$this->dir);
        putenv('GIT_CONFIG_NOSYSTEM=1');
        putenv('WORKMATE_AGENT_AUTHOR_NAME=Agentur Test');
        putenv('WORKMATE_AGENT_AUTHOR_EMAIL=agent@example.org');

        file_put_contents($this->project.'/composer.json', json_encode(['extra' => ['workmate' => ['agent-token-hash' => hash('sha256', self::TOKEN)]]]));
        file_put_contents($this->project.'/.gitignore', "/vendor/\n/var/\n");

        $this->git($this->dir, ['init', '--bare', '-q', 'remote.git']);
        $this->git($this->project, ['init', '-q', '-b', 'main']);
        $this->git($this->project, ['remote', 'add', 'origin', $this->dir.'/remote.git']);
        $this->git($this->project, ['add', 'composer.json', '.gitignore']);
        $this->git($this->project, ['-c', 'user.name=Test', '-c', 'user.email=test@example.org', 'commit', '-q', '-m', 'init']);
        $this->git($this->project, ['push', '-q', 'origin', 'main']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        putenv('WORKMATE_AGENT_TOKEN_HASH');
        putenv('WORKMATE_AGENT_AUTHOR_NAME');
        putenv('WORKMATE_AGENT_AUTHOR_EMAIL');
    }

    public function testTokenFromComposerJson(): void
    {
        $verifier = new TokenVerifier($this->project);

        $this->assertTrue($verifier->isConfigured());
        $this->assertTrue($verifier->isValid(self::TOKEN));
        $this->assertFalse($verifier->isValid('wrong'));
        $this->assertFalse($verifier->isValid(''));
    }

    public function testTokenFromEnvTakesPrecedence(): void
    {
        putenv('WORKMATE_AGENT_TOKEN_HASH='.hash('sha256', 'other'));
        $verifier = new TokenVerifier($this->project);

        $this->assertTrue($verifier->isValid('other'));
        $this->assertFalse($verifier->isValid(self::TOKEN));
    }

    public function testNoTokenConfigured(): void
    {
        file_put_contents($this->project.'/composer.json', '{}');

        $this->assertFalse((new TokenVerifier($this->project))->isConfigured());
        $this->assertFalse((new TokenVerifier($this->project))->isValid(self::TOKEN));
    }

    public function testControllerRejectsWrongToken(): void
    {
        $response = $this->controller()($this->request('wrong', ['action' => 'commit']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFileDoesNotExist($this->project.'/_db/latest.sql');
    }

    public function testCheck(): void
    {
        $response = $this->controller()($this->request(self::TOKEN, ['action' => 'check']));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(Committer::VERSION, $data['agent']);
        $this->assertSame('main', $data['branch']);
        $this->assertTrue($data['clean']);
        $this->assertFalse($data['dumpIgnored']);
        $this->assertSame('Agentur Test <agent@example.org>', $data['author']);
    }

    public function testCommitsDumpComposerJsonAndLock(): void
    {
        file_put_contents($this->project.'/composer.lock', '{"packages": []}');
        file_put_contents($this->project.'/other.txt', 'not committed');

        $response = $this->controller("INSERT INTO tl_page VALUES (1);\n")($this->request(self::TOKEN, ['action' => 'commit', 'message' => "contao update 5.3.44\nx"]));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(40, \strlen($data['commit']));
        $this->assertSame("INSERT INTO tl_page VALUES (1);\n", file_get_contents($this->project.'/_db/latest.sql'));
        $this->assertSame('contao update 5.3.44 x', $this->git($this->dir.'/remote.git', ['log', '-1', '--format=%s', 'main']));
        $this->assertSame('Agentur Test <agent@example.org>', $this->git($this->dir.'/remote.git', ['log', '-1', '--format=%an <%ae>', 'main']));
        $this->assertSame("_db/latest.sql\ncomposer.lock", $this->git($this->dir.'/remote.git', ['show', '--name-only', '--format=', 'main']));
        $this->assertStringContainsString('?? other.txt', $this->git($this->project, ['status', '--porcelain']));

        // the same dump again: no commit
        $data = json_decode((string) $this->controller("INSERT INTO tl_page VALUES (1);\n")($this->request(self::TOKEN, ['action' => 'commit', 'message' => 'again']))->getContent(), true);

        $this->assertSame('', $data['commit']);
    }

    public function testCommitOnBranchWithoutCommits(): void
    {
        (new Filesystem())->remove([$this->project.'/.git', $this->dir.'/remote.git']);
        $this->git($this->dir, ['init', '--bare', '-q', 'remote.git']);
        $this->git($this->project, ['init', '-q', '-b', 'main']);
        $this->git($this->project, ['remote', 'add', 'origin', $this->dir.'/remote.git']);

        $check = json_decode((string) $this->controller()($this->request(self::TOKEN, ['action' => 'check']))->getContent(), true);
        $response = $this->controller()($this->request(self::TOKEN, ['action' => 'commit', 'message' => 'first']));

        $this->assertSame('main', $check['branch']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('first', $this->git($this->dir.'/remote.git', ['log', '-1', '--format=%s', 'main']));
    }

    public function testDetachedHeadIsRejectedBeforeTheBackup(): void
    {
        $this->git($this->project, ['checkout', '-q', '--detach', 'HEAD']);

        $check = json_decode((string) $this->controller()($this->request(self::TOKEN, ['action' => 'check']))->getContent(), true);
        $response = $this->controller()($this->request(self::TOKEN, ['action' => 'commit']));

        $this->assertNull($check['branch']);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('detached HEAD', (string) $response->getContent());
        $this->assertFileDoesNotExist($this->project.'/_db/latest.sql');
    }

    public function testEnvAuthorTakesPrecedenceOverGitConfig(): void
    {
        $this->git($this->project, ['config', 'user.name', 'Server']);
        $this->git($this->project, ['config', 'user.email', 'server@example.org']);

        $response = $this->controller()($this->request(self::TOKEN, ['action' => 'commit']));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('Agentur Test <agent@example.org>', $this->git($this->dir.'/remote.git', ['log', '-1', '--format=%an <%ae>', 'main']));
    }

    /**
     * @dataProvider invalidAuthorProvider
     */
    public function testCommitRequiresAuthor(string $name, string $email): void
    {
        putenv('WORKMATE_AGENT_AUTHOR_NAME='.$name);
        putenv('WORKMATE_AGENT_AUTHOR_EMAIL='.$email);

        $check = json_decode((string) $this->controller()($this->request(self::TOKEN, ['action' => 'check']))->getContent(), true);
        $response = $this->controller()($this->request(self::TOKEN, ['action' => 'commit']));

        $this->assertNull($check['author']);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('WORKMATE_AGENT_AUTHOR_NAME', (string) $response->getContent());
        $this->assertFileDoesNotExist($this->project.'/_db/latest.sql');
    }

    public function invalidAuthorProvider(): iterable
    {
        yield 'nothing set' => ['', ''];
        yield 'no name' => ['', 'agent@example.org'];
        yield 'no email' => ['Agentur Test', ''];
        yield 'invalid email' => ['Agentur Test', 'agentur'];
        yield 'name with angle brackets' => ['Agentur <x>', 'agent@example.org'];
    }

    public function testFailingBackupIsReported(): void
    {
        $exporter = new class() extends BackupExporter {
            public function export(string $target): string
            {
                throw new AgentException('Der Contao-Backup-Manager ist nicht verfügbar (ab Contao 4.13).');
            }
        };

        $response = (new AgentController(new TokenVerifier($this->project), new Committer(new GitRepository($this->project), $exporter, $this->project)))($this->request(self::TOKEN, ['action' => 'commit']));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Backup-Manager', (string) $response->getContent());
    }

    public function testWriteUnzipsGzBackups(): void
    {
        $in = fopen('php://memory', 'w+b');
        fwrite($in, gzencode("CREATE TABLE tl_page (id int);\n"));
        rewind($in);

        BackupExporter::write($in, $this->dir.'/out/latest.sql', true);

        $this->assertSame("CREATE TABLE tl_page (id int);\n", file_get_contents($this->dir.'/out/latest.sql'));
    }

    private function controller(string $dump = "-- dump\n"): AgentController
    {
        $exporter = new class($dump) extends BackupExporter {
            private $dump;

            public function __construct(string $dump)
            {
                parent::__construct(null);
                $this->dump = $dump;
            }

            public function export(string $target): string
            {
                $in = fopen('php://memory', 'w+b');
                fwrite($in, $this->dump);
                rewind($in);
                self::write($in, $target);

                return 'backup__20261108120000.sql';
            }
        };

        return new AgentController(new TokenVerifier($this->project), new Committer(new GitRepository($this->project), $exporter, $this->project));
    }

    private function request(string $token, array $post): Request
    {
        $request = Request::create('/_workmate/agent', 'POST', $post);
        $request->headers->set('X-Workmate-Token', $token);

        return $request;
    }

    private function git(string $cwd, array $arguments): string
    {
        $process = proc_open(array_merge(['git'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, ['PATH' => getenv('PATH'), 'HOME' => $this->dir, 'GIT_CONFIG_NOSYSTEM' => '1']);
        $output = trim(stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
        proc_close($process);

        return $output;
    }
}
