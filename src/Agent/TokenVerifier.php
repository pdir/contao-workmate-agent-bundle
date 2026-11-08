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
 * Checks the token of a request from Workmate against the SHA-256 hash in the composer.json of the project
 * ("extra": {"workmate": {"agent-token-hash": "…"}}), Workmate writes it over the Contao Manager API. The env variable
 * WORKMATE_AGENT_TOKEN_HASH takes precedence.
 */
class TokenVerifier
{
    /**
     * @var string
     */
    private $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function isValid(string $token): bool
    {
        $hash = $this->hash();

        return '' !== $token && null !== $hash && hash_equals($hash, hash('sha256', $token));
    }

    public function isConfigured(): bool
    {
        return null !== $this->hash();
    }

    private function hash(): ?string
    {
        $hash = $_SERVER['WORKMATE_AGENT_TOKEN_HASH'] ?? getenv('WORKMATE_AGENT_TOKEN_HASH');

        if (!\is_string($hash) || '' === $hash) {
            $file = $this->projectDir.'/composer.json';
            $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $hash = $composer['extra']['workmate']['agent-token-hash'] ?? null;
        }

        return \is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : null;
    }
}
