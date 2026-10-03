<?php

header('Content-Type: text/css; charset=UTF-8');

?>

/*
|--------------------------------------------------------------------------
| Transcript Styles
|--------------------------------------------------------------------------
*/

:root {

    --primary: #1e3a5f;
    --primary-dark: #152c47;
    --primary-accent: #2f5d8a;

    --sidebar: #111827;
    --sidebar-hover: #1f2937;

    --background: #f5f7fb;
    --card: #ffffff;

    --text: #111827;
    --muted: #6b7280;
    --border: #e5e7eb;

    --primary-light: #eef4f9;
    --primary-lighter: #f7fafc;

    --success: #059669;
    --danger: #dc2626;

}


/*
|--------------------------------------------------------------------------
| Global
|--------------------------------------------------------------------------
*/

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {

    margin: 0;

    background: var(--background);

    color: var(--text);

    font-family:
        'Inter',
        -apple-system,
        BlinkMacSystemFont,
        'Segoe UI',
        sans-serif;

    font-size: 14px;

}


/*
|--------------------------------------------------------------------------
| Main Application
|--------------------------------------------------------------------------
*/

.app {

    min-height: 100vh;

}


/*
|--------------------------------------------------------------------------
| Sidebar
|--------------------------------------------------------------------------
*/

.sidebar {

    position: fixed;

    top: 0;
    left: 0;
    bottom: 0;

    width: 260px;

    background: var(--sidebar);

    color: #ffffff;

    z-index: 1050;

    overflow-y: auto;

    transition:
        transform 0.25s ease;

}


.brand {

    height: 78px;

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 0 22px;

    border-bottom:
        1px solid rgba(255, 255, 255, 0.07);

}


.brand-icon {

    width: 40px;
    height: 40px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background: var(--primary);

    color: #ffffff;

    font-size: 20px;

    flex-shrink: 0;

}


.brand-title {

    font-size: 15px;

    font-weight: 700;

    line-height: 1.2;

    color: #ffffff;

}


.brand-subtitle {

    margin-top: 3px;

    font-size: 11px;

    font-weight: 500;

    color: #9ca3af;

}


/*
|--------------------------------------------------------------------------
| Sidebar Navigation
|--------------------------------------------------------------------------
*/

.sidebar-menu {

    padding: 22px 12px;

}


.menu-label {

    padding: 0 12px 9px;

    color: #6b7280;

    font-size: 10px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 0.08em;

}


.nav-link {

    display: flex;

    align-items: center;

    gap: 12px;

    width: 100%;

    padding: 11px 13px;

    margin-bottom: 3px;

    border-radius: 8px;

    color: #9ca3af;

    text-decoration: none;

    font-size: 13px;

    font-weight: 500;

    transition:
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;

}


.nav-link i {

    width: 20px;

    font-size: 16px;

    text-align: center;

}


.nav-link:hover {

    color: #ffffff;

    background: var(--sidebar-hover);

}


.nav-link.active {

    color: #ffffff;

    background: var(--primary);

    box-shadow:
        0 4px 12px rgba(30, 58, 95, 0.25);

}


.nav-link.logout-link {

    color: #fca5a5;

}


.nav-link.logout-link:hover {

    color: #fecaca;

    background:
        rgba(220, 38, 38, 0.12);

}


/*
|--------------------------------------------------------------------------
| Sidebar Overlay
|--------------------------------------------------------------------------
*/

.sidebar-overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(0, 0, 0, 0.5);

    z-index: 1040;

}


.sidebar-overlay.show {

    display: block;

}


/*
|--------------------------------------------------------------------------
| Main Area
|--------------------------------------------------------------------------
*/

.main {

    margin-left: 260px;

    min-height: 100vh;

}


/*
|--------------------------------------------------------------------------
| Topbar
|--------------------------------------------------------------------------
*/

