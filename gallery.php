<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function getCategoryLabel(string $category): string
{
    $categories = [
        'events'        => 'Events',
        'computer-lab'  => 'Computer Lab',
        'library'       => 'Library',
        'science-lab'   => 'Science Lab',
        'classrooms'    => 'Classrooms',
        'campus'        => 'School Campus',
        'sports'        => 'Sports',
        'cultural'      => 'Cultural Activities',
        'graduation'    => 'Graduation',
    ];

    return $categories[$category] ?? ucwords(str_replace('-', ' ', $category));
}

function getCategoryIcon(string $category): string
{
    $icons = [
        'events'        => 'bi-calendar-event',
        'computer-lab'  => 'bi-pc-display',
        'library'       => 'bi-book',
        'science-lab'   => 'bi-flask',
        'classrooms'    => 'bi-easel2',
        'campus'        => 'bi-buildings',
        'sports'        => 'bi-trophy',
        'cultural'      => 'bi-music-note-beamed',
        'graduation'    => 'bi-mortarboard',
    ];

    return $icons[$category] ?? 'bi-image';
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date Helper
|--------------------------------------------------------------------------
*/

function formatEthiopianDate(string $dateTime): string
{
    try {
        $date = date('Y-m-d', strtotime($dateTime));

        $result = EthiopianCalendar::fromGregorian($date);

        /*
         * Support different possible return structures from
         * EthiopianCalendar.php.
         */

        if (is_array($result)) {
            $year = $result['year'] ?? null;
            $month = $result['month'] ?? null;
            $day = $result['day'] ?? null;

            if ($year !== null && $month !== null && $day !== null) {
                $monthNames = [
                    1  => 'Meskerem',
                    2  => 'Tikemet',
                    3  => 'Hidar',
                    4  => 'Tahsas',
                    5  => 'Tir',
                    6  => 'Yekatit',
                    7  => 'Megabit',
                    8  => 'Miazia',
                    9  => 'Ginbot',
                    10 => 'Sene',
                    11 => 'Hamle',
                    12 => 'Nehase',
                    13 => 'Pagume',
                ];

                $monthName = $monthNames[(int)$month] ?? (string)$month;

                return $day . ' ' . $monthName . ' ' . $year;
            }
        }

        /*
         * If the class returns an object.
         */
        if (is_object($result)) {
            $year = $result->year ?? null;
            $month = $result->month ?? null;
            $day = $result->day ?? null;

            if ($year !== null && $month !== null && $day !== null) {
                $monthNames = [
                    1  => 'Meskerem',
                    2  => 'Tikemet',
                    3  => 'Hidar',
                    4  => 'Tahsas',
                    5  => 'Tir',
                    6  => 'Yekatit',
                    7  => 'Megabit',
                    8  => 'Miazia',
                    9  => 'Ginbot',
                    10 => 'Sene',
                    11 => 'Hamle',
                    12 => 'Nehase',
                    13 => 'Pagume',
                ];

                $monthName = $monthNames[(int)$month] ?? (string)$month;

                return $day . ' ' . $monthName . ' ' . $year;
            }
        }

    } catch (Throwable $exception) {
        // Fall back silently.
    }

    return '';
}


/*
|--------------------------------------------------------------------------
| Gallery Data
|--------------------------------------------------------------------------
*/

$galleryItems = [];
$databaseError = '';

$sql = "
    SELECT
        id,
        title,
        description,
        category,
        image_path,
        created_at
    FROM gallery
    ORDER BY created_at DESC, id DESC
";

$result = $conn->query($sql);

if ($result === false) {

    $databaseError = 'Unable to load the school gallery at this time.';

} else {

    while ($row = $result->fetch_assoc()) {

        $imagePath = trim((string)($row['image_path'] ?? ''));

        if ($imagePath === '') {
            continue;
        }

        /*
         * Database:
         * gallery/events/photo.jpg
         *
         * Browser:
         * public/gallery/events/photo.jpg
         */

        $imageUrl = 'public/' . ltrim($imagePath, '/\\');

        $galleryItems[] = [
            'id'          => (int)$row['id'],
            'title'       => trim((string)($row['title'] ?? '')),
            'description' => trim((string)($row['description'] ?? '')),
            'category'    => trim((string)($row['category'] ?? '')),
            'categoryLabel' => getCategoryLabel(
                trim((string)($row['category'] ?? ''))
            ),
            'categoryIcon' => getCategoryIcon(
                trim((string)($row['category'] ?? ''))
            ),
            'image'       => $imageUrl,
            'date'        => formatEthiopianDate(
                (string)($row['created_at'] ?? '')
            ),
        ];
    }

    $result->free();
}


/*
|--------------------------------------------------------------------------
| Gallery Statistics
|--------------------------------------------------------------------------
*/

$totalPhotos = count($galleryItems);

$categoryCounts = [];

foreach ($galleryItems as $item) {

    $category = $item['category'];

    if (!isset($categoryCounts[$category])) {
        $categoryCounts[$category] = 0;
    }

    $categoryCounts[$category]++;
}

$totalCategories = count($categoryCounts);


/*
|--------------------------------------------------------------------------
| Hero Image
|--------------------------------------------------------------------------
*/

$heroImage = null;

if (!empty($galleryItems)) {
    $heroImage = $galleryItems[0]['image'];
}


/*
|--------------------------------------------------------------------------
| JSON Data For JavaScript
|--------------------------------------------------------------------------
*/

$galleryJson = json_encode(
    $galleryItems,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP |
    JSON_UNESCAPED_SLASHES
);

if ($galleryJson === false) {
    $galleryJson = '[]';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Explore the Bole Kale Hiwot School gallery featuring school events, classrooms, laboratories, library, sports, graduation and everyday school life."
    >

    <meta
        name="keywords"
        content="Bole Kale Hiwot School, BKHS, school gallery, Addis Ababa school, school events"
    >

    <title>Gallery | Bole Kale Hiwot School</title>

    <link rel="icon" type="image/webp" href="public/image/logo.webp">


    <!-- =========================
         GOOGLE FONT
    ========================== -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- =========================
         BOOTSTRAP
    ========================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =========================
         BOOTSTRAP ICONS
    ========================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- =========================
         PAGE CSS
    ========================== -->

    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-soft: #eff6ff;
            --text-dark: #111827;
            --text-muted: #64748b;
            --border: #e5e7eb;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --dark: #0f172a;
            --radius-lg: 24px;
            --radius-md: 16px;
            --shadow-sm: 0 8px 25px rgba(15, 23, 42, .06);
            --shadow-md: 0 20px 50px rgba(15, 23, 42, .12);
        }


        * {
            box-sizing: border-box;
        }


        html {
            scroll-behavior: smooth;
        }


        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            background: #fff;
            overflow-x: hidden;
        }


        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }


        a {
            text-decoration: none;
        }


        /* =====================================================
           NAVBAR
        ===================================================== */

        .site-header {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: rgba(255, 255, 255, .94);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(226, 232, 240, .8);
        }


        .navbar {
            min-height: 82px;
        }


        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }


        .brand-logo {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }


        .brand-info {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }


        .brand-name {
            color: var(--text-dark);
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 17px;
            font-weight: 800;
        }


        .brand-school {
            color: var(--primary);
            font-size: 13px;
            font-weight: 600;
            margin-top: 3px;
        }


        .nav-link {
            color: #475569;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 14px !important;
            transition: .25s ease;
        }


        .nav-link:hover,
        .nav-link.active {
            color: var(--primary);
        }


        .login-item {
            margin-left: 10px;
        }


        .btn-login {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            color: #fff;
            background: var(--primary);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            transition: .25s ease;
        }


        .btn-login:hover {
            color: #fff;
            background: var(--primary-dark);
            transform: translateY(-1px);
        }


        /* =====================================================
           HERO
        ===================================================== */

        .gallery-hero {
            position: relative;
            min-height: 500px;
            display: flex;
            align-items: center;
            overflow: hidden;
            background:
                linear-gradient(
                    135deg,
                    #0f172a 0%,
                    #172554 55%,
                    #1d4ed8 100%
                );
        }


        .gallery-hero-bg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: .30;
        }


        .gallery-hero-overlay {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(
                    90deg,
                    rgba(15, 23, 42, .96) 0%,
                    rgba(15, 23, 42, .78) 45%,
                    rgba(29, 78, 216, .42) 100%
                );
        }


        .hero-decoration {
            position: absolute;
            width: 420px;
            height: 420px;
            border: 1px solid rgba(255, 255, 255, .10);
            border-radius: 50%;
            right: -150px;
            top: -150px;
        }


        .hero-decoration-two {
            position: absolute;
            width: 300px;
            height: 300px;
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 50%;
            right: 80px;
            bottom: -180px;
        }


        .gallery-hero-content {
            position: relative;
            z-index: 2;
            max-width: 780px;
            padding: 90px 0;
        }


        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 8px 14px;
            margin-bottom: 22px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 50px;
            background: rgba(255, 255, 255, .10);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            backdrop-filter: blur(10px);
        }


        .badge-dot {
            width: 7px;
            height: 7px;
            background: #60a5fa;
            border-radius: 50%;
            box-shadow: 0 0 0 5px rgba(96, 165, 250, .15);
        }


        .gallery-hero h1 {
            margin-bottom: 22px;
            color: #fff;
            font-size: clamp(42px, 6vw, 72px);
            font-weight: 800;
            letter-spacing: -.045em;
            line-height: 1.02;
        }


        .gallery-hero h1 span {
            color: #60a5fa;
        }


        .gallery-hero p {
            max-width: 680px;
            margin: 0;
            color: rgba(255, 255, 255, .76);
            font-size: 17px;
            line-height: 1.8;
        }


        .hero-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 32px;
        }


        .hero-stat {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 16px;
            border: 1px solid rgba(255, 255, 255, .13);
            border-radius: 13px;
            background: rgba(255, 255, 255, .08);
            color: #fff;
            backdrop-filter: blur(10px);
        }


        .hero-stat i {
            color: #93c5fd;
            font-size: 18px;
        }


        .hero-stat strong {
            font-size: 15px;
        }


        .hero-stat span {
            color: rgba(255, 255, 255, .65);
            font-size: 12px;
        }


        /* =====================================================
           GALLERY SECTION
        ===================================================== */

        .gallery-section {
            padding: 90px 0 110px;
            background: #fff;
        }


        .section-heading {
            max-width: 720px;
            margin: 0 auto 45px;
            text-align: center;
        }


        .section-label {
            display: inline-block;
            margin-bottom: 13px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
        }


        .section-heading h2 {
            margin-bottom: 14px;
            font-size: clamp(32px, 4vw, 46px);
            font-weight: 800;
            letter-spacing: -.035em;
        }


        .section-heading h2 span {
            color: var(--primary);
        }


        .section-heading p {
            margin: 0;
            color: var(--text-muted);
            font-size: 16px;
            line-height: 1.8;
        }


        /* =====================================================
           CONTROLS
        ===================================================== */

        .gallery-controls {
            margin-bottom: 42px;
        }


        .category-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 9px;
            margin-bottom: 24px;
        }


        .category-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 50px;
            background: #fff;
            color: #475569;
            font-size: 13px;
            font-weight: 700;
            transition: .25s ease;
        }


        .category-btn:hover {
            border-color: #bfdbfe;
            color: var(--primary);
            background: var(--primary-soft);
            transform: translateY(-1px);
        }


        .category-btn.active {
            border-color: var(--primary);
            color: #fff;
            background: var(--primary);
            box-shadow: 0 8px 20px rgba(37, 99, 235, .20);
        }


        .category-count {
            min-width: 22px;
            padding: 2px 6px;
            border-radius: 20px;
            background: rgba(15, 23, 42, .07);
            font-size: 10px;
            text-align: center;
        }


        .category-btn.active .category-count {
            background: rgba(255, 255, 255, .20);
        }


        .search-wrapper {
            position: relative;
            max-width: 520px;
            margin: 0 auto;
        }


        .search-wrapper i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
        }


        .gallery-search {
            width: 100%;
            height: 52px;
            padding: 0 48px;
            border: 1px solid var(--border);
            border-radius: 15px;
            outline: none;
            color: var(--text-dark);
            background: #fff;
            box-shadow: var(--shadow-sm);
            transition: .25s ease;
        }


        .gallery-search:focus {
            border-color: #93c5fd;
            box-shadow:
                0 0 0 4px rgba(37, 99, 235, .08),
                var(--shadow-sm);
        }


        .clear-search {
            position: absolute;
            right: 15px;
            top: 50%;
            width: 28px;
            height: 28px;
            padding: 0;
            transform: translateY(-50%);
            border: 0;
            border-radius: 50%;
            background: #f1f5f9;
            color: #64748b;
            display: none;
            align-items: center;
            justify-content: center;
        }


        .clear-search:hover {
            background: #e2e8f0;
        }


        .results-info {
            margin-top: 14px;
            color: #94a3b8;
            font-size: 13px;
            text-align: center;
        }


        /* =====================================================
           GALLERY GRID
        ===================================================== */

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }


        .gallery-card {
            position: relative;
            min-width: 0;
            overflow: hidden;
            border-radius: var(--radius-md);
            background: #e2e8f0;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition:
                transform .35s ease,
                box-shadow .35s ease,
                opacity .25s ease;
        }


        .gallery-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-md);
        }


        .gallery-card:nth-child(7n + 1),
        .gallery-card:nth-child(7n + 5) {
            grid-column: span 2;
        }


        .gallery-image {
            position: relative;
            width: 100%;
            aspect-ratio: 4 / 3;
            overflow: hidden;
            background: #e2e8f0;
        }


        .gallery-card:nth-child(7n + 1) .gallery-image,
        .gallery-card:nth-child(7n + 5) .gallery-image {
            aspect-ratio: 16 / 10;
        }


        .gallery-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .6s cubic-bezier(.2, .8, .2, 1);
        }


        .gallery-card:hover .gallery-image img {
            transform: scale(1.07);
        }


        .gallery-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 22px;
            background:
                linear-gradient(
                    to top,
                    rgba(15, 23, 42, .90),
                    rgba(15, 23, 42, .15) 60%,
                    transparent
                );
            opacity: 0;
            transition: .35s ease;
        }


        .gallery-card:hover .gallery-overlay {
            opacity: 1;
        }


        .gallery-category {
            align-self: flex-start;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 9px;
            padding: 6px 10px;
            border-radius: 30px;
            background: rgba(255, 255, 255, .14);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .04em;
            backdrop-filter: blur(10px);
        }


        .gallery-overlay h3 {
            margin: 0 0 5px;
            color: #fff;
            font-size: 17px;
            font-weight: 800;
        }


        .gallery-overlay p {
            display: -webkit-box;
            margin: 0;
            overflow: hidden;
            color: rgba(255, 255, 255, .75);
            font-size: 12px;
            line-height: 1.5;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }


        .gallery-view-icon {
            position: absolute;
            top: 17px;
            right: 17px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, .16);
            color: #fff;
            font-size: 18px;
            backdrop-filter: blur(10px);
            transform: translateY(-8px);
            transition: .35s ease;
        }


        .gallery-card:hover .gallery-view-icon {
            transform: translateY(0);
        }


        .gallery-date {
            position: absolute;
            left: 16px;
            top: 16px;
            padding: 6px 9px;
            border-radius: 8px;
            background: rgba(15, 23, 42, .62);
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            backdrop-filter: blur(8px);
        }


        .gallery-card.is-hidden {
            display: none;
        }


        /* =====================================================
           EMPTY / NO RESULTS
        ===================================================== */

        .gallery-empty {
            padding: 75px 25px;
            border: 1px dashed #cbd5e1;
            border-radius: var(--radius-lg);
            background: var(--surface-soft);
            text-align: center;
        }


        .gallery-empty-icon {
            width: 76px;
            height: 76px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 30px;
        }


        .gallery-empty h3 {
            margin-bottom: 8px;
            font-size: 21px;
            font-weight: 800;
        }


        .gallery-empty p {
            max-width: 480px;
            margin: 0 auto;
            color: var(--text-muted);
            line-height: 1.7;
        }


        /* =====================================================
           LIGHTBOX
        ===================================================== */

        .gallery-modal .modal-dialog {
            max-width: 1180px;
        }


        .gallery-modal .modal-content {
            overflow: hidden;
            border: 0;
            border-radius: 22px;
            background: #0f172a;
            box-shadow: 0 30px 100px rgba(0, 0, 0, .40);
        }


        .lightbox-image-area {
            position: relative;
            min-height: 520px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #020617;
        }


        .lightbox-image {
            display: block;
            max-width: 100%;
            max-height: 72vh;
            object-fit: contain;
        }


        .lightbox-close {
            position: absolute;
            top: 18px;
            right: 18px;
            z-index: 4;
            width: 44px;
            height: 44px;
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 50%;
            background: rgba(15, 23, 42, .70);
            color: #fff;
            font-size: 20px;
            backdrop-filter: blur(10px);
        }


        .lightbox-close:hover {
            background: rgba(15, 23, 42, .95);
        }


        .lightbox-nav {
            position: absolute;
            top: 50%;
            z-index: 4;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translateY(-50%);
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 50%;
            background: rgba(15, 23, 42, .65);
            color: #fff;
            font-size: 22px;
            backdrop-filter: blur(10px);
            transition: .25s ease;
        }


        .lightbox-nav:hover {
            background: var(--primary);
        }


        .lightbox-prev {
            left: 18px;
        }


        .lightbox-next {
            right: 18px;
        }


        .lightbox-info {
            padding: 24px 28px 26px;
            color: #fff;
            background: #0f172a;
        }


        .lightbox-category {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 10px;
            padding: 6px 10px;
            border-radius: 30px;
            background: rgba(37, 99, 235, .18);
            color: #93c5fd;
            font-size: 11px;
            font-weight: 700;
        }


        .lightbox-title {
            margin: 0 0 8px;
            font-size: 23px;
            font-weight: 800;
        }


        .lightbox-description {
            margin: 0 0 10px;
            color: rgba(255, 255, 255, .66);
            font-size: 14px;
            line-height: 1.7;
        }


        .lightbox-date {
            color: rgba(255, 255, 255, .40);
            font-size: 12px;
        }


        /* =====================================================
           FOOTER
        ===================================================== */

        .site-footer {
            padding: 70px 0 25px;
            background: #0f172a;
            color: #fff;
        }


        .footer-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }


        .footer-brand img {
            width: 54px;
            height: 54px;
            object-fit: contain;
        }


        .footer-brand div {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }


        .footer-brand strong {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 17px;
        }


        .footer-brand span {
            margin-top: 4px;
            color: #60a5fa;
            font-size: 13px;
            font-weight: 600;
        }


        .site-footer p {
            max-width: 430px;
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.8;
        }


        .site-footer h4 {
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 800;
        }


        .site-footer ul {
            padding: 0;
            margin: 0;
            list-style: none;
        }


        .site-footer li {
            margin-bottom: 11px;
        }


        .site-footer a {
            color: #94a3b8;
            font-size: 13px;
            transition: .25s ease;
        }


        .site-footer a:hover {
            color: #fff;
        }


        .social-links {
            display: flex;
            gap: 9px;
        }


        .social-links a {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, .10);
            border-radius: 10px;
            background: rgba(255, 255, 255, .04);
            color: #cbd5e1;
            font-size: 16px;
        }


        .social-links a:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }


        .footer-bottom {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-top: 50px;
            padding-top: 22px;
            border-top: 1px solid rgba(255, 255, 255, .08);
            color: #64748b;
            font-size: 12px;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 991.98px) {

            .navbar-collapse {
                padding: 15px 0 20px;
            }


            .nav-link {
                padding: 11px 0 !important;
            }


            .login-item {
                margin: 8px 0 0;
            }


            .btn-login {
                justify-content: center;
                width: 100%;
            }


            .gallery-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }


            .gallery-card:nth-child(7n + 1),
            .gallery-card:nth-child(7n + 5) {
                grid-column: span 2;
            }

        }


        @media (max-width: 767.98px) {

            .navbar {
                min-height: 72px;
            }


            .brand-logo {
                width: 45px;
                height: 45px;
            }


            .brand-name {
                font-size: 15px;
            }


            .brand-school {
                font-size: 11px;
            }


            .gallery-hero {
                min-height: 450px;
            }


            .gallery-hero-content {
                padding: 70px 0;
            }


            .gallery-hero h1 {
                font-size: 42px;
            }


            .gallery-hero p {
                font-size: 15px;
            }


            .hero-stats {
                gap: 8px;
            }


            .hero-stat {
                padding: 9px 12px;
            }


            .hero-stat span {
                display: none;
            }


            .gallery-section {
                padding: 65px 0 80px;
            }


            .section-heading {
                margin-bottom: 35px;
            }


            .category-list {
                justify-content: flex-start;
                overflow-x: auto;
                flex-wrap: nowrap;
                padding: 3px 2px 9px;
                scrollbar-width: none;
            }


            .category-list::-webkit-scrollbar {
                display: none;
            }


            .category-btn {
                flex: 0 0 auto;
            }


            .gallery-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }


            .gallery-card:nth-child(7n + 1),
            .gallery-card:nth-child(7n + 5) {
                grid-column: span 1;
            }


            .gallery-image,
            .gallery-card:nth-child(7n + 1) .gallery-image,
            .gallery-card:nth-child(7n + 5) .gallery-image {
                aspect-ratio: 4 / 3;
            }


            .gallery-overlay {
                opacity: 1;
                padding: 18px;
            }


            .lightbox-image-area {
                min-height: 350px;
            }


            .lightbox-nav {
                width: 42px;
                height: 42px;
            }


            .lightbox-prev {
                left: 10px;
            }


            .lightbox-next {
                right: 10px;
            }


            .lightbox-info {
                padding: 20px;
            }


            .footer-bottom {
                flex-direction: column;
                gap: 8px;
            }

        }


        @media (max-width: 420px) {

            .gallery-hero h1 {
                font-size: 36px;
            }


            .hero-stat i {
                font-size: 15px;
            }


            .hero-stat strong {
                font-size: 13px;
            }

        }

    </style>

