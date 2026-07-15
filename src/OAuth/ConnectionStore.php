<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\OAuth;

defined('ABSPATH') || exit;

final class ConnectionStore
{
    private const OPTION = 'pack_and_go_connection';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, array());
            $this->cache = is_array($stored) ? $stored : array();
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): void
    {
        $this->cache = array_merge($this->all(), $values);
        update_option(self::OPTION, $this->cache, false);
    }

    public function clear(): void
    {
        $this->cache = array();
        delete_option(self::OPTION);
    }

    public function clientId(): string
    {
        $value = $this->get('client_id');

        return is_string($value) ? $value : '';
    }

    public function accessToken(): string
    {
        $value = $this->get('access_token');

        return is_string($value) ? $value : '';
    }

    public function refreshToken(): string
    {
        $value = $this->get('refresh_token');

        return is_string($value) ? $value : '';
    }

    public function expiresAt(): int
    {
        return (int) $this->get('expires_at', 0);
    }

    public function isConnected(): bool
    {
        return $this->accessToken() !== '';
    }

    public function hasProfile(): bool
    {
        return is_string($this->get('profile_id')) && $this->get('profile_id') !== '';
    }

    public function needsRefresh(): bool
    {
        if ($this->accessToken() === '') {
            return true;
        }

        return $this->expiresAt() > 0 && $this->expiresAt() <= (time() + 60);
    }
}