.topbar {

    position: sticky;

    top: 0;

    z-index: 1000;

    height: 78px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 30px;

    background: #ffffff;

    border-bottom:
        1px solid var(--border);

}


.topbar-left {

    display: flex;

    align-items: center;

    gap: 15px;

}


.mobile-menu {

    display: none;

    width: 40px;
    height: 40px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    background: #ffffff;

    color: var(--text);

    font-size: 21px;

    align-items: center;
    justify-content: center;

    cursor: pointer;

}


.page-title {

    margin: 0;

    font-size: 20px;

    font-weight: 700;

    letter-spacing: -0.02em;

}


.page-subtitle {

    margin-top: 4px;

    color: var(--muted);

    font-size: 12px;

}


.top-profile {

    display: flex;

    align-items: center;

    gap: 10px;

}


.top-profile img {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    object-fit: cover;

    border:
        2px solid var(--primary-light);

}


.top-profile-name {

    font-size: 13px;

    font-weight: 600;

}


.top-profile-role {

    margin-top: 2px;

    color: var(--muted);

    font-size: 11px;

}


/*
|--------------------------------------------------------------------------
| Page Content
|--------------------------------------------------------------------------
*/

.content {

    padding: 30px;

}


/*
|--------------------------------------------------------------------------
| Error Alert
|--------------------------------------------------------------------------
*/

.error-alert {

    border: none;

    border-radius: 10px;

}


/*
|--------------------------------------------------------------------------
| Transcript Intro
|--------------------------------------------------------------------------
*/

.transcript-intro {

    display: flex;

    align-items: flex-start;

    gap: 15px;

    margin-bottom: 22px;

    padding: 22px;

    background:
        var(--primary-light);

    border:
        1px solid #d5e1ec;

    border-radius: 12px;

}


.intro-icon {

    width: 46px;
    height: 46px;

    flex-shrink: 0;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background:
        var(--primary);

    color: #ffffff;

    font-size: 21px;

}


.transcript-intro h2 {

    margin: 1px 0 6px;

    font-size: 17px;

    font-weight: 700;

}


.transcript-intro p {

    margin: 0;

    max-width: 760px;

    color: var(--muted);

    font-size: 13px;

    line-height: 1.6;

}


/*
|--------------------------------------------------------------------------
| Search Card
|--------------------------------------------------------------------------
*/

.search-card {

    padding: 24px;

    background: var(--card);

    border:
        1px solid var(--border);

    border-radius: 12px;

    box-shadow:
        0 2px 8px rgba(15, 23, 42, 0.03);

    margin-bottom: 22px;

}


.section-heading {

    display: flex;

    align-items: center;

    gap: 12px;

    margin-bottom: 20px;

}


.section-heading-icon {

    width: 40px;
    height: 40px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 9px;

    background:
        var(--primary-light);

    color: var(--primary);

    font-size: 18px;

}


.section-heading h2 {

    margin: 0;

    font-size: 15px;

    font-weight: 700;

}


.section-heading p {

    margin: 4px 0 0;

    color: var(--muted);

    font-size: 12px;

}


/*
|--------------------------------------------------------------------------
| Search Form
|--------------------------------------------------------------------------
*/

.search-form {

    display: flex;

    align-items: center;

    gap: 10px;

}


.search-input-wrapper {

    position: relative;

    flex: 1;

}


.search-input-wrapper i {

    position: absolute;

    left: 14px;

    top: 50%;

    transform:
        translateY(-50%);

    color: #9ca3af;

    font-size: 16px;

    pointer-events: none;

}


.search-input-wrapper input {

    width: 100%;

    height: 44px;

    padding:
        0 14px 0 42px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    outline: none;

    background: #ffffff;

    color: var(--text);

    font-family: inherit;

    font-size: 13px;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;

}


.search-input-wrapper input::placeholder {

    color: #9ca3af;

}


.search-input-wrapper input:focus {

    border-color:
        var(--primary);

    box-shadow:
        0 0 0 3px rgba(30, 58, 95, 0.1);

}