</head>


<body>


<!-- =====================================================
     NAVBAR
===================================================== -->

<header class="site-header">

    <nav class="navbar navbar-expand-lg">

        <div class="container">

            <!-- Logo -->

            <a
                href="index.php"
                class="navbar-brand brand"
            >

                <img
                    src="public/image/logo.webp"
                    alt="Bole Kale Hiwot School Logo"
                    class="brand-logo"
                >

                <div class="brand-info">

                    <span class="brand-name">
                        Bole Kale Hiwot
                    </span>

                    <span class="brand-school">
                        School
                    </span>

                </div>

            </a>


            <!-- Mobile Menu -->

            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#mainNavbar"
                aria-controls="mainNavbar"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >

                <span class="navbar-toggler-icon"></span>

            </button>


            <!-- Navigation -->

            <div
                class="collapse navbar-collapse"
                id="mainNavbar"
            >

                <ul class="navbar-nav ms-auto align-items-lg-center">

                    <li class="nav-item">

                        <a
                            class="nav-link"
                            href="index.php"
                        >
                            Home
                        </a>

                    </li>


                    <li class="nav-item">

                        <a
                            class="nav-link"
                            href="about.php"
                        >
                            About
                        </a>

                    </li>


                    <li class="nav-item">

                        <a
                            class="nav-link"
                            href="admission.php"
                        >
                            Admission
                        </a>

                    </li>


                    <li class="nav-item">

                        <a
                            class="nav-link"
                            href="announcements.php"
                        >
                            Announcement
                        </a>

                    </li>


                    <li class="nav-item">

                        <a
                            class="nav-link active"
                            href="gallery.php"
                        >
                            Gallery
                        </a>

                    </li>


                    <li class="nav-item">

                        <a
                            class="nav-link"
                            href="contact.php"
                        >
                            Contact
                        </a>

                    </li>


                    <li class="nav-item login-item">

                        <a
                            href="auth/login.php"
                            class="btn btn-login"
                        >

                            <i class="bi bi-person-circle"></i>

                            <span>Login</span>

                        </a>

                    </li>

                </ul>

            </div>

        </div>

    </nav>

