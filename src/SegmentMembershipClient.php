<?php

namespace Toggly\FeatureManagement;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Backend-key client for targeting-list membership on app.toggly.io.
 */
class SegmentMembershipClient
{
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
        return $this->send('GET', '/api/v2/segments');
    }

    public function addSegmentMembers(string $segment, array $identifiers): array
    {
        return $this->send('POST', '/api/v2/segments/' . rawurlencode($segment) . '/items', ['identifiers' => $identifiers]);
    }

    public function removeSegmentMembers(string $segment, array $identifiers): array
    {
        return $this->send('DELETE', '/api/v2/segments/' . rawurlencode($segment) . '/items', ['identifiers' => $identifiers]);
    }

    public function replaceSegmentMembers(string $segment, array $identifiers): array
    {
        return $this->send('PUT', '/api/v2/segments/' . rawurlencode($segment) . '/items', ['identifiers' => $identifiers]);
    }

    private function send(string $method, string $path, ?array $body = null): array
    {
        $request = $this->requests->createRequest($method, $this->baseUrl . $path)
            ->withHeader('Authorization', $this->appKey)
            ->withHeader('Accept', 'application/json');
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($body)));
        }
        $response = $this->http->sendRequest($request);
        $payload = (string) $response->getBody();
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Segment membership ' . $method . ' ' . $path . ' failed: ' . $response->getStatusCode());
        }
        return json_decode($payload, true) ?? [];
    }
}