.search-button {

    height: 44px;

    padding: 0 19px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    gap: 8px;

    border: none;

    border-radius: 8px;

    background:
        var(--primary);

    color: #ffffff;

    font-family: inherit;

    font-size: 13px;

    font-weight: 600;

    cursor: pointer;

    transition:
        background 0.2s ease,
        transform 0.2s ease;

}


.search-button:hover {

    background:
        var(--primary-dark);

    transform:
        translateY(-1px);

}


.clear-button {

    height: 44px;

    padding: 0 16px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    background: #ffffff;

    color: var(--muted);

    text-decoration: none;

    font-size: 13px;

    font-weight: 500;

    transition:
        background 0.2s ease,
        color 0.2s ease;

}


.clear-button:hover {

    background: #f9fafb;

    color: var(--text);

}


/*
|--------------------------------------------------------------------------
| Results Card
|--------------------------------------------------------------------------
*/

.results-card {

    background: #ffffff;

    border:
        1px solid var(--border);

    border-radius: 12px;

    overflow: hidden;

    box-shadow:
        0 2px 8px rgba(15, 23, 42, 0.03);

}


.results-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    padding: 21px 24px;

    border-bottom:
        1px solid var(--border);

}


.results-header h2 {

    margin: 0;

    font-size: 15px;

    font-weight: 700;

}


.results-header p {

    margin: 4px 0 0;

    color: var(--muted);

    font-size: 12px;

}


.result-count {

    display: flex;

    align-items: baseline;

    gap: 5px;

    padding: 7px 11px;

    border-radius: 7px;

    background:
        var(--primary-light);

    color: var(--primary);

    font-size: 14px;

    font-weight: 700;

    white-space: nowrap;

}


.result-count span {

    font-size: 11px;

    font-weight: 500;

}


/*
|--------------------------------------------------------------------------
| Students Table
|--------------------------------------------------------------------------
*/

.table-responsive {

    width: 100%;

    overflow-x: auto;

}


.students-table {

    width: 100%;

    border-collapse: collapse;

}


.students-table th {

    padding: 12px 20px;

    background: #f9fafb;

    color: #6b7280;

    border-bottom:
        1px solid var(--border);

    font-size: 10px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 0.04em;

    white-space: nowrap;

}


.students-table td {

    padding: 14px 20px;

    border-bottom:
        1px solid #f0f1f3;

    vertical-align: middle;

    font-size: 13px;

}


.students-table tbody tr:last-child td {

    border-bottom: none;

}


.students-table tbody tr {

    transition:
        background 0.15s ease;

}


.students-table tbody tr:hover {

    background:
        #fafbff;

}


.row-number {

    color: #9ca3af;

    font-size: 12px;

}


.student-code {

    display: inline-flex;

    padding: 5px 8px;

    border-radius: 6px;

    background: #f3f4f6;

    color: #374151;

    font-family: monospace;

    font-size: 12px;

    font-weight: 600;

}


.student-name {

    display: flex;

    align-items: center;

    gap: 10px;

}


.student-avatar {

    width: 34px;
    height: 34px;

    display: flex;

    align-items: center;
    justify-content: center;

    flex-shrink: 0;

    border-radius: 50%;

    background:
        var(--primary-light);

    color:
        var(--primary);

}


.student-name strong {

    font-size: 13px;

    font-weight: 600;

}


.view-button {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    padding: 8px 12px;

    border-radius: 7px;

    background:
        var(--primary-light);

    color:
        var(--primary);

    text-decoration: none;

    font-size: 12px;

    font-weight: 600;

    transition:
        background 0.2s ease,
        color 0.2s ease;

}


.view-button:hover {

    background:
        var(--primary);

    color:
        #ffffff;

}


/*
|--------------------------------------------------------------------------
| Empty Search State
|--------------------------------------------------------------------------
*/

.empty-state {

    padding: 60px 20px;

    text-align: center;

}


