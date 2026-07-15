<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Sync;

defined('ABSPATH') || exit;

final class SyncLedger
{
    private const OPTION = 'pack_and_go_import_ledger';

    /**
     * @var array<string, mixed>
     */
    private array $data;

    public function __construct(private readonly string $profileId)
    {
        $all = get_option(self::OPTION, array());
        $slice = is_array($all) && is_array($all[$this->profileId] ?? null) ? $all[$this->profileId] : array();

        $this->data = array(
            'sections' => is_array($slice['sections'] ?? null) ? $slice['sections'] : array(),
            'items' => is_array($slice['items'] ?? null) ? $slice['items'] : array(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function hash(array $payload): string
    {
        return md5((string) wp_json_encode($payload));
    }

    public function sectionId(string $wpType): string
    {
        $id = $this->data['sections'][$wpType] ?? null;

        return is_string($id) ? $id : '';
    }

    public function setSectionId(string $wpType, string $sectionId): void
    {
        $this->data['sections'][$wpType] = $sectionId;
    }

    public function forgetType(string $wpType): void
    {
        unset($this->data['sections'][$wpType], $this->data['items'][$wpType]);
    }

    /**
     * @return array{nt_post_id: string, hash: string, synced_at: int}|null
     */
    public function entry(string $wpType, int $wpPostId): ?array
    {
        $entry = $this->data['items'][$wpType][(string) $wpPostId] ?? null;
        if (! is_array($entry)) {
            return null;
        }

        return array(
            'nt_post_id' => is_string($entry['nt_post_id'] ?? null) ? $entry['nt_post_id'] : '',
            'hash' => is_string($entry['hash'] ?? null) ? $entry['hash'] : '',
            'media_hash' => is_string($entry['media_hash'] ?? null) ? $entry['media_hash'] : '',
            'synced_at' => (int) ($entry['synced_at'] ?? 0),
        );
    }

    public function isSynced(string $wpType, int $wpPostId): bool
    {
        return $this->entry($wpType, $wpPostId) !== null;
    }

    public function hasChanged(string $wpType, int $wpPostId, string $currentHash): bool
    {
        $entry = $this->entry($wpType, $wpPostId);

        return $entry !== null && $entry['hash'] !== $currentHash;
    }

    public function record(string $wpType, int $wpPostId, string $ntPostId, string $hash, int $syncedAt, string $mediaHash = ''): void
    {
        $this->data['items'][$wpType][(string) $wpPostId] = array(
            'nt_post_id' => $ntPostId,
            'hash' => $hash,
            'media_hash' => $mediaHash,
            'synced_at' => $syncedAt,
        );
    }

    public function forget(string $wpType, int $wpPostId): void
    {
        unset($this->data['items'][$wpType][(string) $wpPostId]);
    }

    public function syncedCountForType(string $wpType): int
    {
        $items = $this->data['items'][$wpType] ?? null;

        return is_array($items) ? count($items) : 0;
    }

    public function lastSyncedAt(string $wpType): int
    {
        $items = is_array($this->data['items'][$wpType] ?? null) ? $this->data['items'][$wpType] : array();
        $latest = 0;
        foreach ($items as $entry) {
            $at = is_array($entry) ? (int) ($entry['synced_at'] ?? 0) : 0;
            if ($at > $latest) {
                $latest = $at;
            }
        }

        return $latest;
    }

    public function statusFor(string $wpType, int $wpPostId, int $modifiedAt): string
    {
        $entry = $this->entry($wpType, $wpPostId);
        if ($entry === null) {
            return 'new';
        }

        return $modifiedAt > $entry['synced_at'] ? 'changed' : 'synced';
    }

    /**
     * @param array<int, int> $modifiedByPostId Keyed by post id → the post's `post_modified` unix time.
     * @return array{new: int, changed: int, synced: int, total: int}
     */
    public function statsForType(string $wpType, array $modifiedByPostId): array
    {
        $counts = array('new' => 0, 'changed' => 0, 'synced' => 0);

        foreach ($modifiedByPostId as $postId => $modifiedAt) {
            $counts[$this->statusFor($wpType, (int) $postId, (int) $modifiedAt)]++;
        }

        return array(
            'new' => $counts['new'],
            'changed' => $counts['changed'],
            'synced' => $counts['synced'],
            'total' => count($modifiedByPostId),
        );
    }

    public function forgetAll(): void
    {
        $this->data = array('sections' => array(), 'items' => array());
    }

    public function persist(): void
    {
        $all = get_option(self::OPTION, array());
        if (! is_array($all)) {
            $all = array();
        }
        $all[$this->profileId] = $this->data;
        update_option(self::OPTION, $all, false);
    }
}
