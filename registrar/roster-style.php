<?php
/*
|--------------------------------------------------------------------------
| Roster Page Styles
|--------------------------------------------------------------------------
| Bole Kale Hiwot School - Registrar Roster
|--------------------------------------------------------------------------
*/
?>

<style>

/* ==========================================================================
   ROOT VARIABLES
   ========================================================================== */

:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --primary-light: #eff6ff;

    --sidebar: #111827;
    --sidebar-hover: #1f2937;
    --sidebar-active: #2563eb;

    --background: #f5f7fb;
    --card: #ffffff;

    --text: #111827;
    --text-secondary: #374151;
    --muted: #6b7280;

    --border: #e5e7eb;
    --border-dark: #d1d5db;

    --success: #16a34a;
    --success-light: #f0fdf4;

    --warning: #d97706;
    --warning-light: #fffbeb;

    --danger: #dc2626;
    --danger-light: #fef2f2;

    --info: #0284c7;
    --info-light: #f0f9ff;

    --sidebar-width: 260px;
    --topbar-height: 78px;

    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;

    --shadow-sm:
        0 1px 2px rgba(0, 0, 0, 0.04);

    --shadow-md:
        0 4px 12px rgba(0, 0, 0, 0.06);

    --transition:
        all 0.2s ease;
}


/* ==========================================================================
   GLOBAL
   ========================================================================== */

* {
    box-sizing: border-box;
}

html {
    min-height: 100%;
}

body {
    margin: 0;
    min-height: 100vh;

    background: var(--background);
    color: var(--text);

    font-family:
        "Inter",
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        Helvetica,
        Arial,
        sans-serif;

    font-size: 14px;
    line-height: 1.5;
}


/* ==========================================================================
   MAIN LAYOUT
   ========================================================================== */

.app-wrapper {
    min-height: 100vh;
}


/* ==========================================================================
   SIDEBAR
   ========================================================================== */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;

    width: var(--sidebar-width);
    height: 100vh;

    background: var(--sidebar);
    color: #ffffff;

    z-index: 1050;

    display: flex;
    flex-direction: column;

    overflow-y: auto;
    overflow-x: hidden;

    transition: transform 0.25s ease;
}


/* --------------------------------------------------------------------------
   Sidebar scrollbar
   -------------------------------------------------------------------------- */

.sidebar::-webkit-scrollbar {
    width: 5px;
}

.sidebar::-webkit-scrollbar-track {
    background: var(--sidebar);
}

.sidebar::-webkit-scrollbar-thumb {
    background: #374151;
    border-radius: 10px;
}


/* --------------------------------------------------------------------------
   Sidebar brand
   -------------------------------------------------------------------------- */

