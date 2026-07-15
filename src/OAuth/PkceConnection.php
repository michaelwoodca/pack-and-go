<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\OAuth;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Support\Endpoints;
use RuntimeException;

final class PkceConnection
{
    public const SCOPES = 'profile.content.view profile.content.update';

    private const FLOW_TRANSIENT = 'pack_and_go_oauth_flow';

    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly ConnectionStore $store,
    ) {}

    public function registerClientIfNeeded(string $redirectUri): void
    {
        if ($this->store->clientId() !== '') {
            return;
        }

        $response = wp_remote_post($this->endpoints->registrationUrl(), array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json', 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'client_name' => $this->clientName(),
                'redirect_uris' => array($redirectUri),
            )),
        ));

        $body = $this->decode($response, 'registration');

        $clientId = is_string($body['client_id'] ?? null) ? $body['client_id'] : '';

        if ($clientId === '') {
            throw new RuntimeException(__('NoTrouble did not return a client id during registration.', 'pack-and-go'));
        }

        $this->store->merge(array('client_id' => $clientId, 'redirect_uri' => $redirectUri));
    }

    public function beginAuthorization(string $redirectUri): string
    {
        $verifier = $this->base64Url(random_bytes(32));
        $state = $this->base64Url(random_bytes(16));

        set_transient(self::FLOW_TRANSIENT . '_' . get_current_user_id(), array(
            'verifier' => $verifier,
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ), 15 * MINUTE_IN_SECONDS);

        $challenge = $this->base64Url(hash('sha256', $verifier, true));

        return add_query_arg(array(
            'response_type' => 'code',
            'client_id' => rawurlencode($this->store->clientId()),
            'redirect_uri' => rawurlencode($redirectUri),
            'scope' => rawurlencode(self::SCOPES),
            'state' => rawurlencode($state),
            'code_challenge' => rawurlencode($challenge),
            'code_challenge_method' => 'S256',
        ), $this->endpoints->authorizeUrl());
    }

    public function completeAuthorization(string $code, string $state): void
    {
        $flowKey = self::FLOW_TRANSIENT . '_' . get_current_user_id();
        $flow = get_transient($flowKey);
        delete_transient($flowKey);

        if (! is_array($flow) || ! hash_equals((string) ($flow['state'] ?? ''), $state)) {
            throw new RuntimeException(__('The connection response could not be verified. Please try connecting again.', 'pack-and-go'));
        }

        $response = wp_remote_post($this->endpoints->tokenUrl(), array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json'),
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => (string) ($flow['redirect_uri'] ?? ''),
                'client_id' => $this->store->clientId(),
                'code_verifier' => (string) ($flow['verifier'] ?? ''),
            ),
        ));

        $this->storeTokens($this->decode($response, 'token exchange'));
    }

    public function ensureFreshAccessToken(): void
    {
        if (! $this->store->needsRefresh()) {
            return;
        }

        if ($this->store->refreshToken() === '') {
            throw new RuntimeException(__('Your NoTrouble connection has expired. Please reconnect.', 'pack-and-go'));
        }

        $response = wp_remote_post($this->endpoints->tokenUrl(), array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json'),
            'body' => array(
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->store->refreshToken(),
                'client_id' => $this->store->clientId(),
                'scope' => self::SCOPES,
            ),
        ));

        $this->storeTokens($this->decode($response, 'token refresh'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function storeTokens(array $body): void
    {
        $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';

        if ($accessToken === '') {
            throw new RuntimeException(__('NoTrouble did not return an access token.', 'pack-and-go'));
        }

        $expiresIn = (int) ($body['expires_in'] ?? 0);

        $this->store->merge(array(
            'access_token' => $accessToken,
            'refresh_token' => is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : $this->store->refreshToken(),
            'expires_at' => $expiresIn > 0 ? time() + $expiresIn : 0,
            'scope' => is_string($body['scope'] ?? null) ? $body['scope'] : self::SCOPES,
        ));
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @return array<string, mixed>
     */
    private function decode(mixed $response, string $stage): array
    {
        if (is_wp_error($response)) {
            throw new RuntimeException(sprintf(
                /* translators: 1: flow stage, 2: error detail */
                __('Could not reach NoTrouble during %1$s: %2$s', 'pack-and-go'),
                $stage,
                $response->get_error_message(),
            ));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : array();

        if ($status < 200 || $status >= 300) {
            $detail = is_string($decoded['error_description'] ?? null)
                ? $decoded['error_description']
                : (is_string($decoded['error'] ?? null) ? $decoded['error'] : (string) $status);

            throw new RuntimeException(sprintf(
                /* translators: 1: flow stage, 2: error detail */
                __('NoTrouble rejected the %1$s request: %2$s', 'pack-and-go'),
                $stage,
                $detail,
            ));
        }

        return $decoded;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function clientName(): string
    {
        $siteName = get_bloginfo('name');
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        return sprintf('Pack & Go @ %s', is_string($host) && $host !== '' ? $host : (is_string($siteName) ? $siteName : 'WordPress'));
    }
}