</header>



<!-- =====================================================
     HERO
===================================================== -->

<main>

<section class="gallery-hero">

    <?php if ($heroImage !== null): ?>

        <img
            src="<?= e($heroImage) ?>"
            alt=""
            class="gallery-hero-bg"
        >

    <?php endif; ?>


    <div class="gallery-hero-overlay"></div>

    <div class="hero-decoration"></div>

    <div class="hero-decoration-two"></div>


    <div class="container">

        <div class="gallery-hero-content">

            <span class="hero-badge">

                <span class="badge-dot"></span>

                School Life • BKHS

            </span>


            <h1>

                Our School
                <span>Gallery</span>

            </h1>


            <p>

                Explore moments of learning, creativity,
                community, achievement, and everyday school
                life at Bole Kale Hiwot School.

            </p>


            <div class="hero-stats">

                <div class="hero-stat">

                    <i class="bi bi-images"></i>

                    <div>

                        <strong>
                            <?= number_format($totalPhotos) ?>
                        </strong>

                        <span>Photos</span>

                    </div>

                </div>


                <div class="hero-stat">

                    <i class="bi bi-grid-3x3-gap"></i>

                    <div>

                        <strong>
                            <?= number_format($totalCategories) ?>
                        </strong>

                        <span>Categories</span>

                    </div>

                </div>


                <div class="hero-stat">

                    <i class="bi bi-camera"></i>

                    <div>

                        <strong>
                            BKHS
                        </strong>

                        <span>School Memories</span>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>



