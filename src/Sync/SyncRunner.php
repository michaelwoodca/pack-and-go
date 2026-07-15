<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Sync;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Plugin;
use RuntimeException;
use Throwable;

final class SyncRunner
{
    public function __construct(private readonly Plugin $plugin) {}

    /**
     * @return array<string, mixed> progress snapshot
     *
     * @throws RuntimeException with a user-facing message on a fatal setup problem.
     */
    public function start(string $wpType): array
    {
        [$account, $profile] = $this->credentials();

        $mapping = $this->plugin->mappingStore()->forType($wpType);
        if (! is_array($mapping) || ($mapping['enabled'] ?? false) !== true) {
            throw new RuntimeException(__('This content type is not set to import. Choose a NoTrouble type for it first.', 'pack-and-go'));
        }

        $sectionType = is_string($mapping['sectionType'] ?? null) ? $mapping['sectionType'] : '';
        if ($sectionType === '') {
            throw new RuntimeException(__('Pick which NoTrouble section this content should become before importing.', 'pack-and-go'));
        }

        $title = is_string($mapping['sectionTitle'] ?? null) && $mapping['sectionTitle'] !== ''
            ? $mapping['sectionTitle']
            : $wpType;

        $ledger = $this->plugin->ledger();

        $chosenTarget = is_string($mapping['targetSectionId'] ?? null) ? $mapping['targetSectionId'] : '';

        if ($chosenTarget !== '') {
            $sectionId = $chosenTarget;
            if ($ledger->sectionId($wpType) !== $sectionId) {
                $ledger->setSectionId($wpType, $sectionId);
                $ledger->persist();
            }
        } else {
            $sectionId = $ledger->sectionId($wpType);

            if ($sectionId === '') {
                try {
                    $section = $this->plugin->client()->createSection($account, $profile, array(
                        'name' => $title,
                        'type' => $sectionType,
                        'format' => $this->defaultFormat($sectionType),
                    ));
                } catch (Throwable $e) {
                    throw new RuntimeException((new FriendlyMessage())->for($e));
                }

                $sectionId = is_string($section['data']['id'] ?? null) ? $section['data']['id'] : '';
                if ($sectionId === '') {
                    throw new RuntimeException(__('NoTrouble did not return the new section. Please try again.', 'pack-and-go'));
                }

                $ledger->setSectionId($wpType, $sectionId);
                $ledger->persist();
            }
        }

        $state = new ImportState($wpType);
        $state->begin($sectionId, $this->countSelected($wpType, $state));

        return $state->toProgress();
    }

    /**
     * @return array<string, mixed> progress snapshot
     */
    public function runBatch(string $wpType, int $batchSize): array
    {
        $state = new ImportState($wpType);

        if ($state->isDone() || $state->isCanceled() || $state->sectionId() === '') {
            $state->markDone();
            $state->persist();

            return $state->toProgress();
        }

        [$account, $profile] = $this->credentials();
        $sectionId = $state->sectionId();
        $mapping = $this->plugin->mappingStore()->forType($wpType) ?? array();
        $ledger = $this->plugin->ledger();
        $selection = $state->selection();

        $postIds = $this->batchPostIds($wpType, $selection, $state->cursor(), $batchSize);

        if ($postIds === array()) {
            $state->markDone();
            $state->persist();

            return $state->toProgress();
        }

        $builder = new PostBuilder($this->plugin->contentCleaner(), new \NoTrouble\PackAndGo\Content\MediaResolver());
        $friendly = new FriendlyMessage();
        $now = time();

        foreach ($postIds as $postId) {
            $postId = (int) $postId;
            $title = get_the_title($postId) ?: sprintf(/* translators: %d: post id */ __('Item #%d', 'pack-and-go'), $postId);

            try {
                if ($state->hasImported($postId)) {
                    $state->recordSkipped();

                    continue;
                }

                $built = $builder->build($postId, $mapping);
                $hash = SyncLedger::hash($built);
                $entry = $ledger->entry($wpType, $postId);

                if ($entry !== null && $entry['hash'] === $hash) {
                    $state->recordSkipped();

                    continue;
                }

                $tagIds = $this->resolveTags($account, $profile, $sectionId, $built['tags'], $state, $friendly, $title);

                if ($entry !== null && $entry['nt_post_id'] !== '') {
                    $this->plugin->client()->updatePost($account, $profile, $sectionId, $entry['nt_post_id'], $built['attributes'], $tagIds);
                    $ntId = $entry['nt_post_id'];
                    $state->recordUpdated($postId, $ntId);
                } else {
                    $created = $this->plugin->client()->createPost($account, $profile, $sectionId, $built['attributes'], $tagIds);
                    $ntId = is_string($created['data']['id'] ?? null) ? $created['data']['id'] : '';

                    if ($ntId === '') {
                        throw new RuntimeException(__('NoTrouble did not confirm the new post.', 'pack-and-go'));
                    }

                    $state->recordCreated($postId, $ntId);
                }

                $ledger->record($wpType, $postId, $ntId, $hash, $now);
                $this->attachMedia($account, $profile, $ntId, $built['media'], $state, $friendly, $title);
            } catch (Throwable $e) {
                $state->recordFailed($title, $friendly->for($e));
            }
        }

        $state->advanceCursor(count($postIds));

        if ($this->isLastBatch($selection, $postIds, $state, $batchSize)) {
            $state->markDone();
        }

        $ledger->persist();
        $state->persist();

        return $state->toProgress();
    }

