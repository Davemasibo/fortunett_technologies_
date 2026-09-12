<?php
/** Shared appearance for public and authenticated customer pages. */
require_once __DIR__ . '/../../includes/hotspot_theme.php';

function customerThemeData(PDO $pdo, int $tenantId): array
{
    $theme = hotspotThemeLoad($pdo, $tenantId);
    $palette = hotspotThemePalette($theme);
    $image = $theme['bg_style'] === 'image'
        ? hotspotThemeAssetDataUri($theme['bg_image'], HS_WALLPAPER_MAX) : '';
    if ($theme['bg_style'] === 'image' && $image === '') $theme['bg_style'] = 'gradient';
    return [
        'vars' => $palette['vars'],
        'background' => hotspotThemeBodyBackground($theme, $image),
        'accent' => $theme['accent'],
        'logo' => hotspotThemeAssetDataUri($theme['logo'], HS_LOGO_MAX),
        'is_light' => $palette['is_light'],
    ];
}

function customerThemeHead(PDO $pdo, int $tenantId): void
{
    $data = customerThemeData($pdo, $tenantId);
    echo '<style id="tenant-customer-theme">html:root{' . $data['vars']
        . ';--customer-background:' . $data['background'] . ';}</style>';
    echo '<link rel="stylesheet" href="css/portal.css?v=' . filemtime(__DIR__ . '/../css/portal.css') . '">';
}
