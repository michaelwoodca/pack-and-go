<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo\Discovery;

defined('ABSPATH') || exit;

enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case RichText = 'rich_text';
    case Number = 'number';
    case Url = 'url';
    case Email = 'email';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
    case Image = 'image';
    case File = 'file';
    case Video = 'video';
    case Gallery = 'gallery';
    case Color = 'color';
    case Unknown = 'unknown';

    public function isMedia(): bool
    {
        return match ($this) {
            self::Image, self::File, self::Video, self::Gallery => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => __('Text', 'pack-and-go'),
            self::Textarea => __('Text area', 'pack-and-go'),
            self::RichText => __('Rich text', 'pack-and-go'),
            self::Number => __('Number', 'pack-and-go'),
            self::Url => __('URL', 'pack-and-go'),
            self::Email => __('Email', 'pack-and-go'),
            self::Boolean => __('Yes / No', 'pack-and-go'),
            self::Date => __('Date', 'pack-and-go'),
            self::Select => __('Choice', 'pack-and-go'),
            self::Image => __('Image', 'pack-and-go'),
            self::File => __('File', 'pack-and-go'),
            self::Video => __('Video', 'pack-and-go'),
            self::Gallery => __('Gallery', 'pack-and-go'),
            self::Color => __('Color', 'pack-and-go'),
            self::Unknown => __('Unknown', 'pack-and-go'),
        };
    }
}