.sidebar-brand {
    min-height: var(--topbar-height);

    padding: 18px 20px;

    display: flex;
    align-items: center;

    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.sidebar-brand a {
    display: flex;
    align-items: center;
    gap: 12px;

    width: 100%;

    color: #ffffff;
    text-decoration: none;
}

.sidebar-brand-logo {
    width: 40px;
    height: 40px;

    border-radius: 10px;

    background: var(--primary);

    display: flex;
    align-items: center;
    justify-content: center;

    flex-shrink: 0;

    font-size: 19px;
    font-weight: 700;
}

.sidebar-brand-text {
    min-width: 0;
}

.sidebar-brand-title {
    font-size: 15px;
    font-weight: 700;

    line-height: 1.2;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-brand-subtitle {
    margin-top: 3px;

    color: #9ca3af;

    font-size: 11px;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}


/* --------------------------------------------------------------------------
   Sidebar navigation
   -------------------------------------------------------------------------- */

.sidebar-nav {
    flex: 1;

    padding: 18px 12px 20px;
}

.sidebar-section {
    margin-bottom: 22px;
}

.sidebar-section-title {
    padding: 0 12px;
    margin-bottom: 8px;

    color: #6b7280;

    font-size: 10px;
    font-weight: 700;

    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.sidebar-menu {
    list-style: none;

    margin: 0;
    padding: 0;
}

.sidebar-menu-item {
    margin-bottom: 3px;
}

.sidebar-link {
    position: relative;

    display: flex;
    align-items: center;

    gap: 12px;

    min-height: 44px;

    padding: 10px 12px;

    border-radius: 9px;

    color: #d1d5db;

    text-decoration: none;

    font-size: 13px;
    font-weight: 500;

    transition: var(--transition);
}

.sidebar-link:hover {
    color: #ffffff;
    background: var(--sidebar-hover);
}

.sidebar-link.active {
    color: #ffffff;
    background: var(--sidebar-active);
}

.sidebar-link i {
    width: 20px;

    font-size: 17px;

    text-align: center;

    flex-shrink: 0;
}

.sidebar-link span {
    white-space: nowrap;
}

.sidebar-link.active::before {
    content: "";

    position: absolute;

    left: -12px;

    top: 7px;
    bottom: 7px;

    width: 3px;

    border-radius: 0 4px 4px 0;

    background: #ffffff;
}


/* --------------------------------------------------------------------------
   Sidebar footer
   -------------------------------------------------------------------------- */

.sidebar-footer {
    padding: 12px;

    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.sidebar-user {
    display: flex;
    align-items: center;

    gap: 10px;

    padding: 10px;

    border-radius: 10px;

    background: rgba(255, 255, 255, 0.04);
}

.sidebar-user-avatar {
    width: 34px;
    height: 34px;

    border-radius: 50%;

    object-fit: cover;

    flex-shrink: 0;

    background: #374151;
}

.sidebar-user-info {
    min-width: 0;
}

.sidebar-user-name {
    color: #ffffff;

    font-size: 12px;
    font-weight: 600;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-user-role {
    margin-top: 2px;

    color: #9ca3af;

    font-size: 10px;

    text-transform: capitalize;
}


/* ==========================================================================
   SIDEBAR OVERLAY
   ========================================================================== */

.sidebar-overlay {
    display: none;

    position: fixed;

    inset: 0;

    z-index: 1040;

    background: rgba(0, 0, 0, 0.45);
}

.sidebar-overlay.show {
    display: block;
}


/* ==========================================================================
   MAIN CONTENT
   ========================================================================== */

.main-content {
    min-height: 100vh;

    margin-left: var(--sidebar-width);
}


/* ==========================================================================
   TOPBAR
   ========================================================================== */

.topbar {
    position: sticky;

    top: 0;

    height: var(--topbar-height);

    z-index: 1000;

    background: rgba(255, 255, 255, 0.97);

    border-bottom: 1px solid var(--border);

    backdrop-filter: blur(8px);

    display: flex;
    align-items: center;

    justify-content: space-between;

    padding: 0 28px;
}

.topbar-left {
    display: flex;
    align-items: center;

    gap: 14px;

    min-width: 0;
}

.topbar-title-wrapper {
    min-width: 0;
}

.topbar-title {
    margin: 0;

    font-size: 20px;
    font-weight: 700;

    color: var(--text);

    line-height: 1.2;
}

.topbar-subtitle {
    margin-top: 4px;

    color: var(--muted);

    font-size: 12px;
}

.topbar-right {
    display: flex;
    align-items: center;

    gap: 14px;
}


/* --------------------------------------------------------------------------
   Mobile menu button
   -------------------------------------------------------------------------- */

.sidebar-toggle {
    display: none;

    width: 40px;
    height: 40px;

    padding: 0;

    border: 1px solid var(--border);

    border-radius: 9px;

    background: #ffffff;

    color: var(--text);

    align-items: center;
    justify-content: center;

    cursor: pointer;

    transition: var(--transition);
}

.sidebar-toggle:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-light);
}

.sidebar-toggle i {
    font-size: 20px;
}


/* --------------------------------------------------------------------------
   Topbar user
   -------------------------------------------------------------------------- */

.topbar-user {
    display: flex;
    align-items: center;

    gap: 10px;
}

.topbar-user-info {
    text-align: right;
}

.topbar-user-name {
    color: var(--text);

    font-size: 13px;
    font-weight: 600;

    line-height: 1.2;
}

.topbar-user-role {
    margin-top: 3px;

    color: var(--muted);

    font-size: 11px;

    text-transform: capitalize;
}

.topbar-user-avatar {
    width: 42px;
    height: 42px;

    border-radius: 50%;

    object-fit: cover;

    border: 2px solid #ffffff;

    box-shadow:
        0 0 0 1px var(--border);

    background: #e5e7eb;
}


/* ==========================================================================
   PAGE CONTENT
   ========================================================================== */

.page-content {
    padding: 28px;
}


/* ==========================================================================
   PAGE HEADER
   ========================================================================== */

.page-header {
    display: flex;

    align-items: flex-start;
    justify-content: space-between;

    gap: 20px;

    margin-bottom: 24px;
}

.page-header-left {
    min-width: 0;
}

.page-title {
    margin: 0;

    color: var(--text);

    font-size: 24px;
    font-weight: 700;

    line-height: 1.25;
}

.page-description {
    margin: 7px 0 0;

    color: var(--muted);

    font-size: 13px;
}

.page-header-actions {
    display: flex;
    align-items: center;

    gap: 8px;

    flex-shrink: 0;
}


/* ==========================================================================
   CARDS
   ========================================================================== */

.card {
    border: 1px solid var(--border);

    border-radius: var(--radius-lg);

    background: var(--card);

    box-shadow: var(--shadow-sm);

    transition: var(--transition);
}

.card:hover {
    box-shadow: var(--shadow-md);
}

.card-header {
    padding: 18px 20px;

    background: #ffffff;

    border-bottom: 1px solid var(--border);

    border-radius:
        var(--radius-lg)
        var(--radius-lg)
        0
        0;
}

.card-body {
    padding: 20px;
}

.card-title {
    margin: 0;

    color: var(--text);

    font-size: 15px;
    font-weight: 700;
}

.card-subtitle {
    margin: 5px 0 0;

    color: var(--muted);

    font-size: 12px;
}


/* ==========================================================================
   ROSTER FILTER CARD
   ========================================================================== */

.roster-filter-card {
    margin-bottom: 22px;
}

.roster-filter-card .card-body {
    padding: 22px;
}

.roster-filter-grid {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 16px;

    align-items: end;
}

.filter-group {
    min-width: 0;
}

.filter-label {
    display: block;

    margin-bottom: 7px;

    color: var(--text-secondary);

    font-size: 12px;
    font-weight: 600;
}

.filter-label .required {
    color: var(--danger);
}


/* --------------------------------------------------------------------------
   Inputs
   -------------------------------------------------------------------------- */

.form-select,
.form-control {
    min-height: 42px;

    border: 1px solid var(--border-dark);

    border-radius: 9px;

    color: var(--text);

    background-color: #ffffff;

    font-size: 13px;

    transition: var(--transition);
}

.form-select {
    padding-left: 12px;
    padding-right: 36px;
}

.form-control {
    padding: 9px 12px;
}

.form-select:hover,
.form-control:hover {
    border-color: #9ca3af;
}

.form-select:focus,
.form-control:focus {
    border-color: var(--primary);

    box-shadow:
        0 0 0 3px rgba(37, 99, 235, 0.10);

    outline: none;
}


/* ==========================================================================
   BUTTONS
   ========================================================================== */

.btn {
    min-height: 40px;

    border-radius: 8px;

    font-size: 13px;
    font-weight: 600;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    gap: 7px;

    transition: var(--transition);
}

.btn-primary {
    background: var(--primary);

    border-color: var(--primary);

    color: #ffffff;
}

.btn-primary:hover,
.btn-primary:focus {
    background: var(--primary-dark);

    border-color: var(--primary-dark);

    color: #ffffff;

    transform: translateY(-1px);
}

.btn-outline-primary {
    color: var(--primary);

    border-color: #bfdbfe;

    background: #ffffff;
}

.btn-outline-primary:hover {
    color: #ffffff;

    border-color: var(--primary);

    background: var(--primary);
}

.btn-light {
    background: #ffffff;

    border-color: var(--border);

    color: var(--text-secondary);
}

.btn-light:hover {
    background: #f9fafb;

    border-color: var(--border-dark);

    color: var(--text);
}

.btn-success {
    background: var(--success);

    border-color: var(--success);

    color: #ffffff;
}

.btn-danger {
    background: var(--danger);

    border-color: var(--danger);

    color: #ffffff;
}

.btn-sm {
    min-height: 34px;

    padding: 6px 11px;

    font-size: 12px;
}


/* ==========================================================================
   ROSTER STATUS
   ========================================================================== */

.roster-status {
    display: flex;

    align-items: center;

    gap: 10px;

    padding: 13px 15px;

    margin-bottom: 20px;

    border: 1px solid var(--border);

    border-radius: 10px;

    background: #ffffff;

    color: var(--text-secondary);

    font-size: 13px;
}

.roster-status i {
    font-size: 17px;
}

.roster-status.success {
    color: #166534;

    background: var(--success-light);

    border-color: #bbf7d0;
}

.roster-status.warning {
    color: #92400e;

    background: var(--warning-light);

    border-color: #fde68a;
}

.roster-status.danger {
    color: #991b1b;

    background: var(--danger-light);

    border-color: #fecaca;
}

.roster-status.info {
    color: #075985;

    background: var(--info-light);

    border-color: #bae6fd;
}


/* ==========================================================================
   ROSTER RESULT CARD
   ========================================================================== */

.roster-result-card {
    overflow: hidden;
}

.roster-result-header {
    padding: 20px;

    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 15px;

    border-bottom: 1px solid var(--border);

    background: #ffffff;
}

.roster-result-heading {
    min-width: 0;
}

.roster-result-title {
    margin: 0;

    color: var(--text);

    font-size: 17px;
    font-weight: 700;
}

.roster-result-meta {
    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 7px;

    margin-top: 7px;

    color: var(--muted);

    font-size: 12px;
}

.roster-result-meta-item {
    display: inline-flex;

    align-items: center;

    gap: 5px;
}

.roster-result-meta-item i {
    color: var(--primary);

    font-size: 13px;
}

.roster-result-actions {
    display: flex;

    align-items: center;

    gap: 8px;

    flex-shrink: 0;
}


/* ==========================================================================
   TABLE CONTAINER
   ========================================================================== */

.roster-table-wrapper {
    width: 100%;

    overflow-x: auto;

    overflow-y: visible;

    -webkit-overflow-scrolling: touch;
}

.roster-table-wrapper::-webkit-scrollbar {
    height: 8px;
}

.roster-table-wrapper::-webkit-scrollbar-track {
    background: #f3f4f6;
}

.roster-table-wrapper::-webkit-scrollbar-thumb {
    background: #cbd5e1;

    border-radius: 10px;
}


/* ==========================================================================
   ROSTER TABLE
   ========================================================================== */

.roster-table {
    width: 100%;

    min-width: 950px;

    margin: 0;

    border-collapse: separate;

    border-spacing: 0;

    font-size: 12px;
}

.roster-table th {
    position: sticky;

    top: var(--topbar-height);

    z-index: 10;

    padding: 12px 10px;

    border-bottom: 1px solid var(--border-dark);

    border-right: 1px solid var(--border);

    background: #f8fafc;

    color: var(--text-secondary);

    font-size: 11px;
    font-weight: 700;

    text-align: center;

    vertical-align: middle;

    white-space: nowrap;
}

.roster-table th:first-child {
    border-left: 0;
}

.roster-table td {
    padding: 10px;

    border-bottom: 1px solid var(--border);

    border-right: 1px solid var(--border);

    background: #ffffff;

    color: var(--text-secondary);

    vertical-align: middle;
}

.roster-table tbody tr:last-child td {
    border-bottom: 0;
}

.roster-table tbody tr:hover td {
    background: #f8fafc;
}


/* --------------------------------------------------------------------------
   Sticky first columns
   -------------------------------------------------------------------------- */

.roster-table .student-code-column {
    position: sticky;

    left: 0;

    z-index: 7;

    background: #ffffff;

    min-width: 145px;
}

.roster-table th.student-code-column {
    z-index: 15;

    background: #f8fafc;
}

.roster-table .student-name-column {
    position: sticky;

    left: 145px;

    z-index: 7;

    background: #ffffff;

    min-width: 180px;
}

.roster-table th.student-name-column {
    z-index: 15;

    background: #f8fafc;
}

.roster-table tbody tr:hover .student-code-column,
.roster-table tbody tr:hover .student-name-column {
    background: #f8fafc;
}


/* --------------------------------------------------------------------------
   Row number
   -------------------------------------------------------------------------- */

.roster-table .row-number {
    width: 50px;

    min-width: 50px;

    text-align: center;

    color: var(--muted);

    font-weight: 600;
}


/* --------------------------------------------------------------------------
   Student code
   -------------------------------------------------------------------------- */

.roster-table .student-code {
    color: var(--primary);

    font-weight: 600;

    white-space: nowrap;
}


/* --------------------------------------------------------------------------
   Student name
   -------------------------------------------------------------------------- */

.roster-table .student-name {
    color: var(--text);

    font-weight: 600;

    white-space: nowrap;
}


/* --------------------------------------------------------------------------
   Subject marks
   -------------------------------------------------------------------------- */

.roster-table .subject-mark {
    min-width: 75px;

    text-align: center;

    font-variant-numeric: tabular-nums;
}

.roster-table .subject-mark.empty {
    color: #9ca3af;
}


/* --------------------------------------------------------------------------
   Sum
   -------------------------------------------------------------------------- */

.roster-table .sum-column {
    min-width: 80px;

    text-align: center;

    color: var(--text);

    font-weight: 700;

    background: #f8fafc;
}


/* --------------------------------------------------------------------------
   Average
   -------------------------------------------------------------------------- */

.roster-table .average-column {
    min-width: 85px;

    text-align: center;

    color: var(--primary);

    font-weight: 700;

    background: #f8fafc;
}


/* --------------------------------------------------------------------------
   Rank
   -------------------------------------------------------------------------- */

.roster-table .rank-column {
    min-width: 65px;

    text-align: center;

    font-weight: 700;
}

.rank-badge {
    display: inline-flex;

    align-items: center;
    justify-content: center;

    min-width: 30px;
    min-height: 26px;

    padding: 4px 8px;

    border-radius: 7px;

    background: var(--primary-light);

    color: var(--primary);

    font-size: 11px;
    font-weight: 700;
}


/* ==========================================================================
   ANNUAL ROSTER
   ========================================================================== */

.annual-roster-table {
    min-width: 1050px;
}

.annual-roster-table .semester-column {
    min-width: 125px;

    text-align: center;

    white-space: nowrap;

    font-weight: 600;
}


/* --------------------------------------------------------------------------
   First semester row
   -------------------------------------------------------------------------- */

.annual-roster-table tbody tr.first-semester-row td {
    background: #ffffff;
}


/* --------------------------------------------------------------------------
   Second semester row
   -------------------------------------------------------------------------- */

.annual-roster-table tbody tr.second-semester-row td {
    background: #fafafa;
}


/* --------------------------------------------------------------------------
   Annual average row
   -------------------------------------------------------------------------- */

.annual-roster-table tbody tr.annual-average-row td {
    background: #eff6ff;

    color: var(--text);

    font-weight: 700;

    border-bottom: 2px solid #bfdbfe;
}

.annual-roster-table tbody tr.annual-average-row .average-column {
    color: var(--primary);

    background: #dbeafe;
}

.annual-roster-table tbody tr.annual-average-row .sum-column {
    background: #dbeafe;
}


/* --------------------------------------------------------------------------
   Student group separation
   -------------------------------------------------------------------------- */

.annual-roster-table tbody tr.student-group-start td {
    border-top: 2px solid var(--border-dark);
}

.annual-roster-table tbody tr.student-group-end td {
    border-bottom: 2px solid var(--border-dark);
}


/* ==========================================================================
   SEMESTER BADGES
   ========================================================================== */

.semester-badge {
    display: inline-flex;

    align-items: center;
    justify-content: center;

    min-width: 100px;

    padding: 5px 9px;

    border-radius: 7px;

    font-size: 11px;
    font-weight: 700;

    white-space: nowrap;
}

.semester-badge.first {
    color: #1d4ed8;

    background: #eff6ff;
}

.semester-badge.second {
    color: #7c3aed;

    background: #f5f3ff;
}

.semester-badge.annual {
    color: #166534;

    background: #f0fdf4;
}


/* ==========================================================================
   EMPTY STATE
   ========================================================================== */

.roster-empty {
    padding: 55px 25px;

    text-align: center;
}

.roster-empty-icon {
    width: 58px;
    height: 58px;

    margin: 0 auto 15px;

    border-radius: 14px;

    background: var(--primary-light);

    color: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 25px;
}

.roster-empty-title {
    margin: 0;

    color: var(--text);

    font-size: 15px;
    font-weight: 700;
}

.roster-empty-text {
    max-width: 430px;

    margin: 7px auto 0;

    color: var(--muted);

    font-size: 12px;

    line-height: 1.6;
}


/* ==========================================================================
   SUMMARY
   ========================================================================== */

.roster-summary {
    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    padding: 15px 20px;

    border-top: 1px solid var(--border);

    background: #fafafa;
}

.roster-summary-left {
    display: flex;

    align-items: center;

    gap: 8px;

    color: var(--muted);

    font-size: 12px;
}

.roster-summary-left strong {
    color: var(--text);
}

.roster-summary-right {
    display: flex;

    align-items: center;

    gap: 8px;
}


/* ==========================================================================
   BADGES
   ========================================================================== */

.badge {
    font-weight: 600;

    border-radius: 6px;

    padding: 5px 8px;
}

.badge-primary {
    color: #1d4ed8;

    background: #eff6ff;
}

.badge-success {
    color: #166534;

    background: #f0fdf4;
}

.badge-warning {
    color: #92400e;

    background: #fffbeb;
}

.badge-danger {
    color: #991b1b;

    background: #fef2f2;
}

.badge-secondary {
    color: #374151;

    background: #f3f4f6;
}


/* ==========================================================================
   ALERTS
   ========================================================================== */

.alert {
    border-radius: 10px;

    font-size: 13px;
}

.alert i {
    margin-right: 5px;
}

.alert-success {
    color: #166534;

    background: #f0fdf4;

    border-color: #bbf7d0;
}

.alert-warning {
    color: #92400e;

    background: #fffbeb;

    border-color: #fde68a;
}

.alert-danger {
    color: #991b1b;

    background: #fef2f2;

    border-color: #fecaca;
}

.alert-info {
    color: #075985;

    background: #f0f9ff;

    border-color: #bae6fd;
}


/* ==========================================================================
   LOADING STATE
   ========================================================================== */

.roster-loading {
    display: flex;

    align-items: center;
    justify-content: center;

    gap: 10px;

    min-height: 160px;

    color: var(--muted);

    font-size: 13px;
}

.roster-loading .spinner-border {
    width: 20px;
    height: 20px;

    border-width: 2px;
}


/* ==========================================================================
   PRINT
   ========================================================================== */

@media print {

    @page {
        size: landscape;
        margin: 10mm;
    }

    body {
        background: #ffffff !important;
    }

    .sidebar,
    .sidebar-overlay,
    .topbar,
    .sidebar-toggle,
    .page-header-actions,
    .roster-filter-card,
    .roster-result-actions {
        display: none !important;
    }

    .main-content {
        margin-left: 0 !important;
    }

    .page-content {
        padding: 0 !important;
    }

    .card {
        border: none !important;
        box-shadow: none !important;
    }

    .roster-result-card {
        border: none !important;
    }

    .roster-table-wrapper {
        overflow: visible !important;
    }

    .roster-table {
        min-width: 0 !important;

        width: 100% !important;

        font-size: 9px !important;
    }

    .roster-table th {
        position: static !important;

        background: #f3f4f6 !important;

        color: #000000 !important;
    }

    .roster-table .student-code-column,
    .roster-table .student-name-column {
        position: static !important;
    }

    .roster-table td,
    .roster-table th {
        padding: 4px 5px !important;
    }

    .roster-summary {
        display: none !important;
    }
}


/* ==========================================================================
   RESPONSIVE - TABLET
   ========================================================================== */

@media (max-width: 1200px) {

    .roster-filter-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .page-content {
        padding: 22px;
    }

    .topbar {
        padding: 0 22px;
    }

}


/* ==========================================================================
   RESPONSIVE - MOBILE
   ========================================================================== */

@media (max-width: 991.98px) {

    :root {
        --sidebar-width: 260px;
    }

    .sidebar {
        transform: translateX(-100%);

        box-shadow:
            8px 0 30px rgba(0, 0, 0, 0.15);
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
    }

    .sidebar-toggle {
        display: inline-flex;
    }

    .topbar {
        padding: 0 18px;
    }

    .page-content {
        padding: 20px 18px;
    }

}


/* ==========================================================================
   RESPONSIVE - SMALL TABLET / LARGE PHONE
   ========================================================================== */

@media (max-width: 767.98px) {

    .topbar {
        height: 70px;

        padding: 0 14px;
    }

    .topbar-title {
        font-size: 17px;
    }

    .topbar-subtitle {
        display: none;
    }

    .topbar-user-info {
        display: none;
    }

    .topbar-user-avatar {
        width: 38px;
        height: 38px;
    }

    .page-content {
        padding: 16px 14px;
    }

    .page-header {
        flex-direction: column;

        margin-bottom: 18px;
    }

    .page-title {
        font-size: 21px;
    }

    .page-description {
        font-size: 12px;
    }

    .page-header-actions {
        width: 100%;
    }

    .page-header-actions .btn {
        flex: 1;
    }

    .roster-filter-grid {
        grid-template-columns: 1fr;

        gap: 13px;
    }

    .roster-filter-card .card-body {
        padding: 16px;
    }

    .roster-result-header {
        flex-direction: column;

        align-items: flex-start;

        padding: 16px;
    }

    .roster-result-actions {
        width: 100%;
    }

    .roster-result-actions .btn {
        flex: 1;
    }

    .roster-result-title {
        font-size: 15px;
    }

    .roster-summary {
        flex-direction: column;

        align-items: flex-start;

        padding: 13px 16px;
    }

}


/* ==========================================================================
   RESPONSIVE - SMALL PHONE
   ========================================================================== */

@media (max-width: 480px) {

    .sidebar {
        width: min(260px, 86vw);
    }

    .topbar {
        padding: 0 12px;
    }

    .sidebar-toggle {
        width: 38px;
        height: 38px;
    }

    .topbar-user-avatar {
        width: 36px;
        height: 36px;
    }

    .page-content {
        padding: 14px 10px;
    }

    .page-title {
        font-size: 19px;
    }

    .card-body {
        padding: 15px;
    }

    .roster-result-meta {
        align-items: flex-start;

        flex-direction: column;
    }

}


/* ==========================================================================
   UTILITY CLASSES
   ========================================================================== */

.text-primary-custom {
    color: var(--primary) !important;
}

.text-muted-custom {
    color: var(--muted) !important;
}

.text-success-custom {
    color: var(--success) !important;
}

.text-danger-custom {
    color: var(--danger) !important;
}

.bg-primary-light {
    background: var(--primary-light) !important;
}

.cursor-pointer {
    cursor: pointer;
}


/* ==========================================================================
   FOCUS ACCESSIBILITY
   ========================================================================== */

a:focus-visible,
button:focus-visible,
select:focus-visible,
input:focus-visible {
    outline: 3px solid rgba(37, 99, 235, 0.25);

    outline-offset: 2px;
}


/* ==========================================================================
   SMOOTH ANIMATIONS
   ========================================================================== */

.sidebar-link,
.btn,
.card,
.form-control,
.form-select,
.sidebar-toggle {
    -webkit-tap-highlight-color: transparent;
}

</style>