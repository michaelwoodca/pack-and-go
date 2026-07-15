<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Mapping;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Discovery\DiscoveredPostType;
use NoTrouble\PackAndGo\Discovery\FieldType;

final class MappingSuggester
{
    /**
     * @var array<string, string>
     */
    private const KEYWORD_TARGETS = array(
        'tagline' => 'preview', 'subtitle' => 'preview', 'summary' => 'preview', 'excerpt' => 'preview',
        'challenge' => 'content', 'strategy' => 'content', 'description' => 'content', 'overview' => 'content',
        'body' => 'content', 'result' => 'content', 'approach' => 'content', 'endorsement' => 'content',
        'solution' => 'content', 'process' => 'content',
        'website' => 'link_url', 'link' => 'link_url', 'url' => 'link_url',
    );

    /**
     * @param array<int, array<string, mixed>> $contentTypes
     */
    public function suggestSectionType(DiscoveredPostType $postType, array $contentTypes): string
    {
        $available = array();
        foreach ($contentTypes as $type) {
            if (is_string($type['type'] ?? null)) {
                $available[$type['type']] = true;
            }
        }

        $labels = strtolower($postType->name . ' ' . $postType->label);
        $hasVideo = false;
        $hasBeforeAfter = false;
        foreach ($postType->fields as $field) {
            $key = strtolower($field->metaKey . ' ' . $field->label);
            $hasVideo = $hasVideo || $field->type === FieldType::Video || str_contains($key, 'video');
            $hasBeforeAfter = $hasBeforeAfter || str_contains($key, 'after') || str_contains($key, 'before');
        }

        $preference = array();
        if ($hasVideo) {
            $preference[] = 'video';
        }
        if ($hasBeforeAfter) {
            $preference[] = 'before_after';
        }
        if (str_contains($labels, 'product') || str_contains($labels, 'shop')) {
            $preference[] = 'product';
        }
        $preference[] = 'article';
        $preference[] = 'text';

        foreach ($preference as $candidate) {
            if (isset($available[$candidate])) {
                return $candidate;
            }
        }

        return is_string($contentTypes[0]['type'] ?? null) ? $contentTypes[0]['type'] : 'article';
    }

    /**
     * @param array<int, array{value: string, label: string, group: string, multi: bool}>       $targets
     * @param array<int, array{key: string, label: string, type: string, isMedia: bool}>          $wpItems
     * @return array<string, string> targetValue => wpItemKey ('' = unmapped)
     */
    public function suggestTargetMap(array $targets, array $wpItems): array
    {
        $usedWp = array();
        $take = static function (?string $key) use (&$usedWp): string {
            if ($key === null || $key === '' || isset($usedWp[$key])) {
                return '';
            }
            $usedWp[$key] = true;

            return $key;
        };

        $byKey = array();
        foreach ($wpItems as $item) {
            $byKey[$item['key']] = $item;
        }

        $findByKeyword = function (string $targetValue) use ($wpItems, $usedWp): ?string {
            foreach ($wpItems as $item) {
                if (isset($usedWp[$item['key']])) {
                    continue;
                }
                $haystack = strtolower($item['key'] . ' ' . $item['label']);
                foreach (self::KEYWORD_TARGETS as $keyword => $candidate) {
                    if ($candidate === $targetValue && str_contains($haystack, $keyword)) {
                        return $item['key'];
                    }
                }
            }

            return null;
        };

        $firstUnusedImage = function () use ($wpItems, $usedWp): ?string {
            foreach ($wpItems as $item) {
                if (! isset($usedWp[$item['key']]) && $item['isMedia']) {
                    return $item['key'];
                }
            }

            return null;
        };

        $firstUnusedRich = function () use ($wpItems, $usedWp): ?string {
            foreach ($wpItems as $item) {
                if (isset($usedWp[$item['key']])) {
                    continue;
                }
                if (in_array($item['type'], array(FieldType::RichText->value, FieldType::Textarea->value), true)) {
                    return $item['key'];
                }
            }

            return null;
        };

        $map = array();

        foreach ($targets as $target) {
            $value = $target['value'];

            if ($value === 'skip') {
                continue;
            }

            $wpKey = match (true) {
                $value === 'name' => $take(isset($byKey['_title']) ? '_title' : null),
                $value === 'content' => $take(isset($byKey['_content']) ? '_content' : ($findByKeyword('content') ?? $firstUnusedRich())),
                $value === 'preview' => $take($findByKeyword('preview') ?? (isset($byKey['_excerpt']) ? '_excerpt' : null)),
                str_starts_with($value, 'media:') => $take($firstUnusedImage()),
                str_starts_with($value, 'cp:') => $take($this->matchCustomProperty(substr($value, 3), $wpItems, $usedWp)),
                $value === 'link_url' => $take($findByKeyword('link_url')),
                default => '',
            };

            $map[$value] = $wpKey;
        }

        return $map;
    }

    /**
     * @param array<int, array{key: string, label: string, type: string, isMedia: bool}> $wpItems
     * @return array<int, string>
     */
    public function suggestContentFields(array $wpItems): array
    {
        $keys = array();

        foreach ($wpItems as $item) {
            if ($item['isMedia']) {
                continue;
            }

            if ($item['key'] === '_content') {
                $keys[] = $item['key'];

                continue;
            }

            $haystack = strtolower($item['key'] . ' ' . $item['label']);
            foreach (self::KEYWORD_TARGETS as $keyword => $candidate) {
                if ($candidate === 'content' && str_contains($haystack, $keyword)) {
                    $keys[] = $item['key'];

                    break;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, array{key: string, label: string, type: string, isMedia: bool}> $wpItems
     * @param array<string, bool>                                                          $usedWp
     */
    private function matchCustomProperty(string $cpKey, array $wpItems, array $usedWp): ?string
    {
        $needle = strtolower(str_replace('_', ' ', $cpKey));

        foreach ($wpItems as $item) {
            if (isset($usedWp[$item['key']])) {
                continue;
            }
            $key = strtolower(str_replace(array('wpcf-', '_', '-'), array('', ' ', ' '), $item['key']));
            $label = strtolower($item['label']);
            if ($key === $needle || $label === $needle || str_contains($key, $needle) || str_contains($label, $needle)) {
                return $item['key'];
            }
        }

        return null;
    }
}