.empty-icon {

    width: 62px;
    height: 62px;

    display: flex;

    align-items: center;
    justify-content: center;

    margin: 0 auto 15px;

    border-radius: 50%;

    background:
        #f3f4f6;

    color:
        #9ca3af;

    font-size: 26px;

}


.empty-state h3 {

    margin: 0 0 7px;

    font-size: 15px;

    font-weight: 700;

}


.empty-state p {

    margin: 0;

    color:
        var(--muted);

    font-size: 12px;

}


.empty-state .empty-hint {

    margin-top: 5px;

    color:
        #9ca3af;

}


/*
|--------------------------------------------------------------------------
| Transcript Action Bar
|--------------------------------------------------------------------------
*/

.transcript-actions {

    position: sticky;

    top: 0;

    z-index: 1100;

    min-height: 64px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    padding: 10px 24px;

    background: #ffffff;

    border-bottom:
        1px solid var(--border);

    box-shadow:
        0 2px 8px rgba(15, 23, 42, 0.04);

}


.transcript-actions .btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    border-radius: 7px;

    font-size: 12px;

    font-weight: 600;

}


/*
|--------------------------------------------------------------------------
| Transcript Container
|--------------------------------------------------------------------------
*/

.transcript-container {

    padding:
        30px 20px 50px;

}


/*
|--------------------------------------------------------------------------
| A4 Transcript Page
|--------------------------------------------------------------------------
| Professional document frame.
|--------------------------------------------------------------------------
*/

.transcript-page {

    position: relative;

    width: 210mm;

    height: 297mm;

    min-height: 297mm;

    margin:
        0 auto 30px;

    padding:
        15mm 15mm 12mm;

    background: #ffffff;

    border:
        1.2px solid #b8c2ce;

    box-shadow:
        0 8px 30px rgba(15, 23, 42, 0.08);

    overflow: hidden;

}


/*
|--------------------------------------------------------------------------
| Professional Inner Border
|--------------------------------------------------------------------------
| Creates an official certificate/transcript-style frame.
|--------------------------------------------------------------------------
*/

.transcript-page::before {

    content: "";

    position: absolute;

    top: 4mm;

    right: 4mm;

    bottom: 4mm;

    left: 4mm;

    border:
        0.7px solid #d5dce4;

    pointer-events: none;

    z-index: 0;

}


/*
|--------------------------------------------------------------------------
| Inner Accent Border
|--------------------------------------------------------------------------
*/

.transcript-page::after {

    content: "";

    position: absolute;

    top: 5.5mm;

    right: 5.5mm;

    bottom: 5.5mm;

    left: 5.5mm;

    border:
        0.35px solid #edf0f3;

    pointer-events: none;

    z-index: 0;

}


/*
|--------------------------------------------------------------------------
| Keep Transcript Content Above Frame
|--------------------------------------------------------------------------
*/

.transcript-page > * {

    position: relative;

    z-index: 1;

}


/*
|--------------------------------------------------------------------------
| School Header
|--------------------------------------------------------------------------
| Formal dark navy header for official school document.
|--------------------------------------------------------------------------
*/

.transcript-header {

    min-height: 42mm;

    display: grid;

    grid-template-columns:
        32mm 1fr 32mm;

    align-items: center;

    padding:
        7mm 8mm;

    margin:
        -15mm -15mm 0;

    background:
        linear-gradient(
            135deg,
            #142b45 0%,
            #1e3a5f 55%,
            #274d73 100%
        );

    color: #ffffff;

    border-bottom:
        4px solid #0d1f33;

    box-shadow:
        inset 0 -1px 0 rgba(255, 255, 255, 0.12);

}


/*
|--------------------------------------------------------------------------
| School Logo
|--------------------------------------------------------------------------
*/

.logo-container {

    display: flex;

    align-items: center;

    justify-content: center;

}