<!-- =====================================================
     GALLERY
===================================================== -->

<section
    class="gallery-section"
    id="school-gallery"
>

    <div class="container">


        <!-- Section Heading -->

        <div class="section-heading">

            <span class="section-label">
                SCHOOL LIFE
            </span>

            <h2>
                Moments worth
                <span>remembering</span>
            </h2>

            <p>
                Browse photos from our school events,
                learning spaces, activities, celebrations,
                and community life.
            </p>

        </div>



        <?php if ($databaseError !== ''): ?>

            <!-- Database Error -->

            <div class="gallery-empty">

                <div class="gallery-empty-icon">

                    <i class="bi bi-exclamation-triangle"></i>

                </div>

                <h3>
                    Gallery temporarily unavailable
                </h3>

                <p>
                    <?= e($databaseError) ?>
                    Please try again later.
                </p>

            </div>


        <?php elseif ($totalPhotos === 0): ?>

            <!-- No Photos -->

            <div class="gallery-empty">

                <div class="gallery-empty-icon">

                    <i class="bi bi-images"></i>

                </div>

                <h3>
                    Our gallery is coming soon
                </h3>

                <p>
                    We are preparing photos from school events,
                    classrooms, laboratories, activities, and
                    other memorable moments.
                </p>

            </div>


        <?php else: ?>


            <!-- =================================================
                 CONTROLS
            ================================================== -->

            <div class="gallery-controls">


                <!-- Categories -->

                <div class="category-list">

                    <!-- ALL -->

                    <button
                        type="button"
                        class="category-btn active"
                        data-category="all"
                    >

                        <i class="bi bi-grid"></i>

                        <span>All</span>

                        <span class="category-count">
                            <?= number_format($totalPhotos) ?>
                        </span>

                    </button>


                    <?php

                    $orderedCategories = [
                        'events',
                        'computer-lab',
                        'library',
                        'science-lab',
                        'classrooms',
                        'campus',
                        'sports',
                        'cultural',
                        'graduation',
                    ];

                    foreach ($orderedCategories as $category):

                        if (!isset($categoryCounts[$category])) {
                            continue;
                        }

                    ?>

                        <button
                            type="button"
                            class="category-btn"
                            data-category="<?= e($category) ?>"
                        >

                            <i class="bi <?= e(getCategoryIcon($category)) ?>"></i>

                            <span>
                                <?= e(getCategoryLabel($category)) ?>
                            </span>

                            <span class="category-count">
                                <?= number_format($categoryCounts[$category]) ?>
                            </span>

                        </button>

                    <?php endforeach; ?>


                    <!-- Other categories, if admin adds one later -->

                    <?php

                    foreach ($categoryCounts as $category => $count):

                        if (
                            in_array(
                                $category,
                                $orderedCategories,
                                true
                            )
                        ) {
                            continue;
                        }

                    ?>

                        <button
                            type="button"
                            class="category-btn"
                            data-category="<?= e($category) ?>"
                        >

                            <i class="bi <?= e(getCategoryIcon($category)) ?>"></i>

                            <span>
                                <?= e(getCategoryLabel($category)) ?>
                            </span>

                            <span class="category-count">
                                <?= number_format($count) ?>
                            </span>

                        </button>

                    <?php endforeach; ?>

                </div>



                <!-- Search -->

                <div class="search-wrapper">

                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        id="gallerySearch"
                        class="gallery-search"
                        placeholder="Search photos by title or description..."
                        autocomplete="off"
                    >

                    <button
                        type="button"
                        id="clearSearch"
                        class="clear-search"
                        aria-label="Clear search"
                    >

                        <i class="bi bi-x"></i>

                    </button>

                </div>


                <div
                    class="results-info"
                    id="resultsInfo"
                >
                    Showing
                    <?= number_format($totalPhotos) ?>
                    photos
                </div>

            </div>



            <!-- =================================================
                 GALLERY GRID
            ================================================== -->

            <div
                class="gallery-grid"
                id="galleryGrid"
            >

                <?php foreach ($galleryItems as $index => $item): ?>

                    <article
                        class="gallery-card"
                        data-index="<?= $index ?>"
                        data-category="<?= e($item['category']) ?>"
                        data-search="<?= e(
                            strtolower(
                                $item['title'] . ' ' .
                                $item['description'] . ' ' .
                                $item['categoryLabel']
                            )
                        ) ?>"
                        tabindex="0"
                        role="button"
                        aria-label="View <?= e($item['title']) ?>"
                    >

                        <div class="gallery-image">

                            <img
                                src="<?= e($item['image']) ?>"
                                alt="<?= e($item['title']) ?>"
                                loading="lazy"
                                onerror="this.onerror=null;this.src='public/logo.webp';"
                            >


                            <?php if ($item['date'] !== ''): ?>

                                <div class="gallery-date">

                                    <i class="bi bi-calendar3 me-1"></i>

                                    <?= e($item['date']) ?>

                                </div>

                            <?php endif; ?>


                            <div class="gallery-view-icon">

                                <i class="bi bi-arrows-fullscreen"></i>

                            </div>


                            <div class="gallery-overlay">

                                <span class="gallery-category">

                                    <i class="bi <?= e($item['categoryIcon']) ?>"></i>

                                    <?= e($item['categoryLabel']) ?>

                                </span>


                                <h3>
                                    <?= e($item['title']) ?>
                                </h3>


                                <?php if ($item['description'] !== ''): ?>

                                    <p>
                                        <?= e($item['description']) ?>
                                    </p>

                                <?php endif; ?>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>



            <!-- =================================================
                 NO FILTER RESULTS
            ================================================== -->

            <div
                id="noFilterResults"
                class="gallery-empty mt-4"
                style="display:none;"
            >

                <div class="gallery-empty-icon">

                    <i class="bi bi-search"></i>

                </div>

                <h3>
                    No photos found
                </h3>

                <p>
                    We couldn't find any photos matching your
                    search. Try another keyword or category.
                </p>

            </div>


        <?php endif; ?>

    </div>

