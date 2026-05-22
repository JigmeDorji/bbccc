<?php

function bbcc_default_sponsor_program_settings(): array
{
    return [
        'icons' => [
            'icon_one' => 'fa-calendar-day',
            'icon_two' => 'fa-moon',
            'icon_three' => 'fa-spa',
        ],
        'images' => [
            'image_one' => '',
            'image_two' => '',
            'image_three' => '',
            'detail_image_one' => '',
            'detail_image_two' => '',
            'detail_image_three' => '',
        ],
        'texts' => [
            'title_one' => '10th Day of Bhutanese Month (Tshe Chutham)',
            'title_two' => '15th Day of Bhutanese Month (Tshe Chenga)',
            'title_three' => 'Monthly Tara and Menlha Dungdrup',
            'date_one' => '10th day of each Bhutanese month (Tshe Chutham).',
            'date_two' => '15th day of each Bhutanese month (Tshe Chenga).',
            'date_three' => 'Monthly (as scheduled by the Centre).',
            'detail_one' => 'On the 10th day of each Bhutanese month, the Centre observes Guru Rinpoche Day (Tshe Chutham) with prayers and community practice. Families, groups, and individuals are welcome to sponsor this monthly ritual and participate in preserving this sacred tradition.',
            'detail_two' => 'On the 15th day of each Bhutanese month (Tshe Chenga), the Centre holds Yum Ekazati and Gyenyen Tshokhor practice. Community members are warmly invited to attend, receive blessings, and support this important monthly observance through sponsorship.',
            'detail_three' => 'The Centre also conducts monthly Tara and Menlha Dungdrup prayers for wellbeing, healing, and merit. We warmly welcome sponsorship from individuals, families, and groups who wish to support these regular spiritual practices.',
        ],
        'styles' => [
            'style_one' => 'classic',
            'style_two' => 'split',
            'style_three' => 'highlight',
        ],
    ];
}

function bbcc_load_sponsor_program_settings(string $DB_HOST, string $DB_NAME, string $DB_USER, string $DB_PASSWORD): array
{
    $data = bbcc_default_sponsor_program_settings();
    try {
        $pdo = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]
        );
        $stmt = $pdo->prepare("SELECT * FROM sponsor_settings WHERE id = 1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($row)) {
            foreach (array_keys($data['icons']) as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '' && preg_match('/^fa-[a-z0-9-]+$/', $v)) {
                    $data['icons'][$k] = $v;
                }
            }
            foreach (array_keys($data['images']) as $k) {
                $data['images'][$k] = trim((string)($row[$k] ?? ''));
            }
            foreach (array_keys($data['texts']) as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '') {
                    $data['texts'][$k] = $v;
                }
            }
            foreach (array_keys($data['styles']) as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if (in_array($v, ['classic', 'split', 'highlight'], true)) {
                    $data['styles'][$k] = $v;
                }
            }
        }
    } catch (Exception $e) {
        // keep defaults
    }
    return $data;
}

function bbcc_get_sponsor_program_view_data(array $settings, int $programKey): array
{
    $map = [
        1 => ['title' => 'title_one', 'date' => 'date_one', 'image' => 'image_one', 'detail_image' => 'detail_image_one', 'icon' => 'icon_one', 'detail' => 'detail_one', 'style' => 'style_one'],
        2 => ['title' => 'title_two', 'date' => 'date_two', 'image' => 'image_two', 'detail_image' => 'detail_image_two', 'icon' => 'icon_two', 'detail' => 'detail_two', 'style' => 'style_two'],
        3 => ['title' => 'title_three', 'date' => 'date_three', 'image' => 'image_three', 'detail_image' => 'detail_image_three', 'icon' => 'icon_three', 'detail' => 'detail_three', 'style' => 'style_three'],
    ];
    if (!isset($map[$programKey])) {
        $programKey = 1;
    }
    $sel = $map[$programKey];
    $imagePath = (string)$settings['images'][$sel['detail_image']];
    if ($imagePath === '') {
        $imagePath = (string)$settings['images'][$sel['image']];
    }
    return [
        'title' => (string)$settings['texts'][$sel['title']],
        'date' => (string)$settings['texts'][$sel['date']],
        'detail' => (string)$settings['texts'][$sel['detail']],
        'image' => $imagePath,
        'icon' => (string)$settings['icons'][$sel['icon']],
        'style' => (string)$settings['styles'][$sel['style']],
    ];
}