.school-logo {

    width: 27mm;

    height: 27mm;

    object-fit: contain;

    background: #ffffff;

    padding: 3mm;

    border-radius: 50%;

    box-shadow:
        0 3px 10px rgba(0, 0, 0, 0.16);

}


.logo-placeholder {

    width: 27mm;

    height: 27mm;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: #ffffff;

    color: var(--primary);

    font-size: 25px;

}


/*
|--------------------------------------------------------------------------
| School Header Text
|--------------------------------------------------------------------------
*/

.school-header-text {

    text-align: center;

}


.school-header-text h1 {

    margin: 0;

    color: #ffffff;

    font-size: 19px;

    font-weight: 800;

    letter-spacing: 0.025em;

}


.document-title {

    margin-top: 7px;

    color: #ffffff;

    font-size: 17px;

    font-weight: 700;

    letter-spacing: 0.13em;

}


.document-subtitle {

    margin-top: 5px;

    color:
        rgba(255, 255, 255, 0.78);

    font-size: 9px;

    text-transform: uppercase;

    letter-spacing: 0.08em;

}


.header-spacer {

    width: 100%;

}


/*
|--------------------------------------------------------------------------
| Academic Year Banner
|--------------------------------------------------------------------------
| This is displayed automatically on each transcript page.
|--------------------------------------------------------------------------
*/

.academic-year-banner {

    display: grid;

    grid-template-columns:
        1fr 0.65fr 0.65fr;

    gap: 10px;

    margin-top: 8mm;

    padding:
        4mm 5mm;

    background:
        linear-gradient(
            135deg,
            #eef4f9,
            #f8fafc
        );

    border:
        1px solid #d7e1ea;

    border-left:
        4px solid var(--primary);

    border-radius: 5px;

}


.academic-year-banner > div {

    display: flex;

    flex-direction: column;

    justify-content: center;

}


.banner-label {

    margin-bottom: 2px;

    color: #6b7280;

    font-size: 7px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 0.08em;

}


.academic-year-banner strong {

    color: var(--primary);

    font-size: 11px;

    font-weight: 700;

}


.grade-display,
.section-display {

    padding-left: 4mm;

    border-left:
        1px solid #d7e1ea;

}


/*
|--------------------------------------------------------------------------
| Student Information
|--------------------------------------------------------------------------
*/

.student-information {

    display: grid;

    grid-template-columns:
        1.5fr 1fr 0.8fr 0.8fr;

    margin-top: 5mm;

    border:
        1px solid var(--border);

    border-radius: 4px;

    overflow: hidden;

}


.student-info-item {

    min-height: 16mm;

    display: flex;

    flex-direction: column;

    justify-content: center;

    padding:
        3mm 4mm;

    border-right:
        1px solid var(--border);

}


.student-info-item:last-child {

    border-right: none;

}


.info-label {

    margin-bottom: 2px;

    color: #6b7280;

    font-size: 7px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 0.06em;

}


.info-value {

    color: #111827;

    font-size: 9px;

    font-weight: 600;

}


/*
|--------------------------------------------------------------------------
| Certification Statement
|--------------------------------------------------------------------------
*/

.certification-statement {

    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin-top: 5mm;

    padding:
        3.5mm 4mm;

    background:
        #fafcff;

    border-left:
        3px solid var(--primary);

    border-radius: 3px;

}


.certification-statement i {

    margin-top: 1px;

    color:
        var(--primary);

    font-size: 14px;

}


.certification-statement p {

    margin: 0;

    color: #4b5563;

    font-size: 8.5px;

    line-height: 1.65;

}


.certification-statement strong {

    color: #111827;

}


/*
|--------------------------------------------------------------------------
| Record Section
|--------------------------------------------------------------------------
*/

.record-section {

    margin-top: 5mm;

}


.record-section-heading {

    display: flex;

    align-items: center;

    gap: 8px;

    margin-bottom: 3mm;

}