</section>

</main>



<!-- =====================================================
     LIGHTBOX
===================================================== -->

<div
    class="modal fade gallery-modal"
    id="galleryModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered modal-xl">

        <div class="modal-content">


            <!-- Image -->

            <div class="lightbox-image-area">

                <button
                    type="button"
                    class="lightbox-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                >

                    <i class="bi bi-x-lg"></i>

                </button>


                <button
                    type="button"
                    class="lightbox-nav lightbox-prev"
                    id="lightboxPrev"
                    aria-label="Previous photo"
                >

                    <i class="bi bi-chevron-left"></i>

                </button>


                <img
                    src=""
                    alt=""
                    class="lightbox-image"
                    id="lightboxImage"
                >


                <button
                    type="button"
                    class="lightbox-nav lightbox-next"
                    id="lightboxNext"
                    aria-label="Next photo"
                >

                    <i class="bi bi-chevron-right"></i>

                </button>

            </div>



            <!-- Information -->

            <div class="lightbox-info">

                <span
                    class="lightbox-category"
                    id="lightboxCategory"
                >

                    <i class="bi bi-image"></i>

                    Gallery

                </span>


                <h3
                    class="lightbox-title"
                    id="lightboxTitle"
                >
                    Photo
                </h3>


                <p
                    class="lightbox-description"
                    id="lightboxDescription"
                ></p>


                <div
                    class="lightbox-date"
                    id="lightboxDate"
                ></div>

            </div>

        </div>

    </div>

