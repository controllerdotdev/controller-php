<?php

namespace Controller\Context;

class EnvironmentContext
{
    public function toArray(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'hostname' => gethostname(),
            'extensions' => get_loaded_extensions(),
            'interface' => PHP_SAPI,
        ];
    }
}
