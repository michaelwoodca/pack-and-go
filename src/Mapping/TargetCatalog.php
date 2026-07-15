<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Mapping;

defined('ABSPATH') || exit;

final class TargetCatalog
{
    private const CORE_KEYS = array('title' => 'name', 'preview' => 'preview', 'content' => 'content');

    private const COLLECTION_SLOTS = array(
        'image' => 'post_image',
        'cover' => 'post_cover',
        'after_image' => 'post_after_image',
        'map' => 'post_map',
        'video-source' => 'post_video',
    );

    /**
     * NoTrouble-internal reference fields that can't be sourced from WordPress content (they point
     * at a NoTrouble form or email integration by id), so we don't offer them as mapping targets.
     *
     * @var list<string>
     */
    private const SKIP_KEYS = array('form_id', 'list_id');

    /**
     * @param array<string, mixed> $sectionType One entry from Client::contentTypes().
     * @return array<int, array{value: string, label: string, group: string, multi: bool}>
     */
    public function forSectionType(array $sectionType): array
    {
        $fields = is_array($sectionType['fields'] ?? null) ? $sectionType['fields'] : array();

        $requiredByKey = array();
        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $requiredByKey[$field['key']] = (bool) ($field['required'] ?? false);
            }
        }

        $targets = array(
            array('value' => 'skip', 'label' => __('— Skip —', 'pack-and-go'), 'group' => '', 'multi' => true, 'required' => false),
            // The title auto-falls back to the WordPress post title, so it never imports empty.
            array('value' => 'name', 'label' => __('Post title', 'pack-and-go'), 'group' => __('Core', 'pack-and-go'), 'multi' => false, 'required' => false),
            array('value' => 'preview', 'label' => __('Summary', 'pack-and-go'), 'group' => __('Core', 'pack-and-go'), 'multi' => true, 'required' => $requiredByKey['preview'] ?? false),
            array('value' => 'content', 'label' => __('Body', 'pack-and-go'), 'group' => __('Core', 'pack-and-go'), 'multi' => true, 'required' => $requiredByKey['content'] ?? false),
        );

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = is_string($field['key'] ?? null) ? $field['key'] : '';

            if ($key === '' || isset(self::CORE_KEYS[$key]) || $key === 'links' || in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }

            $label = is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $key;
            $slot = $this->mediaSlotFor($field);

            if ($slot !== null) {
                $isVideo = ($field['inputType'] ?? null) === 'video';
                $targets[] = array(
                    'value' => 'media:' . $slot,
                    'label' => $isVideo
                        ? sprintf(/* translators: %s: field label */ __('Video: %s', 'pack-and-go'), $label)
                        : sprintf(/* translators: %s: field label */ __('Image: %s', 'pack-and-go'), $label),
                    'group' => __('Media', 'pack-and-go'),
                    'multi' => false,
                    'required' => (bool) ($field['required'] ?? false),
                );

                continue;
            }

            $options = is_array($field['options'] ?? null)
                ? array_values(array_map('strval', array_keys($field['options'])))
                : array();

            $targets[] = array(
                'value' => 'cp:' . $key,
                'label' => $label,
                'group' => __('Fields', 'pack-and-go'),
                'multi' => false,
                'options' => $options,
                'required' => (bool) ($field['required'] ?? false),
            );
        }

        $targets[] = array('value' => 'link_url', 'label' => __('Primary link URL', 'pack-and-go'), 'group' => __('Link', 'pack-and-go'), 'multi' => false, 'required' => false);
        $targets[] = array('value' => 'link_label', 'label' => __('Primary link label', 'pack-and-go'), 'group' => __('Link', 'pack-and-go'), 'multi' => false, 'required' => false);

        return $targets;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function mediaSlotFor(array $field): ?string
    {
        $inputType = is_string($field['inputType'] ?? null) ? $field['inputType'] : '';

        if (! in_array($inputType, array('image', 'video'), true)) {
            return null;
        }

        $collection = is_string($field['uploadCollection'] ?? null) ? $field['uploadCollection'] : '';

        return self::COLLECTION_SLOTS[$collection] ?? null;
    }
}
