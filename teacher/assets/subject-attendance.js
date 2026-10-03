document.addEventListener("DOMContentLoaded", () => {
    "use strict";

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    */

    const sidebar = document.querySelector(".teacher-sidebar");
    const menuButton = document.querySelector(".topbar-menu-button");
    const overlay = document.querySelector(".sidebar-overlay");

    const openSidebar = () => {
        if (!sidebar) {
            return;
        }

        sidebar.classList.add("mobile-open");

        if (overlay) {
            overlay.classList.add("active");
        }

        document.body.style.overflow = "hidden";
    };

    const closeSidebar = () => {
        if (!sidebar) {
            return;
        }

        sidebar.classList.remove("mobile-open");

        if (overlay) {
            overlay.classList.remove("active");
        }

        document.body.style.overflow = "";
    };

    if (menuButton) {
        menuButton.addEventListener("click", openSidebar);
    }

    if (overlay) {
        overlay.addEventListener("click", closeSidebar);
    }

    document.querySelectorAll(".sidebar-nav-link").forEach((link) => {
        link.addEventListener("click", () => {
            if (window.innerWidth <= 991) {
                closeSidebar();
            }
        });
    });

    window.addEventListener("resize", () => {
        if (window.innerWidth > 991) {
            closeSidebar();
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Grade / Section / Assignment filtering
    |--------------------------------------------------------------------------
    */

    const gradeFilter = document.querySelector("#grade");
    const sectionFilter = document.querySelector("#section");
    const assignmentSelect = document.querySelector("#assignment_id");

    if (gradeFilter && sectionFilter && assignmentSelect) {
        const filterAssignments = () => {
            const selectedGrade = gradeFilter.value;
            const selectedSection = sectionFilter.value;

            const options = Array.from(
                assignmentSelect.options
            );

            let firstVisibleOption = null;
            let selectedOptionStillVisible = false;

            options.forEach((option, index) => {
                if (index === 0) {
                    return;
                }

                const optionGrade =
                    option.dataset.grade || "";

                const optionSection =
                    option.dataset.section || "";

                const gradeMatches =
                    selectedGrade === "" ||
                    optionGrade === selectedGrade;

                const sectionMatches =
                    selectedSection === "" ||
                    optionSection === selectedSection;

                const visible =
                    gradeMatches && sectionMatches;

                option.hidden = !visible;

                if (visible && !firstVisibleOption) {
                    firstVisibleOption = option;
                }

                if (
                    visible &&
                    option.value === assignmentSelect.value
                ) {
                    selectedOptionStillVisible = true;
                }
            });

            if (!selectedOptionStillVisible) {
                if (firstVisibleOption) {
                    assignmentSelect.value =
                        firstVisibleOption.value;
                } else {
                    assignmentSelect.value = "";
                }
            }
        };

        gradeFilter.addEventListener(
            "change",
            filterAssignments
        );

        sectionFilter.addEventListener(
            "change",
            filterAssignments
        );

        filterAssignments();
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance radio buttons
    |--------------------------------------------------------------------------
    */

    const updateSelectedStatus = (input) => {
        const row = input.closest("tr");

        if (!row) {
            return;
        }

        row.querySelectorAll(
            ".attendance-option"
        ).forEach((option) => {
            option.classList.remove("selected");
        });

        const selectedOption =
            input.closest(".attendance-option");

        if (selectedOption) {
            selectedOption.classList.add("selected");
        }
    };

    document
        .querySelectorAll(
            '.attendance-option input[type="radio"]'
        )
        .forEach((input) => {
            input.addEventListener("change", () => {
                updateSelectedStatus(input);
            });

            if (input.checked) {
                updateSelectedStatus(input);
            }
        });

    /*
    |--------------------------------------------------------------------------
    | Mark all students Present
    |--------------------------------------------------------------------------
    */

    const markAllPresentButton =
        document.querySelector("#markAllPresent");

    if (markAllPresentButton) {
        markAllPresentButton.addEventListener(
            "click",
            () => {
                const presentInputs =
                    document.querySelectorAll(
                        'input[type="radio"][value="Present"]'
                    );

                presentInputs.forEach((input) => {
                    input.checked = true;
                    updateSelectedStatus(input);
                });
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance form submission
    |--------------------------------------------------------------------------
    */

    const attendanceForm =
        document.querySelector(
            'form[data-attendance-form="true"]'
        ) ||
        document.querySelector(
            'form[action*="subject-attendance"]'
        ) ||
        document.querySelector(
            'form input[name="action"][value="save_attendance"]'
        )?.closest("form");

    if (attendanceForm) {
        attendanceForm.addEventListener("submit", (event) => {
            const attendanceInputs =
                attendanceForm.querySelectorAll(
                    'input[type="radio"][name^="attendance["]'
                );

            if (attendanceInputs.length === 0) {
                return;
            }

            const students = new Set();

            attendanceInputs.forEach((input) => {
                students.add(input.name);
            });

            for (const name of students) {
                const checked =
                    attendanceForm.querySelector(
                        `input[name="${CSS.escape(name)}"]:checked`
                    );

                if (!checked) {
                    event.preventDefault();

                    alert(
                        "Please select an attendance status for every student."
                    );

                    return;
                }
            }

            const submitButton =
                attendanceForm.querySelector(
                    'button[type="submit"]'
                );

            if (submitButton) {
                submitButton.disabled = true;

                const originalHtml =
                    submitButton.innerHTML;

                submitButton.innerHTML =
                    '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

                /*
                |--------------------------------------------------------------------------
                | Safety timeout
                |--------------------------------------------------------------------------
                */

                window.setTimeout(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML =
                        originalHtml;
                }, 10000);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Alert auto-hide
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(".sa-alert")
        .forEach((alert) => {
            window.setTimeout(() => {
                alert.style.transition =
                    "opacity 0.3s ease, transform 0.3s ease";

                alert.style.opacity = "0";
                alert.style.transform =
                    "translateY(-5px)";

                window.setTimeout(() => {
                    alert.remove();
                }, 350);
            }, 6000);
        });

    /*
    |--------------------------------------------------------------------------
    | Prevent future date selection
    |--------------------------------------------------------------------------
    */

    const dateInputs =
        document.querySelectorAll(
            'input[type="date"]'
        );

    const today = new Date();

    const year = today.getFullYear();
    const month = String(
        today.getMonth() + 1
    ).padStart(2, "0");
    const day = String(
        today.getDate()
    ).padStart(2, "0");

    const todayString =
        `${year}-${month}-${day}`;

    dateInputs.forEach((input) => {
        if (!input.max) {
            input.max = todayString;
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Confirm historical attendance editing
    |--------------------------------------------------------------------------
    */

    const editLinks =
        document.querySelectorAll(
            '[data-edit-attendance]'
        );

    editLinks.forEach((link) => {
        link.addEventListener("click", (event) => {
            const confirmed = window.confirm(
                "You are about to edit attendance for a previous date. Continue?"
            );

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Export button
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-export-attendance]'
        )
        .forEach((button) => {
            button.addEventListener(
                "click",
                () => {
                    const originalHtml =
                        button.innerHTML;

                    button.disabled = true;

                    button.innerHTML =
                        '<i class="fa-solid fa-spinner fa-spin"></i> Exporting...';

                    /*
                    |--------------------------------------------------------------------------
                    | Browser will navigate/download.
                    | Restore button after a short delay.
                    |--------------------------------------------------------------------------
                    */

                    window.setTimeout(() => {
                        button.disabled = false;
                        button.innerHTML =
                            originalHtml;
                    }, 2000);
                }
            );
        });
});