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

namespace Pdir\ContaoWorkmateAgentBundle\Controller;

use Pdir\ContaoWorkmateAgentBundle\Agent\AgentException;
use Pdir\ContaoWorkmateAgentBundle\Agent\Committer;
use Pdir\ContaoWorkmateAgentBundle\Agent\TokenVerifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /_workmate/agent (header X-Workmate-Token), action=check or action=commit&message=…
 */
class AgentController
{
    /**
     * @var TokenVerifier
     */
    private $tokenVerifier;

    /**
     * @var Committer
     */
    private $committer;

    public function __construct(TokenVerifier $tokenVerifier, Committer $committer)
    {
        $this->tokenVerifier = $tokenVerifier;
        $this->committer = $committer;
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->tokenVerifier->isValid((string) $request->headers->get('X-Workmate-Token', ''))) {
            return $this->respond(['ok' => false, 'error' => $this->tokenVerifier->isConfigured() ? 'Ungültiger Token.' : 'Kein Token in der composer.json eingerichtet.'], 403);
        }

        @set_time_limit(600);

        try {
            switch ((string) $request->request->get('action', 'check')) {
                case 'check':
                    return $this->respond($this->committer->check());

                case 'commit':
                    return $this->respond($this->committer->commit((string) $request->request->get('message', '')));
            }
        } catch (AgentException $e) {
            return $this->respond(['ok' => false, 'error' => $e->getMessage()], 500);
        } catch (\Throwable $e) {
            return $this->respond(['ok' => false, 'error' => \get_class($e).': '.$e->getMessage()], 500);
        }

        return $this->respond(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
