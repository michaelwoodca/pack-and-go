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
    );

    /**
     * @param array<string, mixed> $sectionType One entry from Client::contentTypes().
     * @return array<int, array{value: string, label: string, group: string, multi: bool}>
     */
    public function forSectionType(array $sectionType): array
    {
        $targets = array(
            array('value' => 'skip', 'label' => __('— Skip —', 'pack-and-go'), 'group' => '', 'multi' => true),
            array('value' => 'name', 'label' => __('Post title', 'pack-and-go'), 'group' => __('Core', 'pack-and-go'), 'multi' => false),
            array('value' => 'preview', 'label' => __('Summary', 'pack-and-go'), 'group' => __('Core', 'pack-and-go'), 'multi' => true),
            array('value' => 'content', 'label' => __('Body', 'pack-and-go'), 'group' => __('Core', 'pack-and-go'), 'multi' => true),
        );

        $fields = is_array($sectionType['fields'] ?? null) ? $sectionType['fields'] : array();

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = is_string($field['key'] ?? null) ? $field['key'] : '';

            if ($key === '' || isset(self::CORE_KEYS[$key]) || $key === 'links') {
                continue;
            }

            $label = is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $key;
            $slot = $this->mediaSlotFor($field);

            if ($slot !== null) {
                $targets[] = array(
                    'value' => 'media:' . $slot,
                    'label' => sprintf(/* translators: %s: field label */ __('Image: %s', 'pack-and-go'), $label),
                    'group' => __('Media', 'pack-and-go'),
                    'multi' => false,
                );

                continue;
            }

            $targets[] = array(
                'value' => 'cp:' . $key,
                'label' => $label,
                'group' => __('Fields', 'pack-and-go'),
                'multi' => false,
            );
        }

        $targets[] = array('value' => 'link_url', 'label' => __('Primary link URL', 'pack-and-go'), 'group' => __('Link', 'pack-and-go'), 'multi' => false);
        $targets[] = array('value' => 'link_label', 'label' => __('Primary link label', 'pack-and-go'), 'group' => __('Link', 'pack-and-go'), 'multi' => false);

        return $targets;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function mediaSlotFor(array $field): ?string
    {
        $inputType = is_string($field['inputType'] ?? null) ? $field['inputType'] : '';

        if ($inputType !== 'image') {
            return null;
        }

        $collection = is_string($field['uploadCollection'] ?? null) ? $field['uploadCollection'] : '';

        return self::COLLECTION_SLOTS[$collection] ?? null;
    }
}