</div>



<!-- =====================================================
     FOOTER
===================================================== -->

<footer class="site-footer">

    <div class="container">

        <div class="row g-5">

            <div class="col-lg-5">

                <div class="footer-brand">

                    <img
                        src="public/image/logo.webp"
                        alt="Bole Kale Hiwot School Logo"
                    >

                    <div>

                        <strong>
                            Bole Kale Hiwot
                        </strong>

                        <span>
                            School
                        </span>

                    </div>

                </div>


                <p>
                    Building knowledge, character, creativity,
                    and confidence for a brighter future.
                </p>

            </div>


            <div class="col-6 col-lg-2">

                <h4>
                    Quick Links
                </h4>

                <ul>

                    <li>
                        <a href="index.php">
                            Home
                        </a>
                    </li>

                    <li>
                        <a href="about.php">
                            About
                        </a>
                    </li>

                    <li>
                        <a href="admission.php">
                            Admission
                        </a>
                    </li>

                    <li>
                        <a href="gallery.php">
                            Gallery
                        </a>
                    </li>

                </ul>

            </div>


            <div class="col-6 col-lg-2">

                <h4>
                    Information
                </h4>

                <ul>

                    <li>
                        <a href="announcements.php">
                            Announcements
                        </a>
                    </li>

                    <li>
                        <a href="contact.php">
                            Contact
                        </a>
                    </li>

                    <li>
                        <a href="auth/login.php">
                            Login
                        </a>
                    </li>

                </ul>

            </div>


            <div class="col-lg-3">

                <h4>
                    Connect With Us
                </h4>

                <div class="social-links">

                    <a
                        href="#"
                        aria-label="Facebook"
                    >
                        <i class="bi bi-facebook"></i>
                    </a>


                    <a
                        href="#"
                        aria-label="Telegram"
                    >
                        <i class="bi bi-telegram"></i>
                    </a>


                    <a
                        href="#"
                        aria-label="Instagram"
                    >
                        <i class="bi bi-instagram"></i>
                    </a>


                    <a
                        href="#"
                        aria-label="YouTube"
                    >
                        <i class="bi bi-youtube"></i>
                    </a>

                </div>

            </div>

        </div>


        <div class="footer-bottom">

            <span>

                © <?= date('Y') ?>
                Bole Kale Hiwot School.
                All rights reserved.

            </span>


            <span>

                BKHS School Management System

            </span>

        </div>

    </div>

