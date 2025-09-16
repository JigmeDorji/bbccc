<?php
// Ensure no output before this line
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['projectID'])) {
    $newProjectID = $_POST['projectID'];

    foreach ($_SESSION['projects'] as $proj) {
        if ($proj['projectID'] == $newProjectID) {
            $_SESSION['projectID'] = $newProjectID;
            break;
        }
    }

    // Redirect after session is set
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
} else {
    // Fallback if project ID is missing
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}
