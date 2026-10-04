<?php

namespace Toggly\FeatureManagement;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Toggly\FeatureManagement\Exceptions\TogglyException;

/**
 * Backend-key client for targeting-list membership on app.toggly.io.
 */
class SegmentMembershipClient
{
    private const SEGMENTS_PATH = '/api/v2/segments';

    private ClientInterface $http;
    private RequestFactoryInterface $requests;
    private StreamFactoryInterface $streams;
    private string $appKey;
    private string $baseUrl;

    public function __construct(
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        string $appKey,
        string $baseUrl = 'https://app.toggly.io'
    ) {
        $this->http = $http;
        $this->requests = $requests;
        $this->streams = $streams;
        $this->appKey = $appKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function listSegments(): array
    {
        return $this->send('GET', self::SEGMENTS_PATH);
    }

    public function addSegmentMembers(string $segment, array $identifiers): array
    {
        return $this->send('POST', $this->itemsPath($segment), ['identifiers' => $identifiers]);
    }

    public function removeSegmentMembers(string $segment, array $identifiers): array
    {
        return $this->send('DELETE', $this->itemsPath($segment), ['identifiers' => $identifiers]);
    }

    public function replaceSegmentMembers(string $segment, array $identifiers): array
    {
        return $this->send('PUT', $this->itemsPath($segment), ['identifiers' => $identifiers]);
    }

    private function send(string $method, string $path, ?array $body = null): array
    {
        $request = $this->requests->createRequest($method, $this->baseUrl . $path)
            ->withHeader('Authorization', $this->appKey)
            ->withHeader('Accept', 'application/json');
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();
        if ($status >= 400) {
            throw new TogglyException('Segment membership ' . $method . ' ' . $path . ' failed: ' . $status);
        }
        // Successful DELETE/PUT may return 204 No Content or an empty body.
        if ($status === 204 || trim($payload) === '') {
            return [];
        }
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TogglyException(
                'Segment membership ' . $method . ' ' . $path . ' returned invalid JSON',
                0,
                $e
            );
        }
        if (!is_array($decoded)) {
            throw new TogglyException(
                'Segment membership ' . $method . ' ' . $path . ' returned a non-array JSON value'
            );
        }
        return $decoded;
    }

    private function itemsPath(string $segment): string
    {
        return self::SEGMENTS_PATH . '/' . rawurlencode($segment) . '/items';
    }
}
