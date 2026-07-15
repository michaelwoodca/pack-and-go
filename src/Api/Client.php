<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Api;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\OAuth\ConnectionStore;
use NoTrouble\PackAndGo\OAuth\PkceConnection;
use NoTrouble\PackAndGo\Support\Endpoints;
use RuntimeException;

final class Client
{
    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly ConnectionStore $store,
        private readonly PkceConnection $connection,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function myProfiles(): array
    {
        $body = $this->get('/profiles');

        /** @var array<int, array<string, mixed>> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : array();

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSections(string $account, string $profile): array
    {
        $body = $this->get(sprintf('/accounts/%s/profiles/%s/sections?perPage=100', $account, $profile));

        /** @var array<int, array<string, mixed>> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : array();

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function contentTypes(): array
    {
        $body = $this->get('/content-types');

        /** @var array<int, array<string, mixed>> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : array();

        return $data;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createSection(string $account, string $profile, array $attributes): array
    {
        return $this->post(
            sprintf('/accounts/%s/profiles/%s/sections', $account, $profile),
            array('data' => array('attributes' => $attributes)),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string>   $tagIds
     * @return array<string, mixed>
     */
    public function createPost(string $account, string $profile, string $section, array $attributes, array $tagIds = array()): array
    {
        $attributes['sectionId'] = $section;
        $data = array('attributes' => $attributes);

        if ($tagIds !== array()) {
            $data['relationships'] = array('tags' => array(
                'data' => array_map(static fn (string $id): array => array('id' => $id), $tagIds),
            ));
        }

        return $this->post(
            sprintf('/accounts/%s/profiles/%s/sections/%s/posts', $account, $profile, $section),
            array('data' => $data),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string>   $tagIds
     * @return array<string, mixed>
     */
    public function updatePost(string $account, string $profile, string $section, string $postId, array $attributes, array $tagIds = array()): array
    {
        $attributes['sectionId'] = $section;
        $data = array('id' => $postId, 'attributes' => $attributes);

        if ($tagIds !== array()) {
            $data['relationships'] = array('tags' => array(
                'data' => array_map(static fn (string $id): array => array('id' => $id), $tagIds),
            ));
        }

        return $this->request(
            'PATCH',
            sprintf('/accounts/%s/profiles/%s/sections/%s/posts/%s', $account, $profile, $section, $postId),
            array('data' => $data),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePost(string $account, string $profile, string $section, string $postId): array
    {
        return $this->request(
            'DELETE',
            sprintf('/accounts/%s/profiles/%s/sections/%s/posts/%s', $account, $profile, $section, $postId),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createTag(string $account, string $profile, string $section, array $attributes): array
    {
        return $this->post(
            sprintf('/accounts/%s/profiles/%s/sections/%s/tags', $account, $profile, $section),
            array('data' => array('attributes' => $attributes)),
        );
    }

    /**
     * @param array<string, mixed> $attributes {slot, postId?, url?, uploadToken?, alt?, clear?}
     * @return array<string, mixed>
     */
    public function attachMedia(string $account, string $profile, array $attributes): array
    {
        return $this->post(
            sprintf('/accounts/%s/profiles/%s/media/attach', $account, $profile),
            array('data' => array('attributes' => $attributes)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $this->connection->ensureFreshAccessToken();

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->store->accessToken(),
            ),
        );

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->endpoints->apiBase() . $path, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException(sprintf(
                /* translators: %s: error detail */
                __('Could not reach NoTrouble: %s', 'pack-and-go'),
                $response->get_error_message(),
            ));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : array();

        $this->guardAgainstError($status, $decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function guardAgainstError(int $httpStatus, array $decoded): void
    {
        $bodyStatus = isset($decoded['status_code']) ? (int) $decoded['status_code'] : $httpStatus;
        $ok = $httpStatus >= 200 && $httpStatus < 300 && $bodyStatus >= 200 && $bodyStatus < 300;

        if ($ok) {
            return;
        }

        $message = $this->extractError($decoded, $bodyStatus);

        throw new RuntimeException($message);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractError(array $decoded, int $status): string
    {
        $error = $decoded['error'] ?? ($decoded['message'] ?? null);

        if (is_array($error)) {
            $first = reset($error);
            $error = is_scalar($first) ? (string) $first : null;
        }

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return sprintf(
            /* translators: %d: HTTP status code */
            __('NoTrouble returned an error (status %d).', 'pack-and-go'),
            $status,
        );
    }
}
