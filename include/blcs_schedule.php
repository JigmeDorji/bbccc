<?php

function bbcc_blcs_default_intro_text(): string {
    return "Classes are held every Sunday during ACT school terms.";
}

function bbcc_blcs_default_terms_text(): string {
    return implode("\n", [
        "Term 1 (30 Jan - 2 Apr 2026)",
        "Term 2 (21 Apr - 3 Jul 2026)",
        "Term 3 (21 Jul - 25 Sep 2026)",
        "Term 4 (13 Oct - 18 Dec 2026)",
    ]);
}

function bbcc_blcs_default_sunday_dates_text(): string {
    return implode("\n", [
        "08 Feb 2026","15 Feb 2026","22 Feb 2026","01 Mar 2026","08 Mar 2026","15 Mar 2026",
        "22 Mar 2026","29 Mar 2026","5 Apr 2026","26 Apr 2026","03 May 2026","10 May 2026",
        "17 May 2026","24 May 2026","31 May 2026","07 Jun 2026","14 Jun 2026","21 Jun 2026",
        "28 Jun 2026","5 Jul 2026","26 Jul 2026","02 Aug 2026","09 Aug 2026","16 Aug 2026",
        "23 Aug 2026","30 Aug 2026","06 Sep 2026","13 Sep 2026","20 Sep 2026","27 Sep 2026",
        "18 Oct 2026","25 Oct 2026","01 Nov 2026","08 Nov 2026","15 Nov 2026","22 Nov 2026",
        "29 Nov 2026","06 Dec 2026","13 Dec 2026","20 Dec 2026",
    ]);
}

function bbcc_blcs_default_page_text(): string {
    return implode("\n", [
        "School Organisation and Management",
        "The Bhutanese Language and Culture School is established by the Bhutanese Buddhist and Culture Centre.",
        "The Centre is incorporated under the Associations Incorporations Act 1991 in Canberra, Australian Capital Territory.",
        "",
        "3.1 Objectives of the School",
        "The primary mandate of the Bhutanese Buddhist and Culture Centre (BBCC) is to foster Bhutanese Buddhist and cultural heritage including the promotion of Dzongkha as the national language among Bhutanese youth. Given the significant growth of the Bhutanese youth in Canberra, the establishment of a language and culture school has become imperative. Therefore, the Bhutanese Language and Culture School was established with the following objectives:",
        "• Introduce basic Buddhist spirituality and values to Bhutanese youth.",
        "• Develop proficiency in speaking, reading, and writing Dzongkha among Bhutanese youth.",
        "• Teach and promote Bhutanese culture and traditions to young people.",
        "• Offer opportunities for individuals interested in learning about Buddhism, language, and culture of Bhutan.",
        "",
        "What Children Will Learn at BLCS",
        "Students will be guided in:",
        "• Dzongkha Language (reading, speaking, and writing)",
        "• Driglam Namzha (Bhutanese etiquette and values)",
        "• Nangchoe (Buddhist values, mindfulness, and compassion)",
        "",
        "Flexible Payment Options",
        "To support families, fees may be paid using one of the following options:",
        "• Per term: $65",
        "• Half-yearly: $125",
        "• Full year: $250",
        "Payments can be made in cash or via bank transfer. Bank account details are avialable in parent portal.",
        "",
        "Class Locations & Time",
        "Morning Session – Woden Campus",
        "Alfred Deakin High School",
        "10:00am – 12:00pm",
        "Afternoon Session – Belconnen Campus",
        "Hawker College",
        "1:00pm – 3:00pm",
        "Children may attend either one or both sessions, depending on family preference.",
        "",
        "Enrollment Process",
        "1. Create a parent account through the registration page.",
        "2. Add your child details and submit class enrollment.",
        "3. Admin reviews and confirms enrollment.",
        "4. Choose preferred campus/session and payment option in parent portal.",
        "5. Attend class on Sunday and begin learning.",
    ]);
}

function bbcc_blcs_default_highlight_text(): string {
    return "Bhutanese Language and Culture School nurtures language, values, and identity through weekly Sunday learning for children and families.";
}

function bbcc_blcs_ensure_schedule_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blcs_schedule_settings (
            id TINYINT UNSIGNED PRIMARY KEY,
            intro_text TEXT NULL,
            terms_text TEXT NULL,
            sunday_dates_text LONGTEXT NULL,
            page_text LONGTEXT NULL,
            highlight_text TEXT NULL,
            updated_by VARCHAR(190) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $pdo->exec("ALTER TABLE blcs_schedule_settings ADD COLUMN page_text LONGTEXT NULL AFTER sunday_dates_text");
    } catch (Throwable $e) {
        // ignore if exists
    }
    try {
        $pdo->exec("ALTER TABLE blcs_schedule_settings ADD COLUMN highlight_text TEXT NULL AFTER page_text");
    } catch (Throwable $e) {
        // ignore if exists
    }
}

function bbcc_blcs_load_schedule(PDO $pdo): array {
    bbcc_blcs_ensure_schedule_table($pdo);
    $stmt = $pdo->prepare("SELECT intro_text, terms_text, sunday_dates_text, page_text, highlight_text FROM blcs_schedule_settings WHERE id = 1 LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'intro_text' => trim((string)($row['intro_text'] ?? '')) !== '' ? (string)$row['intro_text'] : bbcc_blcs_default_intro_text(),
        'terms_text' => trim((string)($row['terms_text'] ?? '')) !== '' ? (string)$row['terms_text'] : bbcc_blcs_default_terms_text(),
        'sunday_dates_text' => trim((string)($row['sunday_dates_text'] ?? '')) !== '' ? (string)$row['sunday_dates_text'] : bbcc_blcs_default_sunday_dates_text(),
        'page_text' => trim((string)($row['page_text'] ?? '')) !== '' ? (string)$row['page_text'] : bbcc_blcs_default_page_text(),
        'highlight_text' => trim((string)($row['highlight_text'] ?? '')) !== '' ? (string)$row['highlight_text'] : bbcc_blcs_default_highlight_text(),
    ];
}
