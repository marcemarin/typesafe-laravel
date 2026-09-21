<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use LogicException;
use Marcemarin\TypeSafe\Contracts\Client;
use Marcemarin\TypeSafe\Exceptions\AuthenticationException;
use Marcemarin\TypeSafe\Exceptions\ConnectionException;
use Marcemarin\TypeSafe\Exceptions\OverloadedException;
use Marcemarin\TypeSafe\Exceptions\RateLimitException;
use Marcemarin\TypeSafe\Exceptions\TypeSafeException;
use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;
use Marcemarin\TypeSafe\Exceptions\ValidationException;
use Marcemarin\TypeSafe\Http\Backoff;

/**
 * Talks to `POST /v1/systemone`. Works without the container or the facade: construct it
 * with an API key and, optionally, your own HTTP factory and cache repository.
 */
final class TypeSafeClient implements Client
{
    public const ENDPOINT = '/v1/systemone';

    private readonly HttpFactory $http;

    /**
     * @param  int  $timeout  Seconds to wait for a response.
     * @param  int  $retries  Extra attempts after the first one, used only on HTTP 429 and 529.
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'jev-latest',
        private readonly string $baseUrl = 'https://api.typesafe.ai',
        private readonly int $timeout = 20,
        private readonly int $retries = 3,
        ?HttpFactory $http = null,
        private readonly ?CacheRepository $cache = null,
        private readonly Backoff $backoff = new Backoff,
    ) {
        $this->http = $http ?? new HttpFactory;
    }

    public function state(string|array|object $state): PendingDecision
    {
        return PendingDecision::for($this, $this->model, $state);
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    public function send(DecisionRequest $request): Result
    {
        $request->validate();

        if ($request->cacheFor !== null) {
            return $this->sendCached($request, $request->cacheFor);
        }

        return Result::fromArray($this->post($request));
    }

    private function sendCached(DecisionRequest $request, \DateTimeInterface|\DateInterval|int $ttl): Result
    {
        if ($this->cache === null) {
            throw new LogicException('cacheFor() needs a cache repository; pass one to the TypeSafeClient constructor.');
        }

        $key = 'typesafe:'.$request->fingerprint();
        $hit = $this->cache->get($key);

        if (is_array($hit)) {
            return Result::fromArray($hit, cached: true);
        }

        $body = $this->post($request);
        $result = Result::fromArray($body);
        $this->cache->put($key, $body, $ttl);

        return $result;
    }

    /**
     * @return array<mixed>
     */
    private function post(DecisionRequest $request): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new AuthenticationException('No TypeSafe API key configured. Set TYPESAFE_API_KEY (or pass one to TypeSafeClient).');
        }

        $payload = $request->toPayload();

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http
                    ->withToken($this->apiKey)
                    ->withUserAgent('marcemarin-typesafe-laravel')
                    ->acceptJson()
                    ->asJson()
                    ->timeout($this->timeout)
                    ->post(rtrim($this->baseUrl, '/').self::ENDPOINT, $payload);
            } catch (HttpConnectionException $e) {
                throw new ConnectionException('Could not reach TypeSafe: '.$e->getMessage(), previous: $e);
            }

            if ($response->successful()) {
                $body = $response->json();

                return is_array($body)
                    ? $body
                    : throw new UnexpectedResponseException('TypeSafe returned a successful response that is not a JSON object.', $response->status());
            }

            $retryable = in_array($response->status(), [429, 529], true);
            if ($retryable && $attempt <= $this->retries) {
                Sleep::usleep($this->backoff->delayMs($attempt) * 1000);

                continue;
            }

            throw $this->exceptionFor($response);
        }
    }

    private function exceptionFor(Response $response): TypeSafeException
    {
        $status = $response->status();
        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : [];
        $detail = self::describe($body, $response->body());

        return match ($status) {
            401 => new AuthenticationException("TypeSafe rejected the API key (HTTP 401){$detail}", $status, $body),
            422 => new ValidationException("TypeSafe rejected the request as invalid (HTTP 422){$detail}", $status, $body),
            429 => new RateLimitException("TypeSafe rate limit exceeded (HTTP 429) after {$this->retries} retries{$detail}", $status, $body),
            529 => new OverloadedException("TypeSafe is overloaded (HTTP 529) after {$this->retries} retries{$detail}", $status, $body),
            default => new TypeSafeException("TypeSafe returned HTTP {$status}{$detail}", $status, $body),
        };
    }

    /**
     * Best-effort one-line summary of an error body. The docs do not specify its shape, so this
     * only looks for the usual suspects and otherwise falls back to the raw text.
     *
     * @param  array<mixed>  $body
     */
    private static function describe(array $body, string $raw): string
    {
        $candidates = [$body['message'] ?? null, $body['detail'] ?? null, $body['error'] ?? null];
        if (is_array($body['error'] ?? null)) {
            $candidates[] = $body['error']['message'] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return ': '.mb_strimwidth($candidate, 0, 300, '…');
            }
        }

        $raw = trim($raw);

        return $raw === '' ? '.' : ': '.mb_strimwidth($raw, 0, 300, '…');
    }
}
