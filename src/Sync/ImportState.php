<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Sync;

defined('ABSPATH') || exit;

final class ImportState
{
    private const OPTION = 'pack_and_go_import_state';

    /**
     * @var array<string, mixed>
     */
    private array $data;

    public function __construct(private readonly string $wpType)
    {
        $all = get_option(self::OPTION, array());
        $slice = is_array($all) && is_array($all[$this->wpType] ?? null) ? $all[$this->wpType] : array();
        $this->data = $slice;
    }

    public function begin(string $sectionId, int $total): void
    {
        $selection = $this->selection();

        $this->data = array(
            'section_id' => $sectionId,
            'total' => $total,
            'cursor' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'done' => false,
            'canceled' => false,
            'selection' => $selection,
            'id_map' => array(),
            'tag_map' => array(),
            'errors' => array(),
        );
        $this->persist();
    }

    /**
     * @return array<int, int>|null
     */
    public function selection(): ?array
    {
        $selection = $this->data['selection'] ?? null;
        if (! is_array($selection)) {
            return null;
        }

        return array_values(array_map('intval', $selection));
    }

    /**
     * @param array<int, int>|null $ids
     */
    public function setSelection(?array $ids): void
    {
        $this->data['selection'] = $ids === null ? null : array_values(array_map('intval', $ids));
        $this->persist();
    }

    public function sectionId(): string
    {
        return is_string($this->data['section_id'] ?? null) ? $this->data['section_id'] : '';
    }

    public function total(): int
    {
        return (int) ($this->data['total'] ?? 0);
    }

    public function cursor(): int
    {
        return (int) ($this->data['cursor'] ?? 0);
    }

    public function created(): int
    {
        return (int) ($this->data['created'] ?? 0);
    }

    public function updated(): int
    {
        return (int) ($this->data['updated'] ?? 0);
    }

    public function skipped(): int
    {
        return (int) ($this->data['skipped'] ?? 0);
    }

    public function failed(): int
    {
        return (int) ($this->data['failed'] ?? 0);
    }

    public function processed(): int
    {
        return $this->created() + $this->updated() + $this->skipped() + $this->failed();
    }

    public function isDone(): bool
    {
        return (bool) ($this->data['done'] ?? false);
    }

    public function isCanceled(): bool
    {
        return (bool) ($this->data['canceled'] ?? false);
    }

    public function isResumable(): bool
    {
        return $this->sectionId() !== '' && ! $this->isDone() && ! $this->isCanceled();
    }

    public function markCanceled(): void
    {
        $this->data['canceled'] = true;
        $this->data['done'] = true;
    }

    /**
     * @return array<int, array{title: string, message: string}>
     */
    public function errors(): array
    {
        return is_array($this->data['errors'] ?? null) ? $this->data['errors'] : array();
    }

    public function hasImported(int $wpPostId): bool
    {
        return isset($this->data['id_map'][(string) $wpPostId]);
    }

    public function recordCreated(int $wpPostId, string $notroublePostId): void
    {
        $this->data['id_map'][(string) $wpPostId] = $notroublePostId;
        $this->data['created'] = $this->created() + 1;
    }

    public function recordUpdated(int $wpPostId, string $notroublePostId): void
    {
        $this->data['id_map'][(string) $wpPostId] = $notroublePostId;
        $this->data['updated'] = $this->updated() + 1;
    }

    public function recordSkipped(): void
    {
        $this->data['skipped'] = $this->skipped() + 1;
    }

    public function recordFailed(string $title, string $message): void
    {
        $this->data['failed'] = $this->failed() + 1;
        $errors = $this->errors();
        $errors[] = array('title' => $title, 'message' => $message);
        $this->data['errors'] = $errors;
    }

    public function noteIssue(string $title, string $message): void
    {
        $errors = $this->errors();
        $errors[] = array('title' => $title, 'message' => $message);
        $this->data['errors'] = $errors;
    }

    public function tagId(string $name): ?string
    {
        $id = $this->data['tag_map'][$name] ?? null;

        return is_string($id) ? $id : null;
    }

    public function mapTag(string $name, string $id): void
    {
        $this->data['tag_map'][$name] = $id;
    }

    public function advanceCursor(int $by): void
    {
        $this->data['cursor'] = $this->cursor() + $by;
    }

    public function markDone(): void
    {
        $this->data['done'] = true;
    }

    public function persist(): void
    {
        $all = get_option(self::OPTION, array());
        if (! is_array($all)) {
            $all = array();
        }
        $all[$this->wpType] = $this->data;
        update_option(self::OPTION, $all, false);
    }

    public function clear(): void
    {
        $this->data = array();

        $all = get_option(self::OPTION, array());
        if (is_array($all) && array_key_exists($this->wpType, $all)) {
            unset($all[$this->wpType]);
            update_option(self::OPTION, $all, false);
        }
    }

    public static function clearAll(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgress(): array
    {
        return array(
            'total' => $this->total(),
            'processed' => $this->processed(),
            'created' => $this->created(),
            'updated' => $this->updated(),
            'skipped' => $this->skipped(),
            'failed' => $this->failed(),
            'done' => $this->isDone(),
            'canceled' => $this->isCanceled(),
            'errors' => $this->errors(),
        );
    }
}