    /**
     * @param array<int, int>|null $selection
     * @return array<int, int>
     */
    private function batchPostIds(string $wpType, ?array $selection, int $cursor, int $batchSize): array
    {
        if ($selection !== null) {
            return array_slice($selection, $cursor, $batchSize);
        }

        $postIds = get_posts(array(
            'post_type' => $wpType,
            'post_status' => 'publish',
            'numberposts' => $batchSize,
            'offset' => $cursor,
            'orderby' => 'date',
            'order' => 'ASC',
            'fields' => 'ids',
            'suppress_filters' => true,
        ));

        return is_array($postIds) ? array_map('intval', $postIds) : array();
    }

    /**
     * @param array<int, int>|null $selection
     * @param array<int, int>      $postIds
     */
    private function isLastBatch(?array $selection, array $postIds, ImportState $state, int $batchSize): bool
    {
        if ($selection !== null) {
            return $state->cursor() >= count($selection);
        }

        return count($postIds) < $batchSize || $state->cursor() >= $state->total();
    }

    /**
     * @param array<int, array{slot: string, url: string, alt: string}> $media
     */
    private function attachMedia(string $account, string $profile, string $postId, array $media, ImportState $state, FriendlyMessage $friendly, string $title): void
    {
        foreach ($media as $item) {
            try {
                $this->plugin->client()->attachMedia($account, $profile, array(
                    'slot' => $item['slot'],
                    'postId' => $postId,
                    'url' => $item['url'],
                    'alt' => $item['alt'],
                ));
            } catch (Throwable $e) {
                $state->noteIssue(
                    $title,
                    sprintf(/* translators: %s: reason */ __('An image could not be imported: %s', 'pack-and-go'), $friendly->for($e)),
                );
            }
        }
    }

    /**
     * @param array<int, string> $names
     * @return array<int, string>
     */
    private function resolveTags(string $account, string $profile, string $sectionId, array $names, ImportState $state, FriendlyMessage $friendly, string $title): array
    {
        $ids = array();

        foreach ($names as $name) {
            $existing = $state->tagId($name);
            if ($existing !== null) {
                $ids[] = $existing;

                continue;
            }

            try {
                $tag = $this->plugin->client()->createTag($account, $profile, $sectionId, array('name' => $name));
                $id = is_string($tag['data']['id'] ?? null) ? $tag['data']['id'] : '';
                if ($id !== '') {
                    $state->mapTag($name, $id);
                    $ids[] = $id;
                }
            } catch (Throwable $e) {
                $state->noteIssue($title, sprintf(/* translators: %s: reason */ __('A tag could not be created: %s', 'pack-and-go'), $friendly->for($e)));
            }
        }

        return $ids;
    }

    /**
     * @return array{0: string, 1: string} [accountId, profileId]
     *
     * @throws RuntimeException when the connection is incomplete.
     */
    private function credentials(): array
    {
        $store = $this->plugin->store();
        $account = (string) $store->get('account_id', '');
        $profile = (string) $store->get('profile_id', '');

        if ($account === '' || $profile === '') {
            throw new RuntimeException(__('No NoTrouble profile is connected. Connect and choose a profile on the Pack & Go page.', 'pack-and-go'));
        }

        return array($account, $profile);
    }

    private function defaultFormat(string $sectionType): string
    {
        foreach ($this->plugin->client()->contentTypes() as $type) {
            if (($type['type'] ?? null) === $sectionType && is_string($type['defaultFormat'] ?? null)) {
                return $type['defaultFormat'];
            }
        }

        return 'list';
    }

    private function countPosts(string $wpType): int
    {
        $counts = wp_count_posts($wpType);

        return is_object($counts) && isset($counts->publish) ? (int) $counts->publish : 0;
    }

    private function countSelected(string $wpType, ImportState $state): int
    {
        $selection = $state->selection();

        return $selection !== null ? count($selection) : $this->countPosts($wpType);
    }

    public function cancel(string $wpType): array
    {
        $state = new ImportState($wpType);
        $state->markCanceled();
        $state->persist();

        return $state->toProgress();
    }
}
