<?php

namespace App\Support;

class BranchColor
{
    public const DEFAULTS = [
        'mabini' => '#EF4444',
        'adriatico' => '#EAB308',
        'modesto' => '#2563EB',
        'quirino' => '#7C3AED',
    ];

    public const FALLBACKS = [
        '#0EA5E9',
        '#10B981',
        '#F97316',
        '#EC4899',
        '#14B8A6',
        '#6366F1',
    ];

    public static function isValid(?string $color): bool
    {
        return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
    }

    public static function fallback(?string $name, ?int $id = null): string
    {
        $key = strtolower(trim((string) $name));

        foreach (self::DEFAULTS as $needle => $color) {
            if ($key !== '' && str_contains($key, $needle)) {
                return $color;
            }
        }

        $index = abs((int) $id) % count(self::FALLBACKS);

        return self::FALLBACKS[$index];
    }

    public static function resolve(?string $color, ?string $name = null, ?int $id = null): string
    {
        if (self::isValid($color)) {
            return strtoupper($color);
        }

        return self::fallback($name, $id);
    }

    public static function contrast(string $hex): string
    {
        $value = ltrim($hex, '#');
        if (strlen($value) !== 6) {
            return '#0F172A';
        }

        $red = hexdec(substr($value, 0, 2)) / 255;
        $green = hexdec(substr($value, 2, 2)) / 255;
        $blue = hexdec(substr($value, 4, 2)) / 255;
        $luminance = (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);

        return $luminance > 0.62 ? '#0F172A' : '#FFFFFF';
    }

    /**
     * @return array{color: string, text: string}
     */
    public static function payload(?string $color, ?string $name = null, ?int $id = null): array
    {
        $resolved = self::resolve($color, $name, $id);

        return [
            'color' => $resolved,
            'text' => self::contrast($resolved),
        ];
    }
}
