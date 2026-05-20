<?php

function bbcc_image_abs_path(string $relativePath): string {
    return __DIR__ . '/../' . ltrim($relativePath, '/');
}

function bbcc_image_meta(string $relativePath): array {
    static $cache = [];
    if (isset($cache[$relativePath])) return $cache[$relativePath];
    $abs = bbcc_image_abs_path($relativePath);
    if (!is_file($abs)) {
        return $cache[$relativePath] = ['width' => null, 'height' => null];
    }
    $size = @getimagesize($abs);
    if (!$size) {
        return $cache[$relativePath] = ['width' => null, 'height' => null];
    }
    return $cache[$relativePath] = ['width' => (int)$size[0], 'height' => (int)$size[1]];
}

function bbcc_image_variants(string $relativePath, array $widths = [480, 768, 1200]): array {
    $relativePath = ltrim($relativePath, '/');
    $abs = bbcc_image_abs_path($relativePath);
    if (!is_file($abs)) return ['webp' => [], 'fallback' => []];

    $dir = dirname($relativePath);
    $filename = pathinfo($relativePath, PATHINFO_FILENAME);
    $ext = strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));
    $supportedFallbackExt = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

    $webp = [];
    $fallback = [];
    foreach ($widths as $w) {
        $w = (int)$w;
        if ($w <= 0) continue;
        $webpRel = ($dir === '.' ? '' : $dir . '/') . $filename . '-' . $w . '.webp';
        if (is_file(bbcc_image_abs_path($webpRel))) {
            $webp[] = ['src' => $webpRel, 'w' => $w];
        }
        if ($supportedFallbackExt) {
            $fallbackRel = ($dir === '.' ? '' : $dir . '/') . $filename . '-' . $w . '.' . $ext;
            if (is_file(bbcc_image_abs_path($fallbackRel))) {
                $fallback[] = ['src' => $fallbackRel, 'w' => $w];
            }
        }
    }

    return ['webp' => $webp, 'fallback' => $fallback];
}

function bbcc_srcset(array $entries): string {
    $parts = [];
    foreach ($entries as $entry) {
        $parts[] = $entry['src'] . ' ' . (int)$entry['w'] . 'w';
    }
    return implode(', ', $parts);
}

function bbcc_render_responsive_picture(string $relativePath, string $alt, array $opts = []): string {
    $relativePath = ltrim($relativePath, '/');
    $class = (string)($opts['class'] ?? '');
    $sizes = (string)($opts['sizes'] ?? '100vw');
    $loading = (string)($opts['loading'] ?? 'lazy');
    $decoding = (string)($opts['decoding'] ?? 'async');
    $fetchpriority = (string)($opts['fetchpriority'] ?? '');
    $widths = (array)($opts['widths'] ?? [480, 768, 1200]);

    $meta = bbcc_image_meta($relativePath);
    $variants = bbcc_image_variants($relativePath, $widths);

    $attrs = [];
    if ($class !== '') $attrs[] = 'class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
    $attrs[] = 'src="' . htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') . '"';
    $attrs[] = 'alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"';
    if ($sizes !== '') $attrs[] = 'sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '"';
    if ($loading !== '') $attrs[] = 'loading="' . htmlspecialchars($loading, ENT_QUOTES, 'UTF-8') . '"';
    if ($decoding !== '') $attrs[] = 'decoding="' . htmlspecialchars($decoding, ENT_QUOTES, 'UTF-8') . '"';
    if ($fetchpriority !== '') $attrs[] = 'fetchpriority="' . htmlspecialchars($fetchpriority, ENT_QUOTES, 'UTF-8') . '"';
    if (!empty($meta['width'])) $attrs[] = 'width="' . (int)$meta['width'] . '"';
    if (!empty($meta['height'])) $attrs[] = 'height="' . (int)$meta['height'] . '"';

    $html = '<picture>';
    if (!empty($variants['webp'])) {
        $html .= '<source type="image/webp" srcset="' . htmlspecialchars(bbcc_srcset($variants['webp']), ENT_QUOTES, 'UTF-8') . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '">';
    }
    if (!empty($variants['fallback'])) {
        $html .= '<source srcset="' . htmlspecialchars(bbcc_srcset($variants['fallback']), ENT_QUOTES, 'UTF-8') . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '">';
    }
    $html .= '<img ' . implode(' ', $attrs) . '>';
    $html .= '</picture>';
    return $html;
}

function bbcc_generate_responsive_variants(string $sourceAbsPath, array $widths = [480, 768, 1200], int $webpQuality = 80): void {
    if (!is_file($sourceAbsPath)) return;
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) return;

    $info = @getimagesize($sourceAbsPath);
    if (!$info || empty($info[2])) return;

    $imgType = (int)$info[2];
    $srcW = (int)$info[0];
    $srcH = (int)$info[1];
    if ($srcW <= 0 || $srcH <= 0) return;

    switch ($imgType) {
        case IMAGETYPE_JPEG: $srcImg = @imagecreatefromjpeg($sourceAbsPath); break;
        case IMAGETYPE_PNG:  $srcImg = @imagecreatefrompng($sourceAbsPath); break;
        case IMAGETYPE_GIF:  $srcImg = @imagecreatefromgif($sourceAbsPath); break;
        case IMAGETYPE_WEBP: $srcImg = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourceAbsPath) : null; break;
        default: $srcImg = null;
    }
    if (!$srcImg) return;

    $dir = dirname($sourceAbsPath);
    $base = pathinfo($sourceAbsPath, PATHINFO_FILENAME);
    $ext = strtolower((string)pathinfo($sourceAbsPath, PATHINFO_EXTENSION));
    $isPng = ($imgType === IMAGETYPE_PNG);

    foreach ($widths as $targetW) {
        $targetW = (int)$targetW;
        if ($targetW <= 0 || $targetW >= $srcW) continue;
        $targetH = (int)round(($srcH * $targetW) / $srcW);
        if ($targetH <= 0) continue;

        $dst = imagecreatetruecolor($targetW, $targetH);
        if (!$dst) continue;

        if ($isPng) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $trans);
        }

        imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

        // WebP variant
        @imagewebp($dst, $dir . '/' . $base . '-' . $targetW . '.webp', $webpQuality);

        // Fallback same-format variant
        $fallbackPath = $dir . '/' . $base . '-' . $targetW . '.' . $ext;
        switch ($imgType) {
            case IMAGETYPE_JPEG: @imagejpeg($dst, $fallbackPath, 82); break;
            case IMAGETYPE_PNG: @imagepng($dst, $fallbackPath, 6); break;
            case IMAGETYPE_GIF: @imagegif($dst, $fallbackPath); break;
            case IMAGETYPE_WEBP: @imagewebp($dst, $fallbackPath, 82); break;
        }
        imagedestroy($dst);
    }

    imagedestroy($srcImg);
}

