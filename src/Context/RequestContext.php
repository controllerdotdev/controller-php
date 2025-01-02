<?php

namespace Controller\Context;

use Symfony\Component\HttpFoundation\Request;

class RequestContext
{
    public function toArray(): array
    {
        if (PHP_SAPI === 'cli') {
            return $this->getCliContext();
        }

        $request = Request::createFromGlobals();

        return [
            'url' => $request->getUri(),
            'method' => $request->getMethod(),
            'headers' => $request->headers->all(),
            'query_string' => $request->getQueryString(),
            'body' => $request->getContent(),
            'client_ip' => $request->getClientIp(),
            'server' => $request->server->all(),
        ];
    }

    private function getCliContext(): array
    {
        return [
            'argv' => $_SERVER['argv'] ?? [],
            'script' => $_SERVER['SCRIPT_NAME'] ?? null,
        ];
    }
}
