<?php

declare(strict_types=1);

namespace Machma\Forms\Admin;

/** Stacked daily bar chart as inline SVG – rendered on the server, no script, no CDN. */
final class Chart
{
    public const SERIES = ['sent', 'spam', 'invalid', 'rate_limited', 'error'];

    /** @param array<string, array<string, int>> $daily date => status => count */
    public static function svg(array $daily, int $days, int $now): string
    {
        $width = 720;
        $height = 180;
        $padLeft = 32;
        $padBottom = 22;
        $plotW = $width - $padLeft - 4;
        $plotH = $height - $padBottom - 8;

        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = gmdate('Y-m-d', $now - $i * 86400);
        }
        $max = 1;
        foreach ($dates as $d) {
            $max = max($max, array_sum($daily[$d] ?? []));
        }
        $max = self::niceMax($max);
        $barW = $plotW / $days;
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $out = sprintf('<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="Einsendungen pro Tag" preserveAspectRatio="none">', $width, $height);
        foreach ([0, 0.5, 1] as $f) {
            $y = 8 + $plotH - $plotH * $f;
            $out .= sprintf('<line class="grid" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>', $padLeft, $width - 4, $y, $y);
            $out .= sprintf('<text class="axis" x="%d" y="%.1f" text-anchor="end">%d</text>', $padLeft - 6, $y + 4, (int) round($max * $f));
        }
        foreach ($dates as $i => $d) {
            $x = $padLeft + $i * $barW;
            $y = 8 + $plotH;
            $title = [];
            foreach (self::SERIES as $s) {
                $n = $daily[$d][$s] ?? 0;
                if ($n === 0) {
                    continue;
                }
                $h = $plotH * $n / $max;
                $y -= $h;
                $out .= sprintf('<rect class="s-%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>', $s, $x + 1, $y, max(1, $barW - 2), $h);
                $title[] = "$s: $n";
            }
            if ($title) {
                $out .= sprintf('<rect class="hit" x="%.1f" y="8" width="%.1f" height="%d"><title>%s</title></rect>',
                    $x, $barW, $plotH, $e(gmdate('d.m.', strtotime($d)) . ' – ' . implode(', ', $title)));
            }
            $every = $days > 31 ? 14 : 7;
            if (($days - 1 - $i) % $every === 0) {
                $out .= sprintf('<text class="axis" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x + $barW / 2, $height - 6, gmdate('d.m.', strtotime($d)));
            }
        }
        return $out . '</svg>';
    }

    private static function niceMax(int $n): int
    {
        // even steps, so the middle grid line is a whole number
        foreach ([2, 4, 6, 10, 20, 40, 60, 100, 200, 400, 600, 1000, 2000, 4000, 6000, 10000] as $step) {
            if ($n <= $step) {
                return $step;
            }
        }
        return (int) (ceil($n / 10000) * 10000);
    }
}