.heading-icon {

    width: 25px;
    height: 25px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 5px;

    background:
        var(--primary);

    color: #ffffff;

    font-size: 11px;

}


.record-section-heading h2 {

    margin: 0;

    color: #111827;

    font-size: 11px;

    font-weight: 700;

}


.record-section-heading span {

    display: block;

    margin-top: 2px;

    color: #9ca3af;

    font-size: 7px;

}


/*
|--------------------------------------------------------------------------
| Transcript Table
|--------------------------------------------------------------------------
*/

.table-wrapper {

    width: 100%;

    border:
        1px solid #d9dee5;

    border-radius: 4px;

    overflow: hidden;

}


.transcript-table {

    width: 100%;

    border-collapse: collapse;

    table-layout: fixed;

}


.transcript-table th {

    padding:
        2.8mm 2mm;

    background:
        #eaf0f5;

    color:
        #1e3a5f;

    border-right:
        1px solid #d6e0e8;

    border-bottom:
        1px solid #c5d3df;

    font-size: 7.5px;

    font-weight: 700;

    text-align: center;

    vertical-align: middle;

}


.transcript-table th:last-child {

    border-right: none;

}


.transcript-table th small {

    display: block;

    margin-top: 1px;

    color:
        #6b7280;

    font-size: 6.5px;

    font-weight: 500;

}


.transcript-table td {

    padding:
        2.5mm 2mm;

    border-right:
        1px solid #e5e7eb;

    border-bottom:
        1px solid #e5e7eb;

    color:
        #374151;

    font-size: 8px;

    vertical-align: middle;

}


.transcript-table tbody tr:last-child td {

    border-bottom: none;

}


.transcript-table td:last-child {

    border-right: none;

}


.number-column {

    width: 9%;

}


.subject-column {

    width: 35%;

}


.transcript-table th:nth-child(3),
.transcript-table th:nth-child(4) {

    width: 18%;

}


.transcript-table th:nth-child(5) {

    width: 20%;

}


.number-cell {

    color:
        #9ca3af !important;

    text-align: center;

}


.subject-cell {

    color:
        #111827 !important;

    font-weight: 600;

}


.mark-cell {

    text-align: center;

    font-variant-numeric:
        tabular-nums;

}


.annual-mark {

    color:
        var(--primary) !important;

    background:
        var(--primary-lighter);

    font-weight: 700;

}


/*
|--------------------------------------------------------------------------
| No Results
|--------------------------------------------------------------------------
*/

.no-results {

    padding: 8mm;

    border:
        1px dashed #d1d5db;

    border-radius: 5px;

    background:
        #fafafa;

    color:
        #6b7280;

    text-align: center;

    font-size: 8px;

}


.no-results i {

    margin-right: 5px;

    color:
        #9ca3af;

}


/*
|--------------------------------------------------------------------------
| Annual Summary
|--------------------------------------------------------------------------
*/

.summary-section {

    margin-top: 5mm;

}


.summary-title {

    display: flex;

    align-items: center;

    gap: 6px;

    margin-bottom: 2.5mm;

    color:
        #111827;

    font-size: 9px;

    font-weight: 700;

}


.summary-title i {

    color:
        var(--primary);

    font-size: 11px;

}


.summary-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 3mm;

}


.summary-card {

    padding: 3mm;

    border:
        1px solid #e5e7eb;

    border-radius: 4px;

    background:
        #ffffff;

    text-align: center;

}


.summary-card span {

    display: block;

    margin-bottom: 3px;

    color:
        #6b7280;

    font-size: 6.5px;

    font-weight: 600;

    text-transform: uppercase;

    letter-spacing: 0.04em;

}


.summary-card strong {

    display: block;

    color:
        #111827;

    font-size: 12px;

    font-weight: 800;

}


.summary-card-primary {

    background:
        var(--primary-light);

    border-color:
        #c6d5e2;

}


.summary-card-primary strong {

    color:
        var(--primary-dark);

}


