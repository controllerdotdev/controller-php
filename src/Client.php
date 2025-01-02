<?php

namespace Controller;

use Controller\Context\EnvironmentContext;
use Controller\Context\RequestContext;
use Controller\Exceptions\ControllerException;
use GuzzleHttp\Client as HttpClient;
use Ramsey\Uuid\Uuid;

class Client
{
    private array $context = [];
    private ?string $release = null;
    private ?string $environment = null;
    private HttpClient $httpClient;
    private array $tags = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $projectId,
        private readonly ?string $apiEndpoint = null,
        ?HttpClient $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->validateConfig();
        $this->environment = $_ENV['CONTROLLER_ENVIRONMENT'] ?? 'production';
        $this->release = $_ENV['CONTROLLER_RELEASE'] ?? null;
        $this->setupDefaultTags();
    }

    private function setupDefaultTags(): void
    {
        $this->tags = [
            'browser' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'browser.name' => $this->getBrowserName(),
            'client_os' => PHP_OS,
            'device' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'device.family' => $this->getDeviceFamily(),
            'runtime' => 'php ' . PHP_VERSION,
            'runtime.name' => 'php',
            'server_name' => gethostname(),
            'environment' => $this->environment,
            'level' => 'error',
            'mechanism' => 'generic'
        ];
    }

    public function reportException(\Throwable $throwable): void
    {
        $report = $this->buildReport($throwable);
        $this->sendToApi($report);
    }

    public function context(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    public function setRelease(string $release): self
    {
        $this->release = $release;
        return $this;
    }

    public function setEnvironment(string $environment): self
    {
        $this->environment = $environment;
        $this->tags['environment'] = $environment;
        return $this;
    }

    private function getSdkInfo(): array
    {
        $composerFile = dirname(__DIR__) . '/composer.json';
        $composerData = [];

        if (file_exists($composerFile)) {
            $composerData = json_decode(file_get_contents($composerFile), true);
        }

        return [
            'name' => $composerData['name'] ?? 'controller/controller-php',
            'version' => $composerData['version'] ?? '1.0.0',
            'packages' => $this->getPackages(),
        ];
    }

    private function buildReport(\Throwable $throwable): array
    {
        $trace = $this->getFullTrace($throwable);
        $traceId = $this->generateTraceId();

        return [
            'event_id' => Uuid::uuid4()->toString(),
            'timestamp' => date('Y-m-d\TH:i:s.u\Z'),
            'platform' => 'php',
            'level' => 'error',

            'sdk' => $this->getSdkInfo(),

            'exception' => [
                'type' => get_class($throwable),
                'value' => $throwable->getMessage(),
                'mechanism' => [
                    'type' => 'generic',
                    'handled' => false,
                ],
                'stacktrace' => [
                    'frames' => $trace
                ],
            ],

            'tags' => $this->tags,
            'extra' => $this->context,
            'user' => $this->getUserContext(),
            'request' => (new RequestContext())->toArray(),
            'server_name' => gethostname(),

            'runtime' => [
                'name' => 'php',
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],

            'os' => [
                'name' => PHP_OS,
                'version' => php_uname('r'),
                'build' => php_uname('v'),
                'kernel_version' => php_uname('a'),
            ],

            'contexts' => [
                'trace' => [
                    'trace_id' => $traceId,
                    'span_id' => $this->generateSpanId(),
                    'parent_span_id' => null,
                    'op' => 'http.server',
                ],
                'browser' => $this->getBrowserContext(),
                'os' => $this->getOsContext(),
                'runtime' => $this->getRuntimeContext(),
                'device' => $this->getDeviceContext(),
            ],

            'project_id' => $this->projectId,
            'environment' => $this->environment,
            'release' => $this->release,

            'transaction' => $this->getTransaction($throwable),
        ];
    }

    private function getFullTrace(\Throwable $throwable): array
    {
        $frames = [];
        $trace = $throwable->getTrace();

        // Add the error location as the first frame
        array_unshift($trace, [
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'function' => null,
            'class' => null,
        ]);

        foreach ($trace as $frame) {
            $frames[] = [
                'filename' => $frame['file'] ?? null,
                'abs_path' => $frame['file'] ?? null,
                'function' => $frame['function'] ?? null,
                'module' => $frame['class'] ?? null,
                'lineno' => $frame['line'] ?? null,
                'pre_context' => $this->getFileLines($frame['file'] ?? null, ($frame['line'] ?? 0) - 3, 3),
                'context_line' => $this->getFileLine($frame['file'] ?? null, $frame['line'] ?? 0),
                'post_context' => $this->getFileLines($frame['file'] ?? null, ($frame['line'] ?? 0) + 1, 3),
                'in_app' => $this->isInApp($frame['file'] ?? null),
                'vars' => $this->sanitizeVars($frame['args'] ?? []),
            ];
        }

        return array_reverse($frames);
    }

    private function getFileLines(?string $file, int $lineNumber, int $count): array
    {
        if (!$file || !file_exists($file) || $lineNumber < 1) {
            return [];
        }

        try {
            $lines = file($file);
            $offset = $lineNumber - 1;
            $length = min($count, count($lines) - $offset);

            if ($offset < 0 || $length <= 0) {
                return [];
            }

            return array_map('rtrim', array_slice($lines, $offset, $length));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getFileLine(?string $file, int $line): ?string
    {
        $lines = $this->getFileLines($file, $line, 1);
        return $lines[0] ?? null;
    }

    private function getBrowserContext(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return [
            'name' => $this->getBrowserName(),
            'version' => $this->getBrowserVersion(),
            'user_agent' => $userAgent
        ];
    }

    private function getBrowserName(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'Safari') !== false) return 'Safari';
        if (strpos($userAgent, 'Edge') !== false) return 'Edge';
        if (strpos($userAgent, 'Opera') !== false) return 'Opera';

        return null;
    }

    private function getBrowserVersion(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        preg_match('/(Chrome|Firefox|Safari|Edge|Opera)\/([0-9.]+)/', $userAgent, $matches);
        return $matches[2] ?? null;
    }

    private function getOsContext(): array
    {
        return [
            'name' => PHP_OS,
            'version' => php_uname('r'),
            'build' => php_uname('v'),
        ];
    }

    private function getRuntimeContext(): array
    {
        return [
            'name' => 'php',
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ];
    }

    private function getDeviceContext(): array
    {
        return [
            'family' => $this->getDeviceFamily(),
            'model' => $this->getDeviceModel(),
            'brand' => $this->getDeviceBrand(),
        ];
    }

    private function getDeviceFamily(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (strpos($userAgent, 'Mobile') !== false) return 'Mobile';
        if (strpos($userAgent, 'Tablet') !== false) return 'Tablet';

        return 'Desktop';
    }

    private function getDeviceModel(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        preg_match('/\((.*?)\)/', $userAgent, $matches);
        return $matches[1] ?? null;
    }

    private function getDeviceBrand(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (strpos($userAgent, 'iPhone') !== false) return 'Apple';
        if (strpos($userAgent, 'iPad') !== false) return 'Apple';
        if (strpos($userAgent, 'Android') !== false) return 'Android';

        return null;
    }

    private function getUserContext(): array
    {
        // Implemente de acordo com sua lógica de autenticação
        return [
            'id' => null,
            'username' => null,
            'email' => null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
    }

    private function getPackages(): array
    {
        $packages = [];
        if (function_exists('base_path') && file_exists(base_path('composer.lock'))) {
            $composerLock = json_decode(file_get_contents(base_path('composer.lock')), true);
            foreach ($composerLock['packages'] ?? [] as $package) {
                $packages[] = [
                    'name' => $package['name'],
                    'version' => $package['version'],
                ];
            }
        }
        return $packages;
    }

    private function getTransaction(\Throwable $throwable): string
    {
        $trace = $throwable->getTrace();
        foreach ($trace as $frame) {
            if (isset($frame['class'], $frame['function'])) {
                return $frame['class'] . '::' . $frame['function'];
            }
        }
        return $throwable->getFile();
    }

    private function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function isInApp(?string $file): bool
    {
        if (!$file || !function_exists('base_path')) {
            return false;
        }
        return str_starts_with($file, base_path('app/'));
    }

    private function sanitizeVars(array $vars): array
    {
        return array_map(function ($var) {
            if (is_object($var)) {
                return get_class($var);
            }
            if (is_array($var)) {
                return '[array]';
            }
            if (is_resource($var)) {
                return '[resource]';
            }
            return $var;
        }, $vars);
    }

    private function validateConfig(): void
    {
        if (empty($this->apiKey)) {
            throw new ControllerException('API key is required');
        }

        if (empty($this->projectId)) {
            throw new ControllerException('Project ID is required');
        }
    }

    private function getApiEndpoint(): string
    {
        return rtrim($this->apiEndpoint ?? $_ENV['CONTROLLER_ENDPOINT'] ?? 'https://api.controller.dev', '/');
    }

    private function sendToApi(array $report): void
    {

        $this->httpClient->post($this->getApiEndpoint() . '/issues', [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'project-id' => $this->projectId,
            ],
            'json' => $report,
        ]);
    }
}
