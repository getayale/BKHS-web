<?php
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    strtolower($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);

/*
|--------------------------------------------------------------------------
| Load Users
|--------------------------------------------------------------------------
|
| Teachers are included only when their employment status is Active.
| Other user roles are not affected.
|
*/

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        u.role,
        u.is_logged_in,
        u.last_login_at,
        u.created_at,
        t.employment_status
    FROM users u
    LEFT JOIN teachers t
        ON t.user_id = u.id
    WHERE
        LOWER(u.role) <> 'teacher'
        OR (
            LOWER(u.role) = 'teacher'
            AND t.employment_status = 'Active'
        )
    ORDER BY u.created_at DESC
";

$result = $conn->query($sql);

if (!$result) {
    $error = 'Unable to load users.';
}

function roleClass(string $role): string
{
    return match (strtolower($role)) {
        'admin' => 'role-admin',
        'principal' => 'role-principal',
        'teacher' => 'role-teacher',
        'registrar' => 'role-registrar',
        'librarian' => 'role-librarian',
        default => 'role-default',
    };
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

    <title>User Management | Admin</title>

    <!-- Favicon -->
    <link
        rel="icon"
        type="image/webp"
        href="../../public/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="../../public/css/admin-users.css"
    >
</head>

<body>

<div class="admin-layout">

    <!-- Sidebar -->
    <aside class="admin-sidebar">

        <div class="sidebar-brand">

            <div class="brand-mark">
                <i class="bi bi-mortarboard-fill"></i>
            </div>

            <div class="brand-text">
                <strong>Bole Kale Hiwot</strong>
                <span>School Management</span>
            </div>

        </div>

        <nav class="sidebar-nav">

            <div class="nav-section-title">
                MAIN
            </div>

            <a
                href="../dashboard.php"
                class="sidebar-link"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <div class="nav-section-title">
                MANAGEMENT
            </div>

            <a
                href="index.php"
                class="sidebar-link active"
            >
                <i class="bi bi-people-fill"></i>
                <span>Users</span>
            </a>

        </nav>

        <div class="sidebar-footer">

            <a
                href="../../auth/logout.php"
                class="logout-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </div>

    </aside>


    <!-- Main -->
    <main class="admin-main">

        <!-- Topbar -->
        <header class="admin-topbar">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">

                <h1>User Management</h1>

                <p>
                    Manage school staff and system accounts
                </p>

            </div>

            <div class="topbar-actions">

                <button
                    type="button"
                    class="topbar-icon-button"
                >
                    <i class="bi bi-bell"></i>
                    <span class="notification-dot"></span>
                </button>

                <div class="admin-profile">

                    <div class="profile-avatar">
                        <?= strtoupper(
                            substr(
                                $_SESSION['full_name'] ?? 'A',
                                0,
                                1
                            )
                        ) ?>
                    </div>

                    <div class="profile-info">

                        <strong>
                            <?= htmlspecialchars(
                                $_SESSION['full_name']
                                ?? 'Administrator'
                            ) ?>
                        </strong>

                        <span>
                            Administrator
                        </span>

                    </div>

                </div>

            </div>

        </header>


        <!-- Content -->
        <section class="admin-content">

            <!-- Page Header -->
            <div class="page-header">

                <div>

                    <div class="breadcrumb-area">

                        <a href="../dashboard.php">
                            Dashboard
                        </a>

                        <i class="bi bi-chevron-right"></i>

                        <span>
                            Users
                        </span>

                    </div>

                    <h2>
                        System Users
                    </h2>

                    <p>
                        Create and manage administrator and staff accounts.
                    </p>

                </div>

                <a
                    href="create.php"
                    class="btn-add-user"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    <span>Add User</span>
                </a>

            </div>


            <!-- Alerts -->
            <?php if ($success): ?>

                <div class="alert-message alert-success-message">

                    <i class="bi bi-check-circle-fill"></i>

                    <span>
                        <?= htmlspecialchars($success) ?>
                    </span>

                    <button
                        type="button"
                        class="alert-close"
                        onclick="this.parentElement.remove()"
                    >
                        <i class="bi bi-x"></i>
                    </button>

                </div>

            <?php endif; ?>


            <?php if ($error): ?>

                <div class="alert-message alert-error-message">

                    <i class="bi bi-exclamation-circle-fill"></i>

                    <span>
                        <?= htmlspecialchars($error) ?>
                    </span>

                    <button
                        type="button"
                        class="alert-close"
                        onclick="this.parentElement.remove()"
                    >
                        <i class="bi bi-x"></i>
                    </button>

                </div>

            <?php endif; ?>


            <!-- Stats -->
            <div class="user-stats">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div>

                        <span>
                            Total Users
                        </span>

                        <strong>
                            <?= $result
                                ? $result->num_rows
                                : 0 ?>
                        </strong>

                    </div>

                </div>


                <?php

                $onlineCount = 0;

                $roleCounts = [
                    'Admin' => 0,
                    'Principal' => 0,
                    'Teacher' => 0,
                    'Registrar' => 0,
                    'Librarian' => 0
                ];

                if ($result) {

                    $result->data_seek(0);

                    while (
                        $user = $result->fetch_assoc()
                    ) {

                        if (
                            (int) $user['is_logged_in'] === 1
                        ) {
                            $onlineCount++;
                        }

                        $role =
                            strtolower(
                                trim(
                                    (string) $user['role']
                                )
                            );

                        foreach (
                            array_keys($roleCounts)
                            as $roleName
                        ) {

                            if (
                                strtolower($roleName)
                                === $role
                            ) {

                                $roleCounts[$roleName]++;
                                break;
                            }
                        }
                    }

                    $result->data_seek(0);
                }

                ?>

                <div class="stat-card">

                    <div class="stat-icon online-icon">
                        <i class="bi bi-circle-fill"></i>
                    </div>

                    <div>

                        <span>
                            Online Now
                        </span>

                        <strong>
                            <?= $onlineCount ?>
                        </strong>

                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-icon teacher-icon">
                        <i class="bi bi-person-workspace"></i>
                    </div>

                    <div>

                        <span>
                            Active Teachers
                        </span>

                        <strong>
                            <?= $roleCounts['Teacher'] ?>
                        </strong>

                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-icon staff-icon">
                        <i class="bi bi-person-badge-fill"></i>
                    </div>

                    <div>

                        <span>
                            Other Staff
                        </span>

                        <strong>
                            <?= $roleCounts['Admin']
                                + $roleCounts['Principal']
                                + $roleCounts['Registrar']
                                + $roleCounts['Librarian'] ?>
                        </strong>

                    </div>

                </div>

            </div>


            <!-- Users Card -->
            <div class="users-card">

                <div class="users-card-header">

                    <div>

                        <h3>
                            All Users
                        </h3>

                        <p>
                            Manage staff access to the school system.
                        </p>

                    </div>

                    <div class="table-search">

                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            id="userSearch"
                            placeholder="Search users..."
                        >

                    </div>

                </div>


                <!-- Role Filter -->
                <div
                    class="d-flex flex-wrap align-items-center gap-2 px-3 pb-3"
                    id="roleFilters"
                >

                    <button
                        type="button"
                        class="btn btn-sm btn-primary role-filter active"
                        data-role="all"
                    >
                        All
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary role-filter"
                        data-role="Admin"
                    >
                        Admin
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary role-filter"
                        data-role="Principal"
                    >
                        Principal
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary role-filter"
                        data-role="Teacher"
                    >
                        Teacher
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary role-filter"
                        data-role="Registrar"
                    >
                        Registrar
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary role-filter"
                        data-role="Librarian"
                    >
                        Librarian
                    </button>

                </div>


                <div class="table-responsive">

                    <table
                        class="users-table"
                        id="usersTable"
                    >

                        <thead>

                            <tr>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Created</th>
                                <th class="text-end">
                                    Actions
                                </th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if (
                            $result &&
                            $result->num_rows > 0
                        ): ?>

                            <?php while (
                                $user = $result->fetch_assoc()
                            ): ?>

                                <tr
                                    data-role="<?= htmlspecialchars(
                                        $user['role']
                                    ) ?>"
                                >

                                    <!-- User -->
                                    <td>

                                        <div class="user-cell">

                                            <div class="user-avatar">

                                                <?= strtoupper(
                                                    substr(
                                                        trim(
                                                            $user['full_name']
                                                        ),
                                                        0,
                                                        1
                                                    )
                                                ) ?>

                                            </div>

                                            <div class="user-name">

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $user['full_name']
                                                    ) ?>
                                                </strong>

                                                <?php if (
                                                    isset(
                                                        $_SESSION['user_id']
                                                    ) &&
                                                    (int) $_SESSION['user_id']
                                                        ===
                                                    (int) $user['id']
                                                ): ?>

                                                    <span class="you-label">
                                                        You
                                                    </span>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- Contact -->
                                    <td>

                                        <div class="contact-cell">

                                            <span>

                                                <i class="bi bi-envelope"></i>

                                                <?= htmlspecialchars(
                                                    $user['email']
                                                ) ?>

                                            </span>

                                            <?php if (
                                                !empty($user['phone'])
                                            ): ?>

                                                <span>

                                                    <i class="bi bi-telephone"></i>

                                                    <?= htmlspecialchars(
                                                        $user['phone']
                                                    ) ?>

                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </td>


                                    <!-- Role -->
                                    <td>

                                        <span
                                            class="role-badge <?= roleClass(
                                                $user['role']
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                $user['role']
                                            ) ?>
                                        </span>

                                    </td>


                                    <!-- Status -->
                                    <td>

                                        <?php if (
                                            (int) $user['is_logged_in'] === 1
                                        ): ?>

                                            <span
                                                class="status-badge status-online"
                                            >
                                                <span class="status-dot"></span>
                                                Online
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="status-badge status-offline"
                                            >
                                                <span class="status-dot"></span>
                                                Offline
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- Last Login -->
                                    <td>

                                        <?php if (
                                            !empty(
                                                $user['last_login_at']
                                            )
                                        ): ?>

                                            <span class="date-text">

                                                <?= date(
                                                    'M d, Y',
                                                    strtotime(
                                                        $user['last_login_at']
                                                    )
                                                ) ?>

                                            </span>

                                            <small>

                                                <?= date(
                                                    'h:i A',
                                                    strtotime(
                                                        $user['last_login_at']
                                                    )
                                                ) ?>

                                            </small>

                                        <?php else: ?>

                                            <span class="never-login">
                                                Never
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- Created -->
                                    <td>

                                        <span class="date-text">

                                            <?= date(
                                                'M d, Y',
                                                strtotime(
                                                    $user['created_at']
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- Actions -->
                                    <td>

                                        <div class="user-actions">

                                            <a
                                                href="edit.php?id=<?= (int) $user['id'] ?>"
                                                class="action-button edit-action"
                                                title="Edit User"
                                            >
                                                <i class="bi bi-pencil-square"></i>
                                            </a>

                                            <?php if (
                                                !isset(
                                                    $_SESSION['user_id']
                                                ) ||
                                                (int) $_SESSION['user_id']
                                                    !==
                                                (int) $user['id']
                                            ): ?>

                                                <form
                                                    action="delete.php"
                                                    method="POST"
                                                    class="delete-form"
                                                    onsubmit="return confirmDelete('<?= htmlspecialchars(
                                                        addslashes(
                                                            $user['full_name']
                                                        )
                                                    ) ?>')"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int) $user['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="action-button delete-action"
                                                        title="Delete User"
                                                    >
                                                        <i class="bi bi-trash3"></i>
                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="7">

                                    <div class="empty-state">

                                        <div class="empty-icon">
                                            <i class="bi bi-people"></i>
                                        </div>

                                        <h4>
                                            No users found
                                        </h4>

                                        <p>
                                            Start by creating your first
                                            staff account.
                                        </p>

                                        <a
                                            href="create.php"
                                            class="btn-add-user"
                                        >
                                            <i class="bi bi-person-plus"></i>
                                            Add User
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>


                <!-- Pagination -->
                <div
                    class="d-flex flex-wrap align-items-center justify-content-between gap-3 px-3 py-3"
                    id="paginationContainer"
                >

                    <div
                        class="text-muted small"
                        id="paginationInfo"
                    ></div>

                    <nav aria-label="Users pagination">

                        <ul
                            class="pagination pagination-sm mb-0"
                            id="pagination"
                        ></ul>

                    </nav>

                </div>

            </div>

        </section>

    </main>

</div>


<!-- Mobile Overlay -->
<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<script>

const mobileMenuButton =
    document.getElementById('mobileMenuButton');

const sidebar =
    document.querySelector('.admin-sidebar');

const overlay =
    document.getElementById('sidebarOverlay');


mobileMenuButton?.addEventListener(
    'click',
    () => {

        sidebar.classList.toggle('show');

        overlay.classList.toggle('show');

    }
);


overlay?.addEventListener(
    'click',
    () => {

        sidebar.classList.remove('show');

        overlay.classList.remove('show');

    }
);


/*
|--------------------------------------------------------------------------
| Search + Role Filter + Pagination
|--------------------------------------------------------------------------
*/

const searchInput =
    document.getElementById('userSearch');

const table =
    document.getElementById('usersTable');

const pagination =
    document.getElementById('pagination');

const paginationInfo =
    document.getElementById('paginationInfo');

const roleFilters =
    document.querySelectorAll('.role-filter');

const rowsPerPage = 10;

let currentPage = 1;

let currentRole = 'all';


function getFilteredRows() {

    const searchValue =
        searchInput
            ? searchInput.value
                .toLowerCase()
                .trim()
            : '';

    const rows =
        Array.from(
            table.querySelectorAll(
                'tbody tr[data-role]'
            )
        );

    return rows.filter(row => {

        const rowText =
            row.textContent.toLowerCase();

        const rowRole =
            (
                row.getAttribute('data-role') || ''
            ).toLowerCase();

        const matchesSearch =
            searchValue === '' ||
            rowText.includes(searchValue);

        const matchesRole =
            currentRole === 'all' ||
            rowRole === currentRole.toLowerCase();

        return (
            matchesSearch &&
            matchesRole
        );
    });
}


function renderPagination(filteredRows) {

    const totalRows =
        filteredRows.length;

    const totalPages =
        Math.ceil(
            totalRows / rowsPerPage
        );

    if (
        currentPage > totalPages &&
        totalPages > 0
    ) {
        currentPage = totalPages;
    }

    if (totalPages === 0) {
        currentPage = 1;
    }

    const startIndex =
        (currentPage - 1) *
        rowsPerPage;

    const endIndex =
        startIndex +
        rowsPerPage;

    const visibleRows =
        filteredRows.slice(
            startIndex,
            endIndex
        );

    const allRows =
        Array.from(
            table.querySelectorAll(
                'tbody tr[data-role]'
            )
        );

    allRows.forEach(row => {
        row.style.display = 'none';
    });

    visibleRows.forEach(row => {
        row.style.display = '';
    });


    /*
    |--------------------------------------------------------------------------
    | Pagination Information
    |--------------------------------------------------------------------------
    */

    if (totalRows === 0) {

        paginationInfo.textContent =
            'Showing 0 of 0 users';

    } else {

        const showingStart =
            startIndex + 1;

        const showingEnd =
            Math.min(
                endIndex,
                totalRows
            );

        paginationInfo.textContent =
            `Showing ${showingStart}-${showingEnd} of ${totalRows} users`;
    }


    /*
    |--------------------------------------------------------------------------
    | Pagination Buttons
    |--------------------------------------------------------------------------
    */

    pagination.innerHTML = '';

    if (totalPages <= 1) {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Previous
    |--------------------------------------------------------------------------
    */

    const previousItem =
        document.createElement('li');

    previousItem.className =
        `page-item ${
            currentPage === 1
                ? 'disabled'
                : ''
        }`;

    previousItem.innerHTML = `
        <button
            type="button"
            class="page-link"
            aria-label="Previous"
        >
            <i class="bi bi-chevron-left"></i>
        </button>
    `;

    if (currentPage > 1) {

        previousItem
            .querySelector('button')
            .addEventListener(
                'click',
                () => {

                    currentPage--;

                    updateTable();

                }
            );
    }

    pagination.appendChild(
        previousItem
    );


    /*
    |--------------------------------------------------------------------------
    | Page Numbers
    |--------------------------------------------------------------------------
    */

    for (
        let page = 1;
        page <= totalPages;
        page++
    ) {

        const pageItem =
            document.createElement('li');

        pageItem.className =
            `page-item ${
                page === currentPage
                    ? 'active'
                    : ''
            }`;

        pageItem.innerHTML = `
            <button
                type="button"
                class="page-link"
            >
                ${page}
            </button>
        `;

        pageItem
            .querySelector('button')
            .addEventListener(
                'click',
                () => {

                    currentPage = page;

                    updateTable();

                }
            );

        pagination.appendChild(
            pageItem
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Next
    |--------------------------------------------------------------------------
    */

    const nextItem =
        document.createElement('li');

    nextItem.className =
        `page-item ${
            currentPage === totalPages
                ? 'disabled'
                : ''
        }`;

    nextItem.innerHTML = `
        <button
            type="button"
            class="page-link"
            aria-label="Next"
        >
            <i class="bi bi-chevron-right"></i>
        </button>
    `;

    if (
        currentPage < totalPages
    ) {

        nextItem
            .querySelector('button')
            .addEventListener(
                'click',
                () => {

                    currentPage++;

                    updateTable();

                }
            );
    }

    pagination.appendChild(
        nextItem
    );
}


function showNoResultsRow() {

    const tbody =
        table.querySelector('tbody');

    let noResultsRow =
        document.getElementById(
            'noFilteredResults'
        );

    if (!noResultsRow) {

        noResultsRow =
            document.createElement('tr');

        noResultsRow.id =
            'noFilteredResults';

        noResultsRow.innerHTML = `
            <td colspan="7">

                <div class="empty-state">

                    <div class="empty-icon">
                        <i class="bi bi-search"></i>
                    </div>

                    <h4>
                        No users found
                    </h4>

                    <p>
                        No users match your search or role filter.
                    </p>

                </div>

            </td>
        `;

        tbody.appendChild(
            noResultsRow
        );
    }

    noResultsRow.style.display = '';
}


function hideNoResultsRow() {

    const noResultsRow =
        document.getElementById(
            'noFilteredResults'
        );

    if (noResultsRow) {
        noResultsRow.style.display = 'none';
    }
}


function updateTable() {

    const filteredRows =
        getFilteredRows();

    if (
        filteredRows.length === 0
    ) {

        const allRows =
            Array.from(
                table.querySelectorAll(
                    'tbody tr[data-role]'
                )
            );

        allRows.forEach(row => {
            row.style.display = 'none';
        });

        showNoResultsRow();

    } else {

        hideNoResultsRow();
    }

    renderPagination(
        filteredRows
    );
}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

searchInput?.addEventListener(
    'input',
    function () {

        currentPage = 1;

        updateTable();

    }
);


/*
|--------------------------------------------------------------------------
| Role Filter
|--------------------------------------------------------------------------
*/

roleFilters.forEach(button => {

    button.addEventListener(
        'click',
        function () {

            currentRole =
                this.getAttribute(
                    'data-role'
                ) || 'all';

            currentPage = 1;


            roleFilters.forEach(
                filter => {

                    filter.classList.remove(
                        'active'
                    );

                    filter.classList.remove(
                        'btn-primary'
                    );

                    filter.classList.add(
                        'btn-outline-secondary'
                    );

                }
            );


            this.classList.add(
                'active'
            );

            this.classList.remove(
                'btn-outline-secondary'
            );

            this.classList.add(
                'btn-primary'
            );


            updateTable();

        }
    );

});


/*
|--------------------------------------------------------------------------
| Initial Table Load
|--------------------------------------------------------------------------
*/

updateTable();


/*
|--------------------------------------------------------------------------
| Delete Confirmation
|--------------------------------------------------------------------------
*/

function confirmDelete(name) {

    return confirm(
        'Are you sure you want to delete "' +
        name +
        '"?\n\nThis action cannot be undone.'
    );
}

</script>

</body>
</html>