/*
|--------------------------------------------------------------------------
| Footer Certification
|--------------------------------------------------------------------------
*/

.footer-certification {

    margin-top: 5mm;

    padding:
        3mm 4mm;

    background:
        #fafafa;

    border:
        1px solid #eeeeee;

    border-radius: 4px;

}


.footer-certification p {

    margin: 0;

    color:
        #6b7280;

    font-size: 6.8px;

    line-height: 1.55;

}


.footer-certification p + p {

    margin-top: 2px;

}


.footer-certification strong {

    color:
        #374151;

}


/*
|--------------------------------------------------------------------------
| Signatures
|--------------------------------------------------------------------------
| Anchored toward the bottom portion of every A4 page.
|--------------------------------------------------------------------------
*/

.signature-section {

    position: absolute !important;

    left: 15mm;

    right: 15mm;

    bottom: 17mm;

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 35mm;

    align-items: end;

    margin: 0;

    padding:
        0 8mm;

}


.signature-box {

    min-height: 18mm;

    text-align: center;

}


.signature-line {

    height: 9mm;

    margin-bottom: 2mm;

    border-bottom:
        1px solid #6b7280;

}


.signature-box strong {

    display: block;

    color:
        #374151;

    font-size: 7.5px;

    font-weight: 700;

}


.signature-box span {

    display: block;

    margin-top: 2px;

    color:
        #9ca3af;

    font-size: 6.5px;

}


/*
|--------------------------------------------------------------------------
| Transcript Footer
|--------------------------------------------------------------------------
| Fixed at the bottom of every transcript page.
|--------------------------------------------------------------------------
*/

.transcript-footer {

    position: absolute !important;

    left: 15mm;

    right: 15mm;

    bottom: 6mm;

    height: 7mm;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    padding-top: 3mm;

    border-top:
        1px solid #d5dbe2;

    color:
        #7b8794;

    font-size: 6px;

    background:
        transparent;

}


.transcript-footer span {

    white-space: nowrap;

}


.transcript-footer span:nth-child(2) {

    color:
        #536273;

    font-weight: 600;

}


/*
|--------------------------------------------------------------------------
| Empty Transcript
|--------------------------------------------------------------------------
*/

.empty-transcript {

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    text-align: center;

}


.empty-transcript-icon {

    width: 70px;
    height: 70px;

    display: flex;

    align-items: center;
    justify-content: center;

    margin-bottom: 18px;

    border-radius: 50%;

    background:
        var(--primary-light);

    color:
        var(--primary);

    font-size: 30px;

}


.empty-transcript h2 {

    margin: 0 0 8px;

    font-size: 18px;

}


.empty-transcript p {

    margin: 0;

    color:
        var(--muted);

    font-size: 13px;

}


.student-reference {

    margin-top: 18px;

    padding:
        10px 16px;

    border-radius: 7px;

    background:
        #f9fafb;

    color:
        #374151;

    font-size: 12px;

    font-weight: 600;

}


.student-reference span {

    margin-left: 8px;

    color:
        var(--muted);

    font-family: monospace;

    font-weight: 500;

}


/*
|--------------------------------------------------------------------------
| Responsive - Tablet
|--------------------------------------------------------------------------
*/

@media (max-width: 1100px) {

    .sidebar {

        transform:
            translateX(-100%);

    }


    .sidebar.show {

        transform:
            translateX(0);

    }


    .main {

        margin-left: 0;

    }


    .mobile-menu {

        display: flex;

    }


    .transcript-container {

        padding-left: 15px;

        padding-right: 15px;

    }

}


/*
|--------------------------------------------------------------------------
| Responsive - Mobile
|--------------------------------------------------------------------------
*/