</footer>



<!-- =====================================================
     BOOTSTRAP JS
===================================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>



<!-- =====================================================
     GALLERY JAVASCRIPT
===================================================== -->

<script>

    /*
    |--------------------------------------------------------------------------
    | Gallery Data From PHP
    |--------------------------------------------------------------------------
    */

    const galleryItems = <?= $galleryJson ?>;


    /*
    |--------------------------------------------------------------------------
    | Elements
    |--------------------------------------------------------------------------
    */

    const galleryCards = Array.from(
        document.querySelectorAll('.gallery-card')
    );

    const categoryButtons = Array.from(
        document.querySelectorAll('.category-btn')
    );

    const searchInput = document.getElementById(
        'gallerySearch'
    );

    const clearSearchButton = document.getElementById(
        'clearSearch'
    );

    const resultsInfo = document.getElementById(
        'resultsInfo'
    );

    const noFilterResults = document.getElementById(
        'noFilterResults'
    );


    /*
    |--------------------------------------------------------------------------
    | Current Filter
    |--------------------------------------------------------------------------
    */

    let currentCategory = 'all';


    /*
    |--------------------------------------------------------------------------
    | Lightbox
    |--------------------------------------------------------------------------
    */

    const galleryModalElement = document.getElementById(
        'galleryModal'
    );

    const galleryModal = galleryModalElement
        ? new bootstrap.Modal(galleryModalElement)
        : null;


    const lightboxImage = document.getElementById(
        'lightboxImage'
    );

    const lightboxTitle = document.getElementById(
        'lightboxTitle'
    );

    const lightboxDescription = document.getElementById(
        'lightboxDescription'
    );

    const lightboxCategory = document.getElementById(
        'lightboxCategory'
    );

    const lightboxDate = document.getElementById(
        'lightboxDate'
    );

    const lightboxPrev = document.getElementById(
        'lightboxPrev'
    );

    const lightboxNext = document.getElementById(
        'lightboxNext'
    );


    let currentLightboxIndex = 0;


    /*
    |--------------------------------------------------------------------------
    | Get Visible Gallery Indexes
    |--------------------------------------------------------------------------
    */

    function getVisibleIndexes() {

        return galleryCards
            .filter(card => !card.classList.contains('is-hidden'))
            .map(card => Number(card.dataset.index));

    }


    /*
    |--------------------------------------------------------------------------
    | Apply Filters
    |--------------------------------------------------------------------------
    */

    function applyFilters() {

        const searchTerm = searchInput
            ? searchInput.value
                .trim()
                .toLowerCase()
            : '';

        let visibleCount = 0;


        galleryCards.forEach(card => {

            const category = card.dataset.category || '';

            const searchableText =
                card.dataset.search || '';


            const categoryMatches =
                currentCategory === 'all' ||
                category === currentCategory;


            const searchMatches =
                searchTerm === '' ||
                searchableText.includes(searchTerm);


            const shouldShow =
                categoryMatches &&
                searchMatches;


            if (shouldShow) {

                card.classList.remove('is-hidden');

                visibleCount++;

            } else {

                card.classList.add('is-hidden');

            }

        });


        /*
         * Search clear button
         */

        if (clearSearchButton && searchInput) {

            clearSearchButton.style.display =
                searchInput.value.trim() !== ''
                    ? 'flex'
                    : 'none';

        }


        /*
         * Results information
         */

        if (resultsInfo) {

            resultsInfo.textContent =
                `Showing ${visibleCount.toLocaleString()} ${
                    visibleCount === 1
                        ? 'photo'
                        : 'photos'
                }`;

        }


        /*
         * Empty result state
         */

        if (noFilterResults) {

            noFilterResults.style.display =
                visibleCount === 0
                    ? 'block'
                    : 'none';

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Category Buttons
    |--------------------------------------------------------------------------
    */

    categoryButtons.forEach(button => {

        button.addEventListener(
            'click',
            function () {

                categoryButtons.forEach(
                    item => item.classList.remove('active')
                );


                this.classList.add('active');


                currentCategory =
                    this.dataset.category || 'all';


                applyFilters();

            }
        );

    });


    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    if (searchInput) {

        searchInput.addEventListener(
            'input',
            applyFilters
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Clear Search
    |--------------------------------------------------------------------------
    */

    if (clearSearchButton) {

        clearSearchButton.addEventListener(
            'click',
            function () {

                if (!searchInput) {
                    return;
                }


                searchInput.value = '';

                searchInput.focus();

                applyFilters();

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Open Lightbox
    |--------------------------------------------------------------------------
    */

    function openLightbox(index) {

        if (
            !galleryItems[index] ||
            !galleryModal
        ) {
            return;
        }


        currentLightboxIndex = index;


        updateLightbox();


        galleryModal.show();

    }


    /*
    |--------------------------------------------------------------------------
    | Update Lightbox
    |--------------------------------------------------------------------------
    */

    function updateLightbox() {

        const item =
            galleryItems[currentLightboxIndex];


        if (!item) {
            return;
        }


        lightboxImage.src = item.image;

        lightboxImage.alt = item.title;


        lightboxTitle.textContent =
            item.title || 'School Gallery';


        lightboxDescription.textContent =
            item.description || '';


        lightboxCategory.innerHTML = `
            <i class="bi ${item.categoryIcon}"></i>
            ${escapeHtml(item.categoryLabel)}
        `;


        lightboxDate.textContent =
            item.date
                ? `Added ${item.date}`
                : '';


        updateLightboxNavigation();

    }


    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    function updateLightboxNavigation() {

        const visibleIndexes =
            getVisibleIndexes();


        /*
         * If current item is not visible because
         * the filter changed, fall back to the
         * first visible item.
         */

        if (
            visibleIndexes.length > 0 &&
            !visibleIndexes.includes(
                currentLightboxIndex
            )
        ) {

            currentLightboxIndex =
                visibleIndexes[0];

        }


        if (visibleIndexes.length <= 1) {

            lightboxPrev.style.display = 'none';

            lightboxNext.style.display = 'none';

            return;
        }


        lightboxPrev.style.display = 'flex';

        lightboxNext.style.display = 'flex';

    }


    /*
    |--------------------------------------------------------------------------
    | Previous
    |--------------------------------------------------------------------------
    */

    function showPrevious() {

        const visibleIndexes =
            getVisibleIndexes();


        if (visibleIndexes.length <= 1) {
            return;
        }


        let position =
            visibleIndexes.indexOf(
                currentLightboxIndex
            );


        if (position === -1) {
            position = 0;
        }


        position =
            (position - 1 + visibleIndexes.length) %
            visibleIndexes.length;


        currentLightboxIndex =
            visibleIndexes[position];


        updateLightbox();

    }


    /*
    |--------------------------------------------------------------------------
    | Next
    |--------------------------------------------------------------------------
    */

    function showNext() {

        const visibleIndexes =
            getVisibleIndexes();


        if (visibleIndexes.length <= 1) {
            return;
        }


        let position =
            visibleIndexes.indexOf(
                currentLightboxIndex
            );


        if (position === -1) {
            position = 0;
        }


        position =
            (position + 1) %
            visibleIndexes.length;


        currentLightboxIndex =
            visibleIndexes[position];


        updateLightbox();

    }


    /*
    |--------------------------------------------------------------------------
    | Lightbox Buttons
    |--------------------------------------------------------------------------
    */

    if (lightboxPrev) {

        lightboxPrev.addEventListener(
            'click',
            showPrevious
        );

    }


    if (lightboxNext) {

        lightboxNext.addEventListener(
            'click',
            showNext
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Gallery Card Click
    |--------------------------------------------------------------------------
    */

    galleryCards.forEach(card => {

        card.addEventListener(
            'click',
            function () {

                const index =
                    Number(this.dataset.index);


                openLightbox(index);

            }
        );


        /*
         * Keyboard accessibility
         */

        card.addEventListener(
            'keydown',
            function (event) {

                if (
                    event.key === 'Enter' ||
                    event.key === ' '
                ) {

                    event.preventDefault();

                    const index =
                        Number(this.dataset.index);


                    openLightbox(index);

                }

            }
        );

    });


    /*
    |--------------------------------------------------------------------------
    | Keyboard Navigation
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                !galleryModalElement ||
                !galleryModalElement.classList.contains('show')
            ) {
                return;
            }


            if (event.key === 'ArrowLeft') {

                showPrevious();

            }


            if (event.key === 'ArrowRight') {

                showNext();

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Escape HTML
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    }


    /*
    |--------------------------------------------------------------------------
    | Initial Filter
    |--------------------------------------------------------------------------
    */

    applyFilters();

</script>


</body>

</html>