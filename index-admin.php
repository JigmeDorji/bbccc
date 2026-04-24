<?php

require_once "include/config.php";
require_once "include/auth.php";
require_once "include/notifications.php";
require_once "include/pcm_helpers.php";
require_login();

function bbcc_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return false;
    }
}

$filterCompanyID = $_SESSION['companyID'] ?? null;
$filterProjectID = $_SESSION['projectID'] ?? null;

$role = strtolower($_SESSION['role'] ?? '');
$dashboardRole = $role;
if ($dashboardRole === 'website admin') {
    $dashboardRole = 'website_admin';
}
$isWebsiteAdminDashboard = ($dashboardRole === 'website_admin');
if ($role === 'patron') {
    header('Location: patron-dashboard');
    exit;
}

// Parent dashboard data
$parentDbId = null;
$parentProfile = null;
$myChildren = [];

// Admin overview data
$totalStudents    = 0;
$pendingStudents  = 0;
$approvedStudents = 0;
$totalParents     = 0;
$totalEvents      = 0;
$upcomingEvents   = [];
$bookedEvents     = 0;
$availableEvents  = 0;
$pendingBookings  = 0;
$totalBookings    = 0;
$recentStudents   = [];
$recentBookings   = [];
$contactMessages  = 0;
$unreadNotifications = 0;
$dashboardAttendance = [];
$attendanceTitle = 'Attendance Records';
$attendanceViewLink = 'attendanceManagement';
$parentClassesAttended = 0;
$parentChildTermProgress = [];
$parentTermTotals = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$parentFeeSummary = [
    'outstanding_count' => 0,
    'outstanding_amount' => 0.0,
    'pending_count' => 0,
    'pending_amount' => 0.0,
    'items' => [],
];
$recentClassroomActivity = [];
$dashboardTeacherId = 0;
$teacherAssignedClassNames = [];
$campusAttendanceSummary = [];
$campusAttendanceOverall = ['present' => 0, 'absent' => 0, 'grand' => 0];
$campusKioskSummary = [];
$campusKioskOverall = ['registered' => 0, 'sign_in' => 0, 'sign_out' => 0, 'grand' => 0];
$dashboardFeePayments = [];
$summaryDate = date('Y-m-d');
if (isset($_GET['summary_date'])) {
    $candidateDate = trim((string)$_GET['summary_date']);
    $dt = DateTime::createFromFormat('Y-m-d', $candidateDate);
    if ($dt && $dt->format('Y-m-d') === $candidateDate) {
        $summaryDate = $candidateDate;
    }
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $unreadNotifications = bbcc_unread_notifications_count(
        $pdo,
        (string)($_SESSION['username'] ?? ''),
        (string)($_SESSION['role'] ?? '')
    );
    bbcc_ensure_term_class_total_columns($pdo);
    $campusChoiceLabels = pcm_campus_choice_labels();

    $sessionUsername = (string)($_SESSION['username'] ?? '');
    $sessionUserId = (string)($_SESSION['userid'] ?? '');
    $activePortal = strtolower(trim((string)($_SESSION['active_portal'] ?? '')));
    $hasParentProfile = false;
    $hasTeacherProfile = false;

    if ($sessionUsername !== '') {
        $stmtHasParent = $pdo->prepare("SELECT id FROM parents WHERE username = :u LIMIT 1");
        $stmtHasParent->execute([':u' => $sessionUsername]);
        $hasParentProfile = (bool)$stmtHasParent->fetch(PDO::FETCH_ASSOC);
    }
    $stmtHasTeacher = $pdo->prepare("
        SELECT id
        FROM teachers
        WHERE (user_id = :uid AND :uid <> '')
           OR LOWER(email) = LOWER(:em)
        LIMIT 1
    ");
    $stmtHasTeacher->execute([':uid' => $sessionUserId, ':em' => $sessionUsername]);
    $hasTeacherProfile = (bool)$stmtHasTeacher->fetch(PDO::FETCH_ASSOC);

    if ($hasParentProfile && $hasTeacherProfile) {
        if (in_array($activePortal, ['parent', 'teacher'], true)) {
            $dashboardRole = $activePortal;
        } else {
            $dashboardRole = 'teacher';
            $_SESSION['active_portal'] = 'teacher';
        }
    }

    /* ═══ PARENT DASHBOARD ═══ */
    if ($dashboardRole === 'parent') {
        $sessionUsername = $_SESSION['username'] ?? '';
        $sessionUserId = (string)($_SESSION['userid'] ?? '');
        if ($sessionUsername === '') {
            throw new Exception("Session username missing. Please logout and login again.");
        }

        $stmtParent = $pdo->prepare("SELECT id, full_name, email FROM parents WHERE username = :u LIMIT 1");
        $stmtParent->execute([':u' => $sessionUsername]);
        $parentProfile = $stmtParent->fetch(PDO::FETCH_ASSOC);

        if ($parentProfile) {
            $parentDbId = (int)$parentProfile['id'];
            $stmtKids = $pdo->prepare("
                SELECT s.id, s.student_id, s.student_name, s.dob, s.gender, s.registration_date, s.approval_status,
                       e.status AS enrolment_status,
                       COALESCE(c.class_name, s.class_option) AS class_option,
                       COALESCE(e.fee_plan, s.payment_plan) AS payment_plan,
                       COALESCE(e.fee_amount, s.payment_amount) AS payment_amount,
                       COALESCE(e.payment_ref, s.payment_reference) AS payment_reference,
                       COALESCE(e.proof_path, s.payment_proof) AS payment_proof
                FROM students s
                LEFT JOIN pcm_enrolments e ON e.student_id = s.id
                LEFT JOIN class_assignments ca ON ca.student_id = s.id
                LEFT JOIN classes c ON c.id = ca.class_id
                WHERE s.parentId = :pid ORDER BY s.id DESC
            ");
            $stmtKids->execute([':pid' => $parentDbId]);
            $myChildren = $stmtKids->fetchAll(PDO::FETCH_ASSOC);

            if (bbcc_table_exists($pdo, 'pcm_fee_payments')) {
                $stmtParentFees = $pdo->prepare("
                    SELECT
                        s.student_name,
                        f.instalment_label,
                        f.status,
                        COALESCE(f.due_amount, 0) AS due_amount,
                        COALESCE(f.paid_amount, 0) AS paid_amount
                    FROM pcm_fee_payments f
                    INNER JOIN students s ON s.id = f.student_id
                    WHERE s.parentId = :pid
                      AND COALESCE(f.due_amount, 0) > 0
                    ORDER BY s.student_name ASC, f.id ASC
                ");
                $stmtParentFees->execute([':pid' => $parentDbId]);
                $feeRows = $stmtParentFees->fetchAll(PDO::FETCH_ASSOC);

                foreach ($feeRows as $fr) {
                    $status = strtolower(trim((string)($fr['status'] ?? '')));
                    $due = (float)($fr['due_amount'] ?? 0);
                    $paid = (float)($fr['paid_amount'] ?? 0);
                    $balance = max($due - $paid, 0);
                    $label = trim((string)($fr['instalment_label'] ?? 'Installment'));
                    $studentName = trim((string)($fr['student_name'] ?? 'Child'));

                    if ($status === 'pending') {
                        $parentFeeSummary['pending_count']++;
                        $parentFeeSummary['pending_amount'] += ($paid > 0 ? $paid : $due);
                        continue;
                    }

                    if (in_array($status, ['unpaid', 'rejected'], true) && $balance > 0) {
                        $parentFeeSummary['outstanding_count']++;
                        $parentFeeSummary['outstanding_amount'] += $balance;
                        if (count($parentFeeSummary['items']) < 6) {
                            $parentFeeSummary['items'][] = [
                                'student_name' => $studentName,
                                'instalment_label' => $label,
                                'balance' => $balance,
                            ];
                        }
                    }
                }
            }

            $stmtParentAttended = $pdo->prepare("
                SELECT COUNT(*) 
                FROM attendance a
                INNER JOIN students s ON s.id = a.student_id
                WHERE s.parentId = :pid AND LOWER(COALESCE(a.status,'')) = 'present'
            ");
            $stmtParentAttended->execute([':pid' => $parentDbId]);
            $parentClassesAttended = (int)$stmtParentAttended->fetchColumn();

            $stmtParentTerms = $pdo->prepare("
                SELECT
                    s.id AS student_pk,
                    s.student_name,
                    s.student_id,
                    SUM(CASE WHEN LOWER(COALESCE(a.status,''))='present' AND MONTH(a.attendance_date) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS t1,
                    SUM(CASE WHEN LOWER(COALESCE(a.status,''))='present' AND MONTH(a.attendance_date) BETWEEN 4 AND 6 THEN 1 ELSE 0 END) AS t2,
                    SUM(CASE WHEN LOWER(COALESCE(a.status,''))='present' AND MONTH(a.attendance_date) BETWEEN 7 AND 9 THEN 1 ELSE 0 END) AS t3,
                    SUM(CASE WHEN LOWER(COALESCE(a.status,''))='present' AND MONTH(a.attendance_date) BETWEEN 10 AND 12 THEN 1 ELSE 0 END) AS t4
                FROM students s
                LEFT JOIN attendance a ON a.student_id = s.id
                WHERE s.parentId = :pid
                GROUP BY s.id, s.student_name, s.student_id
                ORDER BY s.student_name ASC
            ");
            $stmtParentTerms->execute([':pid' => $parentDbId]);
            $parentChildTermProgress = $stmtParentTerms->fetchAll(PDO::FETCH_ASSOC);

            $stmtTermTotals = $pdo->query("
                SELECT
                    COALESCE(term1_total_classes, 0) AS term1_total_classes,
                    COALESCE(term2_total_classes, 0) AS term2_total_classes,
                    COALESCE(term3_total_classes, 0) AS term3_total_classes,
                    COALESCE(term4_total_classes, 0) AS term4_total_classes
                FROM fees_settings
                WHERE id = 1
                LIMIT 1
            ");
            $totalRow = $stmtTermTotals->fetch(PDO::FETCH_ASSOC) ?: [];
            $parentTermTotals = [
                1 => (int)($totalRow['term1_total_classes'] ?? 0),
                2 => (int)($totalRow['term2_total_classes'] ?? 0),
                3 => (int)($totalRow['term3_total_classes'] ?? 0),
                4 => (int)($totalRow['term4_total_classes'] ?? 0),
            ];
        }

    }

    /* ═══ ADMIN DASHBOARD STATS ═══ */
    if ($dashboardRole !== 'parent') {
        // Events
        $totalEvents    = (int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
        $bookedEvents   = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='Booked'")->fetchColumn();
        $availableEvents= (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='Available'")->fetchColumn();

        // Upcoming events (next 5)
        $stmtUp = $pdo->prepare("SELECT id, title, event_date, start_time, location, status FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");
        $stmtUp->execute();
        $upcomingEvents = $stmtUp->fetchAll(PDO::FETCH_ASSOC);

        // Bookings
        $totalBookings  = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
        $pendingBookings= (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='Pending'")->fetchColumn();

        // Recent students (last 5)
        $stmtRecSt = $pdo->query("SELECT student_id, student_name, class_option, approval_status, registration_date FROM students ORDER BY id DESC LIMIT 5");
        $recentStudents = $stmtRecSt->fetchAll(PDO::FETCH_ASSOC);

        // Recent bookings (last 5)
        $stmtRecBk = $pdo->prepare("SELECT b.id, b.name, b.email, b.status, b.created_at, e.title AS event_title FROM bookings b LEFT JOIN events e ON b.event_id = e.id ORDER BY b.id DESC LIMIT 5");
        $stmtRecBk->execute();
        $recentBookings = $stmtRecBk->fetchAll(PDO::FETCH_ASSOC);

        // Contact messages
        $contactMessages = (int)$pdo->query("SELECT COUNT(*) FROM contact")->fetchColumn();

        if (!$isWebsiteAdminDashboard) {
            // Students
            $totalStudents   = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
            $pendingStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Pending'")->fetchColumn();
            $approvedStudents= (int)$pdo->query("SELECT COUNT(*) FROM students WHERE approval_status='Approved'")->fetchColumn();

            // Parents
            $totalParents = (int)$pdo->query("SELECT COUNT(*) FROM parents")->fetchColumn();
        }

        if ($dashboardRole === 'teacher') {
            $sessionUserId = (string)($_SESSION['userid'] ?? '');
            $sessionUsername = (string)($_SESSION['username'] ?? '');

            $stmtTeacher = $pdo->prepare("
                SELECT id
                FROM teachers
                WHERE (user_id = :uid AND :uid <> '')
                   OR LOWER(email) = LOWER(:em)
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmtTeacher->execute([':uid' => $sessionUserId, ':em' => $sessionUsername]);
            $teacherRow = $stmtTeacher->fetch(PDO::FETCH_ASSOC);
            $teacherId = (int)($teacherRow['id'] ?? 0);
            $dashboardTeacherId = $teacherId;

            if ($teacherId > 0) {
                $stmtTeacherClassNames = $pdo->prepare("
                    SELECT DISTINCT c.class_name
                    FROM classes c
                    WHERE c.teacher_id = :teacher_id
                    ORDER BY c.class_name ASC
                ");
                $stmtTeacherClassNames->execute([':teacher_id' => $teacherId]);
                $teacherAssignedClassNames = array_values(array_filter(array_map(
                    static fn($r) => trim((string)($r['class_name'] ?? '')),
                    $stmtTeacherClassNames->fetchAll(PDO::FETCH_ASSOC)
                )));

                $stmtTeacherStudentStats = $pdo->prepare("
                    SELECT
                        COUNT(DISTINCT s.id) AS total_students,
                        COUNT(DISTINCT CASE WHEN LOWER(COALESCE(s.approval_status,'')) = 'approved' THEN s.id END) AS approved_students,
                        COUNT(DISTINCT CASE WHEN LOWER(COALESCE(s.approval_status,'')) = 'pending' THEN s.id END) AS pending_students
                    FROM classes c
                    INNER JOIN class_assignments ca ON ca.class_id = c.id
                    INNER JOIN students s ON s.id = ca.student_id
                    WHERE c.teacher_id = :teacher_id
                ");
                $stmtTeacherStudentStats->execute([':teacher_id' => $teacherId]);
                $teacherStudentStats = $stmtTeacherStudentStats->fetch(PDO::FETCH_ASSOC) ?: [];
                $totalStudents = (int)($teacherStudentStats['total_students'] ?? 0);
                $approvedStudents = (int)($teacherStudentStats['approved_students'] ?? 0);
                $pendingStudents = (int)($teacherStudentStats['pending_students'] ?? 0);

                $stmtTeacherAttendance = $pdo->prepare("
                    SELECT a.attendance_date, s.student_name, s.student_id, c.class_name, a.status
                    FROM attendance a
                    INNER JOIN classes c ON c.id = a.class_id
                    INNER JOIN students s ON s.id = a.student_id
                    WHERE c.teacher_id = :teacher_id
                    ORDER BY a.attendance_date DESC, a.id DESC
                    LIMIT 15
                ");
                $stmtTeacherAttendance->execute([':teacher_id' => $teacherId]);
                $dashboardAttendance = $stmtTeacherAttendance->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $totalStudents = 0;
                $approvedStudents = 0;
                $pendingStudents = 0;
            }
            $attendanceTitle = 'My Class Attendance Records';
            $attendanceViewLink = 'teacher-attendance';
        } else {
            $stmtAdminAttendance = $pdo->query("
                SELECT a.attendance_date, s.student_name, s.student_id, c.class_name, a.status
                FROM attendance a
                INNER JOIN students s ON s.id = a.student_id
                LEFT JOIN classes c ON c.id = a.class_id
                ORDER BY a.attendance_date DESC, a.id DESC
                LIMIT 15
            ");
            $dashboardAttendance = $stmtAdminAttendance->fetchAll(PDO::FETCH_ASSOC);
            $attendanceTitle = 'Recent Attendance Records';
            $attendanceViewLink = 'attendanceManagement';

            // Attendance summary by campus and class (Present / Absent / Grand Total)
            $campusLabels = $campusChoiceLabels;
            $campusAttendanceSummary = [
                'c1' => [
                    'label' => (string)($campusLabels['c1'] ?? 'Campus 1'),
                    'rows' => [],
                    'totals' => ['present' => 0, 'absent' => 0, 'grand' => 0],
                ],
                'c2' => [
                    'label' => (string)($campusLabels['c2'] ?? 'Campus 2'),
                    'rows' => [],
                    'totals' => ['present' => 0, 'absent' => 0, 'grand' => 0],
                ],
            ];

            $stmtCampusAttendance = $pdo->prepare("
                SELECT
                    LOWER(COALESCE(NULLIF(TRIM(c.campus_key), ''), 'c1')) AS campus_key,
                    COALESCE(NULLIF(TRIM(c.class_name), ''), 'Unassigned Class') AS class_name,
                    SUM(CASE WHEN LOWER(COALESCE(a.status, '')) = 'present' THEN 1 ELSE 0 END) AS present_count,
                    SUM(CASE WHEN LOWER(COALESCE(a.status, '')) = 'absent' THEN 1 ELSE 0 END) AS absent_count
                FROM attendance a
                LEFT JOIN classes c ON c.id = a.class_id
                WHERE DATE(a.attendance_date) = :summary_date
                GROUP BY campus_key, class_name
                ORDER BY campus_key ASC, class_name ASC
            ");
            $stmtCampusAttendance->execute([':summary_date' => $summaryDate]);
            $campusRows = $stmtCampusAttendance->fetchAll(PDO::FETCH_ASSOC);

            foreach ($campusRows as $row) {
                $campusKey = strtolower((string)($row['campus_key'] ?? 'c1'));
                if (!isset($campusAttendanceSummary[$campusKey])) {
                    continue;
                }
                $present = (int)($row['present_count'] ?? 0);
                $absent = (int)($row['absent_count'] ?? 0);
                $grand = $present + $absent;

                $campusAttendanceSummary[$campusKey]['rows'][] = [
                    'class_name' => (string)($row['class_name'] ?? 'Class'),
                    'present' => $present,
                    'absent' => $absent,
                    'grand' => $grand,
                ];
                $campusAttendanceSummary[$campusKey]['totals']['present'] += $present;
                $campusAttendanceSummary[$campusKey]['totals']['absent'] += $absent;
                $campusAttendanceSummary[$campusKey]['totals']['grand'] += $grand;
            }

            foreach ($campusAttendanceSummary as $summary) {
                $campusAttendanceOverall['present'] += (int)($summary['totals']['present'] ?? 0);
                $campusAttendanceOverall['absent'] += (int)($summary['totals']['absent'] ?? 0);
                $campusAttendanceOverall['grand'] += (int)($summary['totals']['grand'] ?? 0);
            }

            // Kiosk sign-in/out summary by campus and class
            $campusKioskSummary = [
                'c1' => [
                    'label' => (string)($campusLabels['c1'] ?? 'Campus 1'),
                    'rows' => [],
                    'totals' => ['registered' => 0, 'sign_in' => 0, 'sign_out' => 0, 'grand' => 0],
                ],
                'c2' => [
                    'label' => (string)($campusLabels['c2'] ?? 'Campus 2'),
                    'rows' => [],
                    'totals' => ['registered' => 0, 'sign_in' => 0, 'sign_out' => 0, 'grand' => 0],
                ],
            ];

            $stmtCampusKiosk = $pdo->prepare("
                SELECT
                    LOWER(COALESCE(NULLIF(TRIM(c.campus_key), ''), 'c1')) AS campus_key,
                    COALESCE(NULLIF(TRIM(c.class_name), ''), 'Unassigned Class') AS class_name,
                    COUNT(DISTINCT ca.student_id) AS registered_count,
                    SUM(CASE WHEN k.time_in IS NOT NULL THEN 1 ELSE 0 END) AS sign_in_count,
                    SUM(CASE WHEN k.time_out IS NOT NULL THEN 1 ELSE 0 END) AS sign_out_count
                FROM classes c
                LEFT JOIN class_assignments ca ON ca.class_id = c.id
                LEFT JOIN pcm_kiosk_log k
                    ON k.child_id = ca.student_id
                   AND k.log_date = :summary_date
                GROUP BY c.id, campus_key, class_name
                ORDER BY campus_key ASC, class_name ASC
            ");
            $stmtCampusKiosk->execute([':summary_date' => $summaryDate]);
            $kioskRows = $stmtCampusKiosk->fetchAll(PDO::FETCH_ASSOC);

            foreach ($kioskRows as $row) {
                $campusKey = strtolower((string)($row['campus_key'] ?? 'c1'));
                if (!isset($campusKioskSummary[$campusKey])) {
                    continue;
                }
                $registered = (int)($row['registered_count'] ?? 0);
                $signIn = (int)($row['sign_in_count'] ?? 0);
                $signOut = (int)($row['sign_out_count'] ?? 0);
                $grand = $signIn + $signOut;

                $campusKioskSummary[$campusKey]['rows'][] = [
                    'class_name' => (string)($row['class_name'] ?? 'Class'),
                    'registered' => $registered,
                    'sign_in' => $signIn,
                    'sign_out' => $signOut,
                    'grand' => $grand,
                ];
                $campusKioskSummary[$campusKey]['totals']['registered'] += $registered;
                $campusKioskSummary[$campusKey]['totals']['sign_in'] += $signIn;
                $campusKioskSummary[$campusKey]['totals']['sign_out'] += $signOut;
                $campusKioskSummary[$campusKey]['totals']['grand'] += $grand;
            }

            foreach ($campusKioskSummary as $summary) {
                $campusKioskOverall['registered'] += (int)($summary['totals']['registered'] ?? 0);
                $campusKioskOverall['sign_in'] += (int)($summary['totals']['sign_in'] ?? 0);
                $campusKioskOverall['sign_out'] += (int)($summary['totals']['sign_out'] ?? 0);
                $campusKioskOverall['grand'] += (int)($summary['totals']['grand'] ?? 0);
            }
        }
    }

    /* ═══ FEE COLLECTION DATA ═══ */
    $feeCollected    = 0;
    $feePending      = 0;
    $feeTotal        = 0;
    $feePendingCount = 0;
    $feeVerifiedCount= 0;
    $todaySignIns    = 0;
    $todaySignOuts   = 0;
    $totalClasses    = 0;

    if ($dashboardRole !== 'parent') {
        if (!$isWebsiteAdminDashboard) {
            // Fee collection stats
            $stmtFees = $pdo->query("SELECT
                COALESCE(SUM(due_amount), 0) AS total_due,
                COALESCE(SUM(CASE WHEN status = 'Verified' THEN paid_amount ELSE 0 END), 0) AS collected,
                COALESCE(SUM(CASE WHEN status IN ('Unpaid','Pending','Rejected') THEN due_amount ELSE 0 END), 0) AS pending,
                SUM(CASE WHEN status = 'Verified' THEN 1 ELSE 0 END) AS verified_count,
                SUM(CASE WHEN status IN ('Unpaid','Pending') THEN 1 ELSE 0 END) AS pending_count
                FROM pcm_fee_payments");
            $feeRow = $stmtFees->fetch(PDO::FETCH_ASSOC);
            $feeTotal        = (float)($feeRow['total_due'] ?? 0);
            $feeCollected    = (float)($feeRow['collected'] ?? 0);
            $feePending      = (float)($feeRow['pending'] ?? 0);
            $feeVerifiedCount= (int)($feeRow['verified_count'] ?? 0);
            $feePendingCount = (int)($feeRow['pending_count'] ?? 0);

            $stmtFeeTable = $pdo->query("
                SELECT
                    f.id,
                    s.student_name,
                    COALESCE(s.student_id, CONCAT('ID-', s.id)) AS public_student_id,
                    f.instalment_label,
                    COALESCE(f.due_amount, 0) AS due_amount,
                    f.status
                FROM pcm_fee_payments f
                LEFT JOIN students s ON s.id = f.student_id
                WHERE COALESCE(f.due_amount, 0) > 0
                ORDER BY f.id DESC
                LIMIT 8
            ");
            $dashboardFeePayments = $stmtFeeTable->fetchAll(PDO::FETCH_ASSOC);

            // Today's kiosk attendance
            $stmtToday = $pdo->query("SELECT
                SUM(CASE WHEN time_in IS NOT NULL THEN 1 ELSE 0 END) AS sign_ins,
                SUM(CASE WHEN time_out IS NOT NULL THEN 1 ELSE 0 END) AS sign_outs
                FROM pcm_kiosk_log WHERE log_date = CURDATE()");
            $todayRow = $stmtToday->fetch(PDO::FETCH_ASSOC);
            $todaySignIns  = (int)($todayRow['sign_ins'] ?? 0);
            $todaySignOuts = (int)($todayRow['sign_outs'] ?? 0);

            // Total classes
            $totalClasses = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
        }
    }

    /* ═══ CLASSROOM ACTIVITY FEED (ALL ROLES) ═══ */
    if (
        bbcc_table_exists($pdo, 'classroom_announcements') &&
        bbcc_table_exists($pdo, 'classroom_announcement_classes') &&
        bbcc_table_exists($pdo, 'classroom_reports') &&
        bbcc_table_exists($pdo, 'classroom_report_comments')
    ) {
        $activityItems = [];
        $appendActivity = static function (string $type, string $title, string $detail, string $at, string $url) use (&$activityItems): void {
            if (trim($at) === '') return;
            $activityItems[] = [
                'type' => $type,
                'title' => $title,
                'detail' => $detail,
                'at' => $at,
                'url' => $url,
            ];
        };

        if ($dashboardRole === 'parent' && (int)$parentDbId > 0) {
            $stmtA = $pdo->prepare("
                SELECT a.created_at, a.title, a.category
                FROM classroom_announcements a
                WHERE a.scope_type = 'all_classes'
                   OR EXISTS (
                        SELECT 1
                        FROM classroom_announcement_classes ac
                        INNER JOIN class_assignments ca ON ca.class_id = ac.class_id
                        INNER JOIN students s ON s.id = ca.student_id
                        WHERE ac.announcement_id = a.id
                          AND s.parentId = :pid
                   )
                ORDER BY a.created_at DESC
                LIMIT 6
            ");
            $stmtA->execute([':pid' => (int)$parentDbId]);
            foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $appendActivity(
                    'announcement',
                    (string)($row['title'] ?? 'Classroom Announcement'),
                    (string)($row['category'] ?? 'Announcement'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=announcements&as=parent'
                );
            }

            $stmtR = $pdo->prepare("
                SELECT r.created_at, r.report_title, s.student_name
                FROM classroom_reports r
                INNER JOIN students s ON s.id = r.student_id
                WHERE s.parentId = :pid
                ORDER BY r.created_at DESC
                LIMIT 8
            ");
            $stmtR->execute([':pid' => (int)$parentDbId]);
            foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $appendActivity(
                    'report',
                    'Report for ' . (string)($row['student_name'] ?? 'Student'),
                    (string)($row['report_title'] ?? 'Student progress note updated'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=reports&as=parent'
                );
            }
        } elseif ($dashboardRole === 'teacher' && $dashboardTeacherId > 0) {
            $stmtA = $pdo->prepare("
                SELECT DISTINCT a.created_at, a.title, a.category
                FROM classroom_announcements a
                INNER JOIN classroom_announcement_classes ac ON ac.announcement_id = a.id
                INNER JOIN classes c ON c.id = ac.class_id
                WHERE c.teacher_id = :tid
                ORDER BY a.created_at DESC
                LIMIT 6
            ");
            $stmtA->execute([':tid' => $dashboardTeacherId]);
            foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $appendActivity(
                    'announcement',
                    (string)($row['title'] ?? 'Classroom Announcement'),
                    (string)($row['category'] ?? 'Announcement'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=announcements&as=teacher'
                );
            }

            $stmtR = $pdo->prepare("
                SELECT r.created_at, r.report_title, s.student_name
                FROM classroom_reports r
                INNER JOIN students s ON s.id = r.student_id
                INNER JOIN classes c ON c.id = r.class_id
                WHERE c.teacher_id = :tid
                ORDER BY r.created_at DESC
                LIMIT 8
            ");
            $stmtR->execute([':tid' => $dashboardTeacherId]);
            foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $appendActivity(
                    'report',
                    'Report for ' . (string)($row['student_name'] ?? 'Student'),
                    (string)($row['report_title'] ?? 'Student progress note posted'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=reports&as=teacher'
                );
            }

            $stmtC = $pdo->prepare("
                SELECT cm.created_at, s.student_name
                FROM classroom_report_comments cm
                INNER JOIN classroom_reports r ON r.id = cm.report_id
                INNER JOIN students s ON s.id = r.student_id
                INNER JOIN classes c ON c.id = r.class_id
                WHERE c.teacher_id = :tid
                ORDER BY cm.created_at DESC
                LIMIT 8
            ");
            $stmtC->execute([':tid' => $dashboardTeacherId]);
            foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $appendActivity(
                    'comment',
                    'Parent comment received',
                    'On report for ' . (string)($row['student_name'] ?? 'Student'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=reports&as=teacher'
                );
            }
        } elseif ($dashboardRole !== 'parent') {
            $rowsA = $pdo->query("SELECT created_at, title, category FROM classroom_announcements ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rowsA as $row) {
                $appendActivity(
                    'announcement',
                    (string)($row['title'] ?? 'Classroom Announcement'),
                    (string)($row['category'] ?? 'Announcement'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=announcements'
                );
            }

            $rowsR = $pdo->query("
                SELECT r.created_at, r.report_title, s.student_name
                FROM classroom_reports r
                INNER JOIN students s ON s.id = r.student_id
                ORDER BY r.created_at DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rowsR as $row) {
                $appendActivity(
                    'report',
                    'Report for ' . (string)($row['student_name'] ?? 'Student'),
                    (string)($row['report_title'] ?? 'Student progress note posted'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=reports'
                );
            }

            $rowsC = $pdo->query("
                SELECT cm.created_at, s.student_name
                FROM classroom_report_comments cm
                INNER JOIN classroom_reports r ON r.id = cm.report_id
                INNER JOIN students s ON s.id = r.student_id
                ORDER BY cm.created_at DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rowsC as $row) {
                $appendActivity(
                    'comment',
                    'Parent comment received',
                    'On report for ' . (string)($row['student_name'] ?? 'Student'),
                    (string)($row['created_at'] ?? ''),
                    'dzongkha-classroom?tab=reports'
                );
            }
        }

        usort($activityItems, static function (array $a, array $b): int {
            return strtotime((string)$b['at']) <=> strtotime((string)$a['at']);
        });
        $recentClassroomActivity = array_slice($activityItems, 0, 8);
    }

} catch (PDOException $e) {
    bbcc_fail_db($e);
} catch (Exception $e) {
    bbcc_fail("Service temporarily unavailable. Please try again shortly.", $e);
}


function badge_class($st) {
    $st = strtolower($st ?? '');
    if ($st === 'pending') return 'warning';
    if ($st === 'approved') return 'success';
    if ($st === 'rejected') return 'danger';
    return 'secondary';
}

function bbcc_ensure_term_class_total_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $cols = ['term1_total_classes','term2_total_classes','term3_total_classes','term4_total_classes'];
    foreach ($cols as $col) {
        $stmt = $pdo->query("SHOW COLUMNS FROM fees_settings LIKE " . $pdo->quote($col));
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE fees_settings ADD COLUMN {$col} INT NULL");
        }
    }
    $done = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard - BBCC Admin</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <style>
    /* ═══ Dashboard Styles ═══ */
    .dash-card {
        border: none;
        border-radius: 14px;
        overflow: hidden;
        transition: all 0.3s ease;
        height: 100%;
    }
    .dash-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 35px rgba(0,0,0,0.1) !important;
    }
    .dash-stat-card {
        position: relative;
        padding: 24px;
    }
    .dash-stat-card .stat-icon {
        width: 52px; height: 52px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: #fff;
        position: absolute;
        top: 24px; right: 24px;
    }
    .dash-stat-card .stat-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #8c8c9e;
        margin-bottom: 8px;
    }
    .dash-stat-card .stat-value {
        font-size: 2rem;
        font-weight: 800;
        color: #1a1a2e;
        line-height: 1;
        margin-bottom: 10px;
    }
    .dash-stat-card .stat-footer {
        font-size: 0.78rem;
        color: #666;
    }
    .dash-stat-card .stat-footer a {
        color: #4e73df;
        font-weight: 600;
        text-decoration: none;
    }
    .dash-stat-card .stat-footer a:hover { text-decoration: underline; }

    /* Color variants */
    .stat-icon.bg-students   { background: linear-gradient(135deg, #4e73df, #224abe); }
    .stat-icon.bg-parents    { background: linear-gradient(135deg, #1cc88a, #13855c); }
    .stat-icon.bg-events     { background: linear-gradient(135deg, #f6c23e, #dda20a); }
    .stat-icon.bg-bookings   { background: linear-gradient(135deg, #e74a3b, #be2617); }
    .stat-icon.bg-fees       { background: linear-gradient(135deg, #1cc88a, #13855c); }
    .stat-icon.bg-attendance { background: linear-gradient(135deg, #36b9cc, #258391); }
    .stat-icon.bg-classes    { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
    .stat-icon.bg-messages   { background: linear-gradient(135deg, #36b9cc, #258391); }

    /* Welcome banner */
    .welcome-banner {
        background: linear-gradient(135deg, #881b12 0%, #6b140d 100%);
        border-radius: 16px;
        padding: 30px 32px;
        color: #fff;
        position: relative;
        overflow: hidden;
        margin-bottom: 24px;
    }
    .welcome-banner::before {
        content: '';
        position: absolute;
        top: -40%; right: -10%;
        width: 300px; height: 300px;
        background: rgba(255,255,255,0.06);
        border-radius: 50%;
    }
    .welcome-banner::after {
        content: '';
        position: absolute;
        bottom: -50%; right: 15%;
        width: 200px; height: 200px;
        background: rgba(255,255,255,0.04);
        border-radius: 50%;
    }
    .welcome-banner h2 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 0 6px;
        position: relative; z-index: 1;
    }
    .welcome-banner p {
        opacity: 0.85;
        margin: 0;
        font-size: 0.92rem;
        position: relative; z-index: 1;
    }
    .welcome-banner .quick-actions {
        margin-top: 18px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        position: relative; z-index: 1;
    }
    .welcome-banner .quick-actions a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        background: rgba(255,255,255,0.15);
        color: #fff;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255,255,255,0.1);
    }
    .welcome-banner .quick-actions a:hover {
        background: rgba(255,255,255,0.25);
        transform: translateY(-1px);
    }

    /* Section title */
    .section-title {
        font-size: 0.88rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .section-title a {
        font-size: 0.78rem;
        font-weight: 600;
        color: #4e73df;
        text-decoration: none;
    }
    .section-title a:hover { text-decoration: underline; }

    /* Events list */
    .event-list-item {
        display: flex;
        align-items: center;
        padding: 14px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .event-list-item:last-child { border-bottom: none; }
    .event-date-badge {
        width: 50px; height: 54px;
        background: #f0f2ff;
        border-radius: 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin-right: 14px;
        flex-shrink: 0;
    }
    .event-date-badge .day {
        font-size: 1.15rem;
        font-weight: 800;
        color: #4e73df;
        line-height: 1;
    }
    .event-date-badge .month {
        font-size: 0.65rem;
        font-weight: 600;
        color: #8c8c9e;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .event-info { flex: 1; }
    .event-info .title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #1a1a2e;
        margin: 0;
    }
    .event-info .meta {
        font-size: 0.75rem;
        color: #999;
        margin: 2px 0 0;
    }
    .event-info .meta i { margin-right: 3px; }
    .event-status-dot {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .dot-booked    { background: #e8f5e9; color: #2e7d32; }
    .dot-available { background: #fff3e0; color: #e65100; }
    .dot-pending   { background: #fff8e1; color: #f57f17; }

    /* Fee collection widget */
    .fee-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #f5f5f5;
    }
    .fee-row:last-child { border-bottom: none; }
    .fee-row .label {
        font-size: 0.84rem;
        font-weight: 500;
        color: #555;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .fee-row .label .dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .fee-row .val {
        font-size: 0.9rem;
        font-weight: 700;
        color: #1a1a2e;
    }
    .fee-bar {
        height: 8px;
        border-radius: 4px;
        background: #f0f2f5;
        margin-top: 12px;
        overflow: hidden;
        display: flex;
    }
    .fee-bar .seg {
        height: 100%;
        transition: width 0.6s ease;
    }

    /* Recent table */
    .dash-table {
        width: 100%;
        font-size: 0.82rem;
    }
    .dash-table th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        color: #8c8c9e;
        padding: 8px 12px;
        border-bottom: 2px solid #f0f0f0;
    }
    .dash-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f5f5f5;
        color: #444;
        vertical-align: middle;
    }
    .dash-table tr:last-child td { border-bottom: none; }
    .dash-table .badge {
        font-size: 0.68rem;
        padding: 4px 10px;
        border-radius: 6px;
        font-weight: 600;
    }

    /* Parent Dashboard Cards */
    .parent-stat-card {
        background: #fff;
        border-radius: 14px;
        padding: 22px;
        text-align: center;
        box-shadow: 0 2px 15px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
    }
    .parent-stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    .parent-stat-card .icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 1.1rem;
        color: #fff;
    }
    .parent-stat-card .number {
        font-size: 1.6rem;
        font-weight: 800;
        color: #1a1a2e;
    }
    .parent-stat-card .label {
        font-size: 0.75rem;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    </style>
</head>

<body id="page-top">
<div id="wrapper">

    <?php include_once 'include/admin-nav.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <?php include_once 'include/admin-header.php'; ?>

            <div class="container-fluid">

            <?php if ($dashboardRole === 'parent'): ?>
                <!-- ═══════════════════════════════════════════ -->
                <!-- ═══ PARENT DASHBOARD ═══ -->
                <!-- ═══════════════════════════════════════════ -->

                <!-- Welcome -->
                <div class="welcome-banner">
                    <h2><i class="fas fa-namaste"></i> Welcome, <?php echo htmlspecialchars($parentProfile['full_name'] ?? $_SESSION['username'] ?? 'Parent'); ?>!</h2>
                    <p>Manage your children's enrollments, track attendance, and make fee payments from your dashboard.</p>
                    <div class="quick-actions">
                        <a href="mark-absenteeism"><i class="fas fa-clipboard-check"></i> Mark Absenteeism</a>
                    </div>
                </div>

                <!-- Parent Stats Row -->
                <?php
                    $totalKids = count($myChildren);
                    $approvedKids = count(array_filter($myChildren, fn($c) => strtolower($c['approval_status'] ?? '') === 'approved'));
                    $pendingKids  = count(array_filter($myChildren, fn($c) => strtolower($c['approval_status'] ?? '') === 'pending'));
                ?>
                <div class="row mb-4">
                    <div class="col-md-3 col-6 mb-3">
                        <div class="parent-stat-card">
                            <div class="icon" style="background: linear-gradient(135deg, #4e73df, #224abe);"><i class="fas fa-user-graduate"></i></div>
                            <div class="number"><?php echo $totalKids; ?></div>
                            <div class="label">Total Enrolled</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="parent-stat-card">
                            <div class="icon" style="background: linear-gradient(135deg, #1cc88a, #13855c);"><i class="fas fa-check-circle"></i></div>
                            <div class="number"><?php echo $approvedKids; ?></div>
                            <div class="label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="parent-stat-card">
                            <div class="icon" style="background: linear-gradient(135deg, #f6c23e, #dda20a);"><i class="fas fa-clock"></i></div>
                            <div class="number"><?php echo $pendingKids; ?></div>
                            <div class="label">Pending</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="parent-stat-card">
                            <div class="icon" style="background: linear-gradient(135deg, #e74a3b, #be2617);"><i class="fas fa-bell"></i></div>
                            <div class="number"><?php echo (int)$parentClassesAttended; ?></div>
                            <div class="label">Classes Attended</div>
                        </div>
                    </div>
                </div>

                <?php if ((int)$parentFeeSummary['outstanding_count'] > 0 || (int)$parentFeeSummary['pending_count'] > 0): ?>
                    <div class="card dash-card shadow mb-4">
                        <div class="card-body" style="padding:18px 22px;">
                            <div class="d-flex flex-wrap justify-content-between align-items-start">
                                <div>
                                    <div class="section-title mb-1" style="font-size:.95rem;">
                                        <i class="fas fa-exclamation-circle mr-2" style="color:#e74a3b;"></i>Fees Payment Reminder
                                    </div>
                                    <?php if ((int)$parentFeeSummary['outstanding_count'] > 0): ?>
                                        <div style="font-weight:700; color:#881b12;">
                                            Outstanding: $<?= number_format((float)$parentFeeSummary['outstanding_amount'], 2) ?>
                                            across <?= (int)$parentFeeSummary['outstanding_count'] ?> installment(s).
                                        </div>
                                    <?php endif; ?>
                                    <?php if ((int)$parentFeeSummary['pending_count'] > 0): ?>
                                        <div style="color:#8a6d3b; margin-top:4px;">
                                            Pending verification: <?= (int)$parentFeeSummary['pending_count'] ?> payment(s).
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2 mt-md-0">
                                    <a href="parent-fees" class="btn btn-sm btn-primary" style="border-radius:8px;">
                                        <i class="fas fa-credit-card mr-1"></i> Review Fees
                                    </a>
                                </div>
                            </div>
                            <?php if (!empty($parentFeeSummary['items'])): ?>
                                <div class="table-responsive mt-3">
                                    <table class="dash-table mb-0">
                                        <thead>
                                        <tr>
                                            <th>Child</th>
                                            <th>Installment</th>
                                            <th>Outstanding</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($parentFeeSummary['items'] as $fi): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$fi['student_name']) ?></td>
                                                <td><?= htmlspecialchars((string)$fi['instalment_label']) ?></td>
                                                <td>$<?= number_format((float)$fi['balance'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Children Table -->
                <div class="card dash-card shadow mb-4">
                    <div class="card-body p-0">
                        <div class="p-3 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid #f0f0f0;">
                            <div class="section-title mb-0">My Children Enrollments</div>
                            <a href="children-enrollment" class="btn btn-primary btn-sm" style="border-radius:8px;">
                                <i class="fas fa-plus"></i> Add New Student
                            </a>
                        </div>

                        <?php if (!$parentProfile): ?>
                            <div class="p-4">
                                <div class="alert alert-warning mb-0">
                                    <strong>Account not linked.</strong> Please contact admin to link your login
                                    (<strong><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></strong>).
                                </div>
                            </div>
                        <?php elseif (empty($myChildren)): ?>
                            <div class="p-4 text-center" style="color:#999;">
                                <i class="fas fa-inbox fa-3x mb-3" style="opacity:0.3;"></i>
                                <p>No enrollments yet. Click <strong>Add New Student</strong> to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="dash-table">
                                    <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Class</th>
                                        <th>Plan</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th>Proof</th>
                                        <th>Reg Date</th>
                                        <th>Status</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($myChildren as $c): ?>
                                        <?php
                                            $regStatus = strtolower((string)($c['approval_status'] ?? 'pending'));
                                            $enrolStatusRaw = trim((string)($c['enrolment_status'] ?? ''));
                                            if ($enrolStatusRaw !== '') {
                                                $statusLabel = $enrolStatusRaw;
                                                $statusClass = badge_class($enrolStatusRaw);
                                            } elseif ($regStatus === 'approved') {
                                                $statusLabel = 'Enrollment Not Submitted';
                                                $statusClass = 'warning';
                                            } elseif ($regStatus === 'rejected') {
                                                $statusLabel = 'Child Registration Rejected';
                                                $statusClass = 'danger';
                                            } else {
                                                $statusLabel = 'Child Registration Pending';
                                                $statusClass = 'warning';
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($c['student_id'] ?? ''); ?></td>
                                            <td><strong><?php echo htmlspecialchars($c['student_name'] ?? ''); ?></strong></td>
                                            <td><?php echo htmlspecialchars($c['class_option'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($c['payment_plan'] ?? '-'); ?></td>
                                            <td><?php echo isset($c['payment_amount']) ? '$'.htmlspecialchars($c['payment_amount']) : '-'; ?></td>
                                            <td style="max-width:180px; white-space:normal;"><?php echo htmlspecialchars($c['payment_reference'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($c['payment_proof'])): ?>
                                                    <a href="<?php echo htmlspecialchars($c['payment_proof']); ?>" target="_blank" style="color:#4e73df;">View</a>
                                                <?php else: ?>-<?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($c['registration_date'] ?? ''); ?></td>
                                            <td><span class="badge badge-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card dash-card shadow mb-4">
                    <div class="card-body" style="padding:22px 24px;">
                        <div class="section-title mb-2" style="font-size:.95rem;">
                            <span><i class="fas fa-clipboard-check mr-2" style="color:#36b9cc;"></i>Term Attendance Progress</span>
                            <a href="mark-absenteeism">View Attendance</a>
                        </div>
                        <div class="table-responsive">
                            <table class="dash-table">
                                <thead>
                                <tr><th>Child</th><th>Term 1</th><th>Term 2</th><th>Term 3</th><th>Term 4</th></tr>
                                </thead>
                                <tbody>
                                <?php if (empty($parentChildTermProgress)): ?>
                                    <tr>
                                        <td colspan="5" class="text-muted">No child records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($parentChildTermProgress as $row): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['student_name'] ?? ''); ?></strong>
                                                <?php if (!empty($row['student_id'])): ?>
                                                    <div style="font-size:.75rem;color:#8c8c9e;"><?php echo htmlspecialchars($row['student_id']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <?php for ($term = 1; $term <= 4; $term++): ?>
                                                <?php
                                                    $key = 't' . $term;
                                                    $att = (int)($row[$key] ?? 0);
                                                    $tot = (int)($parentTermTotals[$term] ?? 0);
                                                    $label = $att . ($tot > 0 ? (' / ' . $tot) : '') . ' classes';
                                                ?>
                                                <td><?php echo htmlspecialchars($label); ?></td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="font-size:.75rem;color:#8c8c9e;margin-top:8px;">
                            Terms are calculated by months: Term 1 (Jan-Mar), Term 2 (Apr-Jun), Term 3 (Jul-Sep), Term 4 (Oct-Dec).
                        </div>
                    </div>
                </div>

                <div class="card dash-card shadow mb-4">
                    <div class="card-body p-0">
                        <div class="p-3">
                            <div class="section-title mb-0">
                                <span><i class="fas fa-stream mr-2" style="color:#4e73df;"></i>Recent Classroom Activity</span>
                                <a href="dzongkha-classroom?as=parent">Open Classroom</a>
                            </div>
                        </div>
                        <?php if (empty($recentClassroomActivity)): ?>
                            <div class="p-4 text-center" style="color:#ccc;">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p class="mb-0" style="font-size:0.85rem;">No recent classroom activity yet</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="dash-table">
                                    <thead>
                                    <tr><th>Type</th><th>Activity</th><th>Details</th><th>Time</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recentClassroomActivity as $act): ?>
                                        <?php
                                            $type = strtolower((string)($act['type'] ?? 'activity'));
                                            $icon = 'fa-bullhorn';
                                            if ($type === 'report') $icon = 'fa-file-alt';
                                            if ($type === 'comment') $icon = 'fa-comment';
                                        ?>
                                        <tr>
                                            <td><i class="fas <?= $icon ?>" style="color:#4e73df;"></i> <?= htmlspecialchars(ucfirst($type)); ?></td>
                                            <td><a href="<?= htmlspecialchars((string)($act['url'] ?? 'dzongkha-classroom?as=parent')); ?>"><?= htmlspecialchars((string)($act['title'] ?? 'Activity')); ?></a></td>
                                            <td><?= htmlspecialchars((string)($act['detail'] ?? '-')); ?></td>
                                            <td><?= !empty($act['at']) ? date('d M Y, h:i A', strtotime((string)$act['at'])) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>


            <?php else: ?>
                <!-- ═══════════════════════════════════════════ -->
                <!-- ═══ ADMIN DASHBOARD ═══ -->
                <!-- ═══════════════════════════════════════════ -->
                <?php if ($isWebsiteAdminDashboard): ?>
                <div class="welcome-banner">
                    <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Website Admin'); ?>!</h2>
                    <p>Here's an overview of your website and event operations. Today is <?php echo date('l, d F Y'); ?>.</p>
                    <div class="quick-actions">
                        <a href="ourTeamSetup"><i class="fas fa-users-cog"></i> Team</a>
                        <a href="eventManagement"><i class="fas fa-calendar-alt"></i> Events</a>
                        <a href="bookingManagement"><i class="fas fa-bookmark"></i> Bookings</a>
                        <a href="viewFeedback"><i class="fas fa-envelope"></i> Contact Queries</a>
                    </div>
                </div>

                <div class="row mb-2">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-events"><i class="fas fa-calendar-alt"></i></div>
                                <div class="stat-label">Total Events</div>
                                <div class="stat-value"><?php echo $totalEvents; ?></div>
                                <div class="stat-footer">
                                    <span style="color:#1cc88a;"><i class="fas fa-check"></i> <?php echo $bookedEvents; ?> booked</span>
                                    &nbsp;&middot;&nbsp;
                                    <span style="color:#e65100;"><i class="fas fa-clock"></i> <?php echo $availableEvents; ?> available</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-bookings"><i class="fas fa-bookmark"></i></div>
                                <div class="stat-label">Booking Requests</div>
                                <div class="stat-value"><?php echo $totalBookings; ?></div>
                                <div class="stat-footer">
                                    <?php if ($pendingBookings > 0): ?>
                                        <a href="bookingManagement" style="color:#e74a3b;"><i class="fas fa-exclamation-circle"></i> <?php echo $pendingBookings; ?> pending approval</a>
                                    <?php else: ?>
                                        <span style="color:#1cc88a;"><i class="fas fa-check-circle"></i> All handled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-messages"><i class="fas fa-envelope"></i></div>
                                <div class="stat-label">Contact Messages</div>
                                <div class="stat-value"><?php echo $contactMessages; ?></div>
                                <div class="stat-footer"><a href="viewFeedback">View queries <i class="fas fa-arrow-right"></i></a></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-messages"><i class="fas fa-bell"></i></div>
                                <div class="stat-label">Unread Notifications</div>
                                <div class="stat-value"><?php echo (int)$unreadNotifications; ?></div>
                                <div class="stat-footer"><a href="notifications">Open notification center <i class="fas fa-arrow-right"></i></a></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-2">
                    <div class="col-lg-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body">
                                <div class="section-title">
                                    <span><i class="fas fa-calendar-day mr-2" style="color:#4e73df;"></i>Upcoming Events</span>
                                    <a href="eventManagement">View All</a>
                                </div>
                                <?php if (empty($upcomingEvents)): ?>
                                    <div class="text-center py-4" style="color:#ccc;">
                                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No upcoming events</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($upcomingEvents as $ev): ?>
                                        <?php
                                            $evDate = strtotime($ev['event_date']);
                                            $evDay  = date('d', $evDate);
                                            $evMon  = date('M', $evDate);
                                            $evTime = $ev['start_time'] ? date('g:i A', strtotime($ev['start_time'])) : '';
                                            $evStatusClass = strtolower($ev['status']) === 'booked' ? 'dot-booked' : (strtolower($ev['status']) === 'available' ? 'dot-available' : 'dot-pending');
                                        ?>
                                        <div class="event-list-item">
                                            <div class="event-date-badge">
                                                <span class="day"><?php echo $evDay; ?></span>
                                                <span class="month"><?php echo $evMon; ?></span>
                                            </div>
                                            <div class="event-info">
                                                <p class="title"><?php echo htmlspecialchars($ev['title']); ?></p>
                                                <p class="meta">
                                                    <?php if ($evTime): ?><i class="far fa-clock"></i> <?php echo $evTime; ?> &nbsp;<?php endif; ?>
                                                    <?php if ($ev['location']): ?><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ev['location']); ?><?php endif; ?>
                                                </p>
                                            </div>
                                            <span class="event-status-dot <?php echo $evStatusClass; ?>"><?php echo htmlspecialchars($ev['status']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body p-0">
                                <div class="p-3">
                                    <div class="section-title mb-0">
                                        <span><i class="fas fa-bookmark mr-2" style="color:#e74a3b;"></i>Recent Booking Requests</span>
                                        <a href="bookingManagement">View All</a>
                                    </div>
                                </div>
                                <?php if (empty($recentBookings)): ?>
                                    <div class="p-4 text-center" style="color:#ccc;">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No booking requests yet</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="dash-table">
                                            <thead>
                                            <tr><th>Name</th><th>Event</th><th>Date</th><th>Status</th></tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($recentBookings as $rb): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($rb['name'] ?? ''); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($rb['event_title'] ?? '-'); ?></td>
                                                    <td><?php echo $rb['created_at'] ? date('d M Y', strtotime($rb['created_at'])) : '-'; ?></td>
                                                    <td><span class="badge badge-<?php echo badge_class($rb['status']); ?>"><?php echo htmlspecialchars($rb['status'] ?? ''); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>

                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>!</h2>
                    <p>
                        <?php if ($dashboardRole === 'teacher'): ?>
                            Here's an overview of your classroom.
                        <?php else: ?>
                            Here's an overview of your community centre. Today is <?php echo date('l, d F Y'); ?>.
                        <?php endif; ?>
                    </p>
                    <div class="quick-actions">
                        <?php if ($dashboardRole === 'teacher'): ?>
                            <a href="teacher-attendance" style="background:#1cc88a;border-color:#1cc88a;">
                                <i class="fas fa-clipboard-list"></i> Take Attendance
                            </a>
                            <a href="attendance-records?as=teacher"><i class="fas fa-table"></i> Attendance Records</a>
                            <a href="dzongkha-classroom?as=teacher"><i class="fas fa-bullhorn"></i> Dzongkha Classroom</a>
                            <a href="parent-email"><i class="fas fa-envelope-open-text"></i> Send Parent Email</a>
                        <?php else: ?>
                            <a href="dzoClassManagement"><i class="fas fa-user-graduate"></i> Enrollments</a>
                            <a href="eventManagement"><i class="fas fa-calendar-alt"></i> Events</a>
                            <a href="bookingManagement"><i class="fas fa-bookmark"></i> Bookings</a>
                            <a href="admin-attendance"><i class="fas fa-clipboard-check"></i> Attendance</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Stats Cards Row ── -->
                <div class="row mb-2">
                    <!-- Total Students -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-students"><i class="fas fa-user-graduate"></i></div>
                                <div class="stat-label">Total Students</div>
                                <div class="stat-value"><?php echo $totalStudents; ?></div>
                                <div class="stat-footer">
                                    <?php if ($dashboardRole === 'teacher'): ?>
                                        <?php if (!empty($teacherAssignedClassNames)): ?>
                                            <span style="color:#4e73df;"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars(implode(', ', $teacherAssignedClassNames)); ?></span>
                                        <?php else: ?>
                                            <span style="color:#8c8c9e;"><i class="fas fa-info-circle"></i> No class assigned yet</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#1cc88a;"><i class="fas fa-check-circle"></i> <?php echo $approvedStudents; ?> approved</span>
                                        &nbsp;&middot;&nbsp;
                                        <span style="color:#f6c23e;"><i class="fas fa-clock"></i> <?php echo $pendingStudents; ?> pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($dashboardRole !== 'teacher'): ?>
                    <!-- Registered Parents -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-parents"><i class="fas fa-users"></i></div>
                                <div class="stat-label">Registered Parents</div>
                                <div class="stat-value"><?php echo $totalParents; ?></div>
                                <div class="stat-footer">
                                    <a href="dzoClassManagement">View all families <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Events -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-events"><i class="fas fa-calendar-alt"></i></div>
                                <div class="stat-label">Total Events</div>
                                <div class="stat-value"><?php echo $totalEvents; ?></div>
                                <div class="stat-footer">
                                    <span style="color:#1cc88a;"><i class="fas fa-check"></i> <?php echo $bookedEvents; ?> booked</span>
                                    &nbsp;&middot;&nbsp;
                                    <span style="color:#e65100;"><i class="fas fa-clock"></i> <?php echo $availableEvents; ?> available</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Bookings -->
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-bookings"><i class="fas fa-bookmark"></i></div>
                                <div class="stat-label">Booking Requests</div>
                                <div class="stat-value"><?php echo $totalBookings; ?></div>
                                <div class="stat-footer">
                                    <?php if ($pendingBookings > 0): ?>
                                        <a href="bookingManagement" style="color:#e74a3b;"><i class="fas fa-exclamation-circle"></i> <?php echo $pendingBookings; ?> pending approval</a>
                                    <?php else: ?>
                                        <span style="color:#1cc88a;"><i class="fas fa-check-circle"></i> All handled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card">
                                <div class="stat-icon bg-messages"><i class="fas fa-bell"></i></div>
                                <div class="stat-label">Unread Notifications</div>
                                <div class="stat-value"><?php echo (int)$unreadNotifications; ?></div>
                                <div class="stat-footer">
                                    <a href="notifications">Open notification center <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($dashboardRole !== 'teacher'): ?>
                <!-- ── Attendance By Campus & Class ── -->
                <div class="card dash-card shadow mb-4">
                    <div class="card-body p-0">
                        <div class="p-3" style="border-bottom: 1px solid #f0f0f0;">
                            <div class="section-title mb-1">
                                <span><i class="fas fa-school mr-2" style="color:#4e73df;"></i>Attendance Summary By Campus</span>
                                <a href="attendance-records">View Records</a>
                            </div>
                            <form method="GET" class="mb-2 d-flex align-items-center" style="gap:8px;">
                                <label for="summaryDate" class="mb-0" style="font-size:0.78rem;color:#8c8c9e;">Date:</label>
                                <input type="date" id="summaryDate" name="summary_date" class="form-control form-control-sm" style="max-width:180px;" value="<?= htmlspecialchars($summaryDate, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Load</button>
                            </form>
                            <div style="font-size:0.78rem;color:#8c8c9e;">
                                For: <strong><?= date('d M Y', strtotime($summaryDate)) ?></strong>
                                &nbsp;&middot;&nbsp;
                                Grand Total: <strong><?= (int)$campusAttendanceOverall['grand'] ?></strong>
                                &nbsp;&middot;&nbsp;
                                Present: <strong style="color:#1cc88a;"><?= (int)$campusAttendanceOverall['present'] ?></strong>
                                &nbsp;&middot;&nbsp;
                                Absent: <strong style="color:#e74a3b;"><?= (int)$campusAttendanceOverall['absent'] ?></strong>
                            </div>
                        </div>

                        <div class="row no-gutters">
                            <?php foreach (['c1', 'c2'] as $campusKey): ?>
                                <?php $summary = $campusAttendanceSummary[$campusKey] ?? ['label' => 'Campus', 'rows' => [], 'totals' => ['present' => 0, 'absent' => 0, 'grand' => 0]]; ?>
                                <div class="col-lg-6" style="border-right: <?= $campusKey === 'c1' ? '1px solid #f0f0f0' : 'none' ?>;">
                                    <div class="p-3">
                                        <div style="font-size:0.86rem;font-weight:700;color:#1a1a2e;margin-bottom:10px;">
                                            <?= htmlspecialchars((string)$summary['label']) ?>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="dash-table mb-0">
                                                <thead>
                                                <tr>
                                                    <th>Class</th>
                                                    <th>Present</th>
                                                    <th>Absent</th>
                                                    <th>Grand Total</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php if (empty($summary['rows'])): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-muted">No attendance records yet.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($summary['rows'] as $row): ?>
                                                        <tr>
                                                            <td><strong><?= htmlspecialchars((string)$row['class_name']) ?></strong></td>
                                                            <td><span style="color:#1cc88a;font-weight:700;"><?= (int)$row['present'] ?></span></td>
                                                            <td><span style="color:#e74a3b;font-weight:700;"><?= (int)$row['absent'] ?></span></td>
                                                            <td><strong><?= (int)$row['grand'] ?></strong></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr style="background:#fafbff;">
                                                        <td><strong>Total</strong></td>
                                                        <td><strong style="color:#1cc88a;"><?= (int)$summary['totals']['present'] ?></strong></td>
                                                        <td><strong style="color:#e74a3b;"><?= (int)$summary['totals']['absent'] ?></strong></td>
                                                        <td><strong><?= (int)$summary['totals']['grand'] ?></strong></td>
                                                    </tr>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card dash-card shadow mb-4">
                    <div class="card-body p-0">
                        <div class="p-3" style="border-bottom: 1px solid #f0f0f0;">
                            <div class="section-title mb-1">
                                <span><i class="fas fa-door-open mr-2" style="color:#36b9cc;"></i>Kiosk Sign In/Out Summary By Campus</span>
                                <a href="admin-attendance?tab=kiosk">View Kiosk Records</a>
                            </div>
                            <div style="font-size:0.78rem;color:#8c8c9e;">
                                For: <strong><?= date('d M Y', strtotime($summaryDate)) ?></strong>
                                &nbsp;&middot;&nbsp;
                                Registered: <strong style="color:#4e73df;"><?= (int)$campusKioskOverall['registered'] ?></strong>
                                &nbsp;&middot;&nbsp;
                                Grand Total: <strong><?= (int)$campusKioskOverall['grand'] ?></strong>
                                &nbsp;&middot;&nbsp;
                                Sign In: <strong style="color:#1cc88a;"><?= (int)$campusKioskOverall['sign_in'] ?></strong>
                                &nbsp;&middot;&nbsp;
                                Sign Out: <strong style="color:#e74a3b;"><?= (int)$campusKioskOverall['sign_out'] ?></strong>
                            </div>
                        </div>

                        <div class="row no-gutters">
                            <?php foreach (['c1', 'c2'] as $campusKey): ?>
                                <?php $summary = $campusKioskSummary[$campusKey] ?? ['label' => 'Campus', 'rows' => [], 'totals' => ['sign_in' => 0, 'sign_out' => 0, 'grand' => 0]]; ?>
                                <div class="col-lg-6" style="border-right: <?= $campusKey === 'c1' ? '1px solid #f0f0f0' : 'none' ?>;">
                                    <div class="p-3">
                                        <div style="font-size:0.86rem;font-weight:700;color:#1a1a2e;margin-bottom:10px;">
                                            <?= htmlspecialchars((string)$summary['label']) ?>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="dash-table mb-0">
                                                <thead>
                                                <tr>
                                                    <th>Class</th>
                                                    <th>Registered Students</th>
                                                    <th>Sign In</th>
                                                    <th>Sign Out</th>
                                                    <th>Grand Total</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php if (empty($summary['rows'])): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-muted">No class records yet.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($summary['rows'] as $row): ?>
                                                        <tr>
                                                            <td><strong><?= htmlspecialchars((string)$row['class_name']) ?></strong></td>
                                                            <td><span style="color:#4e73df;font-weight:700;"><?= (int)$row['registered'] ?></span></td>
                                                            <td><span style="color:#1cc88a;font-weight:700;"><?= (int)$row['sign_in'] ?></span></td>
                                                            <td><span style="color:#e74a3b;font-weight:700;"><?= (int)$row['sign_out'] ?></span></td>
                                                            <td><strong><?= (int)$row['grand'] ?></strong></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr style="background:#fafbff;">
                                                        <td><strong>Total</strong></td>
                                                        <td><strong style="color:#4e73df;"><?= (int)$summary['totals']['registered'] ?></strong></td>
                                                        <td><strong style="color:#1cc88a;"><?= (int)$summary['totals']['sign_in'] ?></strong></td>
                                                        <td><strong style="color:#e74a3b;"><?= (int)$summary['totals']['sign_out'] ?></strong></td>
                                                        <td><strong><?= (int)$summary['totals']['grand'] ?></strong></td>
                                                    </tr>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ── Row 2: Upcoming Events + Finance ── -->
                <div class="row mb-2">
                    <!-- Upcoming Events -->
                    <div class="col-lg-7 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body">
                                <div class="section-title">
                                    <span><i class="fas fa-calendar-day mr-2" style="color:#4e73df;"></i>Upcoming Events</span>
                                    <a href="eventManagement">View All</a>
                                </div>

                                <?php if (empty($upcomingEvents)): ?>
                                    <div class="text-center py-4" style="color:#ccc;">
                                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No upcoming events</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($upcomingEvents as $ev): ?>
                                        <?php
                                            $evDate = strtotime($ev['event_date']);
                                            $evDay  = date('d', $evDate);
                                            $evMon  = date('M', $evDate);
                                            $evTime = $ev['start_time'] ? date('g:i A', strtotime($ev['start_time'])) : '';
                                            $evStatusClass = strtolower($ev['status']) === 'booked' ? 'dot-booked' : (strtolower($ev['status']) === 'available' ? 'dot-available' : 'dot-pending');
                                        ?>
                                        <div class="event-list-item">
                                            <div class="event-date-badge">
                                                <span class="day"><?php echo $evDay; ?></span>
                                                <span class="month"><?php echo $evMon; ?></span>
                                            </div>
                                            <div class="event-info">
                                                <p class="title"><?php echo htmlspecialchars($ev['title']); ?></p>
                                                <p class="meta">
                                                    <?php if ($evTime): ?><i class="far fa-clock"></i> <?php echo $evTime; ?> &nbsp;<?php endif; ?>
                                                    <?php if ($ev['location']): ?><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ev['location']); ?><?php endif; ?>
                                                </p>
                                            </div>
                                            <span class="event-status-dot <?php echo $evStatusClass; ?>"><?php echo htmlspecialchars($ev['status']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Fee Collection Overview -->
                    <div class="col-lg-5 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body">
                                <div class="section-title">
                                    <span><i class="fas fa-money-check-alt mr-2" style="color:#1cc88a;"></i>Fee Collection</span>
                                    <a href="feesManagement">View All</a>
                                </div>

                                <div class="fee-row">
                                    <span class="label"><span class="dot" style="background:#1cc88a;"></span> Collected</span>
                                    <span class="val" style="color:#1cc88a;">$<?php echo number_format($feeCollected, 2); ?></span>
                                </div>
                                <div class="fee-row">
                                    <span class="label"><span class="dot" style="background:#e74a3b;"></span> Outstanding</span>
                                    <span class="val" style="color:#e74a3b;">$<?php echo number_format($feePending, 2); ?></span>
                                </div>
                                <div class="fee-row">
                                    <span class="label"><span class="dot" style="background:#4e73df;"></span> Total Expected</span>
                                    <span class="val">$<?php echo number_format($feeTotal, 2); ?></span>
                                </div>

                                <?php
                                    $collPct = $feeTotal > 0 ? round(($feeCollected / $feeTotal) * 100) : 0;
                                    $pendPct = 100 - $collPct;
                                ?>
                                <div class="fee-bar">
                                    <div class="seg" style="width:<?php echo $collPct; ?>%; background:#1cc88a;"></div>
                                    <div class="seg" style="width:<?php echo $pendPct; ?>%; background:#e74a3b;"></div>
                                </div>

                                <div style="margin-top:18px; padding:16px; background:linear-gradient(135deg,#e8f5e9,#c8e6c9); border-radius:10px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="font-size:0.84rem; font-weight:600; color:#555;">
                                            <i class="fas fa-percentage mr-1" style="color:#1cc88a;"></i> Collection Rate
                                        </span>
                                        <span style="font-size:1.3rem; font-weight:800; color:<?php echo $collPct >= 70 ? '#1cc88a' : ($collPct >= 40 ? '#f6c23e' : '#e74a3b'); ?>;">
                                            <?php echo $collPct; ?>%
                                        </span>
                                    </div>
                                </div>

                                <div style="margin-top:10px; display:flex; justify-content:center; gap:16px; font-size:0.76rem; color:#888;">
                                    <span><i class="fas fa-check-circle" style="color:#1cc88a;"></i> <?php echo $feeVerifiedCount; ?> verified</span>
                                    <span><i class="fas fa-clock" style="color:#f6c23e;"></i> <?php echo $feePendingCount; ?> pending</span>
                                </div>

                                <div class="table-responsive mt-3">
                                    <table class="dash-table mb-0">
                                        <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Installment</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($dashboardFeePayments)): ?>
                                            <tr><td colspan="4" class="text-muted">No payment records found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($dashboardFeePayments as $fp): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars((string)($fp['student_name'] ?? 'Student')) ?></strong>
                                                        <div style="font-size:.72rem;color:#8c8c9e;"><?= htmlspecialchars((string)($fp['public_student_id'] ?? '')) ?></div>
                                                    </td>
                                                    <td><?= htmlspecialchars((string)($fp['instalment_label'] ?? '-')) ?></td>
                                                    <td>$<?= number_format((float)($fp['due_amount'] ?? 0), 2) ?></td>
                                                    <td><span class="badge badge-<?= badge_class((string)($fp['status'] ?? 'Pending')) ?>"><?= htmlspecialchars((string)($fp['status'] ?? 'Pending')) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($dashboardRole !== 'teacher'): ?>
                <!-- ── Row 3: Recent Students + Recent Bookings ── -->
                <div class="row mb-2">
                    <!-- Recent Students -->
                    <div class="col-lg-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body p-0">
                                <div class="p-3">
                                    <div class="section-title mb-0">
                                        <span><i class="fas fa-user-graduate mr-2" style="color:#4e73df;"></i>Recent Enrollments</span>
                                        <a href="dzoClassManagement">View All</a>
                                    </div>
                                </div>
                                <?php if (empty($recentStudents)): ?>
                                    <div class="p-4 text-center" style="color:#ccc;">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No enrollments yet</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="dash-table">
                                            <thead>
                                            <tr><th>ID</th><th>Name</th><th>Class</th><th>Date</th><th>Status</th></tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($recentStudents as $rs): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($rs['student_id'] ?? ''); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($rs['student_name'] ?? ''); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($rs['class_option'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($rs['registration_date'] ?? ''); ?></td>
                                                    <td><span class="badge badge-<?php echo badge_class($rs['approval_status']); ?>"><?php echo htmlspecialchars($rs['approval_status'] ?? ''); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Bookings -->
                    <div class="col-lg-6 mb-4">
                        <div class="card dash-card shadow">
                            <div class="card-body p-0">
                                <div class="p-3">
                                    <div class="section-title mb-0">
                                        <span><i class="fas fa-bookmark mr-2" style="color:#e74a3b;"></i>Recent Booking Requests</span>
                                        <a href="bookingManagement">View All</a>
                                    </div>
                                </div>
                                <?php if (empty($recentBookings)): ?>
                                    <div class="p-4 text-center" style="color:#ccc;">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <p class="mb-0" style="font-size:0.85rem;">No booking requests yet</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="dash-table">
                                            <thead>
                                            <tr><th>Name</th><th>Event</th><th>Date</th><th>Status</th></tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($recentBookings as $rb): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($rb['name'] ?? ''); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($rb['event_title'] ?? '-'); ?></td>
                                                    <td><?php echo $rb['created_at'] ? date('d M Y', strtotime($rb['created_at'])) : '-'; ?></td>
                                                    <td><span class="badge badge-<?php echo badge_class($rb['status']); ?>"><?php echo htmlspecialchars($rb['status'] ?? ''); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($dashboardRole !== 'teacher'): ?>
                <div class="card dash-card shadow mb-4">
                    <div class="card-body p-0">
                        <div class="p-3">
                            <div class="section-title mb-0">
                                <span><i class="fas fa-clipboard-check mr-2" style="color:#36b9cc;"></i><?php echo htmlspecialchars($attendanceTitle); ?></span>
                                <a href="<?php echo htmlspecialchars($attendanceViewLink); ?>">View All</a>
                            </div>
                        </div>
                        <?php if (empty($dashboardAttendance)): ?>
                            <div class="p-4 text-center" style="color:#ccc;">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p class="mb-0" style="font-size:0.85rem;">No attendance records yet</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="dash-table">
                                    <thead>
                                    <tr><th>Date</th><th>Student</th><th>Class</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($dashboardAttendance as $ar): ?>
                                        <?php
                                            $attStatus = strtolower((string)($ar['status'] ?? ''));
                                            $attBadge = 'secondary';
                                            if ($attStatus === 'present') $attBadge = 'success';
                                            elseif ($attStatus === 'absent') $attBadge = 'danger';
                                            elseif ($attStatus === 'late') $attBadge = 'warning';
                                        ?>
                                        <tr>
                                            <td><?php echo !empty($ar['attendance_date']) ? date('d M Y', strtotime($ar['attendance_date'])) : '-'; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($ar['student_name'] ?? '-'); ?></strong>
                                                <?php if (!empty($ar['student_id'])): ?>
                                                    <div style="font-size:.75rem;color:#8c8c9e;"><?php echo htmlspecialchars($ar['student_id']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($ar['class_name'] ?? '-'); ?></td>
                                            <td><span class="badge badge-<?php echo $attBadge; ?>"><?php echo htmlspecialchars($ar['status'] ?? 'Unknown'); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card dash-card shadow mb-4">
                    <div class="card-body p-0">
                        <div class="p-3">
                            <div class="section-title mb-0">
                                <span><i class="fas fa-stream mr-2" style="color:#4e73df;"></i>Recent Classroom Activity</span>
                                <a href="<?= $dashboardRole === 'teacher' ? 'dzongkha-classroom?as=teacher' : 'dzongkha-classroom'; ?>">Open Classroom</a>
                            </div>
                        </div>
                        <?php if (empty($recentClassroomActivity)): ?>
                            <div class="p-4 text-center" style="color:#ccc;">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p class="mb-0" style="font-size:0.85rem;">No recent classroom activity yet</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="dash-table">
                                    <thead>
                                    <tr><th>Type</th><th>Activity</th><th>Details</th><th>Time</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recentClassroomActivity as $act): ?>
                                        <?php
                                            $type = strtolower((string)($act['type'] ?? 'activity'));
                                            $icon = 'fa-bullhorn';
                                            if ($type === 'report') $icon = 'fa-file-alt';
                                            if ($type === 'comment') $icon = 'fa-comment';
                                        ?>
                                        <tr>
                                            <td><i class="fas <?= $icon ?>" style="color:#4e73df;"></i> <?= htmlspecialchars(ucfirst($type)); ?></td>
                                            <td><a href="<?= htmlspecialchars((string)($act['url'] ?? 'dzongkha-classroom')); ?>"><?= htmlspecialchars((string)($act['title'] ?? 'Activity')); ?></a></td>
                                            <td><?= htmlspecialchars((string)($act['detail'] ?? '-')); ?></td>
                                            <td><?= !empty($act['at']) ? date('d M Y, h:i A', strtotime((string)$act['at'])) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Row 4: Quick Stats bar ── -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card" style="padding: 20px 24px;">
                                <div class="stat-icon bg-attendance" style="width:42px;height:42px;border-radius:10px;font-size:1rem;"><i class="fas fa-clipboard-check"></i></div>
                                <div class="stat-label">Today's Attendance</div>
                                <div class="stat-value" style="font-size:1.6rem;"><?php echo $todaySignIns; ?></div>
                                <div class="stat-footer">
                                    <span style="color:#1cc88a;"><i class="fas fa-sign-in-alt"></i> <?php echo $todaySignIns; ?> in</span>
                                    &middot;
                                    <span style="color:#e74a3b;"><i class="fas fa-sign-out-alt"></i> <?php echo $todaySignOuts; ?> out</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if ($dashboardRole !== 'teacher'): ?>
                    <div class="col-md-4 mb-3">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card" style="padding: 20px 24px;">
                                <div class="stat-icon bg-classes" style="width:42px;height:42px;border-radius:10px;font-size:1rem;"><i class="fas fa-chalkboard-teacher"></i></div>
                                <div class="stat-label">Active Classes</div>
                                <div class="stat-value" style="font-size:1.6rem;"><?php echo $totalClasses; ?></div>
                                <div class="stat-footer"><a href="admin-class-setup">Manage classes <i class="fas fa-arrow-right"></i></a></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card dash-card shadow">
                            <div class="dash-stat-card" style="padding: 20px 24px;">
                                <div class="stat-icon bg-messages" style="width:42px;height:42px;border-radius:10px;font-size:1rem;"><i class="fas fa-envelope"></i></div>
                                <div class="stat-label">Contact Messages</div>
                                <div class="stat-value" style="font-size:1.6rem;"><?php echo $contactMessages; ?></div>
                                <div class="stat-footer"><a href="viewFeedback">View messages <i class="fas fa-arrow-right"></i></a></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            <?php endif; ?>

            </div>
        </div>

        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>
</body>
</html>