@media (max-width: 768px) {

    .topbar {

        height: 68px;

        padding:
            0 16px;

    }


    .page-title {

        font-size: 16px;

    }


    .page-subtitle {

        display: none;

    }


    .top-profile-name,
    .top-profile-role {

        display: none;

    }


    .top-profile img {

        width: 36px;
        height: 36px;

    }


    .content {

        padding:
            18px 15px;

    }


    .transcript-intro {

        padding: 17px;

    }


    .transcript-intro h2 {

        font-size: 15px;

    }


    .search-card {

        padding: 18px;

    }


    .search-form {

        flex-direction: column;

        align-items: stretch;

    }


    .search-button,
    .clear-button {

        width: 100%;

    }


    .results-header {

        padding: 17px;

    }


    .students-table th,
    .students-table td {

        padding:
            12px 14px;

    }


    .transcript-actions {

        padding:
            9px 14px;

    }


    .transcript-actions .btn {

        padding:
            7px 10px;

    }


    .transcript-container {

        padding:
            15px 8px 30px;

        overflow-x: auto;

    }


    .transcript-page {

        margin-left: auto;

        margin-right: auto;

    }


    .signature-section {

        left: 14mm;

        right: 14mm;

        bottom: 17mm;

        gap: 20mm;

        padding: 0 4mm;

    }


    .transcript-footer {

        left: 14mm;

        right: 14mm;

    }

}


/*
|--------------------------------------------------------------------------
| Print
|--------------------------------------------------------------------------
*/

@media print {

    @page {

        size: A4 portrait;

        margin: 0;

    }


    html,
    body {

        width: 210mm;

        margin: 0;

        padding: 0;

        background:
            #ffffff !important;

    }


    body {

        -webkit-print-color-adjust:
            exact !important;

        print-color-adjust:
            exact !important;

    }


    .transcript-actions {

        display: none !important;

    }


    .transcript-container {

        padding: 0 !important;

        margin: 0 !important;

    }


    .transcript-page {

        width: 210mm;

        height: 297mm;

        min-height: 297mm;

        margin: 0 !important;

        padding:
            15mm 15mm 12mm;

        border:
            1.2px solid #b8c2ce !important;

        box-shadow: none !important;

        overflow: hidden;

        page-break-after: always;

        break-after: page;

    }


    .transcript-page::before {

        display: block;

        top: 4mm;

        right: 4mm;

        bottom: 4mm;

        left: 4mm;

        border-color:
            #d5dce4 !important;

    }


    .transcript-page::after {

        display: block;

        top: 5.5mm;

        right: 5.5mm;

        bottom: 5.5mm;

        left: 5.5mm;

        border-color:
            #edf0f3 !important;

    }


    .transcript-page:last-child {

        page-break-after: auto;

        break-after: auto;

    }


    .transcript-header {

        -webkit-print-color-adjust:
            exact !important;

        print-color-adjust:
            exact !important;

    }


    .academic-year-banner,
    .transcript-table th,
    .summary-card-primary {

        -webkit-print-color-adjust:
            exact !important;

        print-color-adjust:
            exact !important;

    }


    .table-wrapper {

        overflow: visible !important;

    }


    .transcript-table {

        page-break-inside: avoid;

        break-inside: avoid;

    }


    .summary-section,
    .signature-section,
    .footer-certification {

        page-break-inside: avoid;

        break-inside: avoid;

    }


    /*
    ------------------------------------------------------------
    | Keep Signatures at Bottom When Printing
    ------------------------------------------------------------
    */

    .signature-section {

        position: absolute !important;

        left: 15mm;

        right: 15mm;

        bottom: 17mm;

        margin: 0 !important;

    }


    /*
    ------------------------------------------------------------
    | Keep Footer at Very Bottom When Printing
    ------------------------------------------------------------
    */

    .transcript-footer {

        position: absolute !important;

        left: 15mm;

        right: 15mm;

        bottom: 6mm;

    }

}


/*
|--------------------------------------------------------------------------
| Print - Empty Transcript
|--------------------------------------------------------------------------
*/

@media print {

    .empty-transcript {

        width: 210mm;

        height: 297mm;

    }

}