<?php session_start();
// This is view.php
require_once "include/config.php";
require_once "include/auth.php";

// you have to be logged in to view this page
// This function is in utils.php
require_login();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Grades</title>
	<link rel="stylesheet" href="css\SiteStyle.css">
</head>
<body>
	<header>
		  Introduction to Web Information Systems
	</header>
	<?php include "include/nav.php"; ?>

	<div class="content">
		<form action="logout.php" method="POST">
			Currently logged in as <?php echo htmlentities(logged_in_user()); ?>
			<button>Log out</button>
		</form>

		<?php
		// Get the list of results for this user
		$conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME);

		$query = '
				SELECT
					ach.year, ach.semester,
					co.name,
					gr.description
				FROM achievement ach 
				INNER JOIN
					course co
				ON
					ach.courseid = co.id
				INNER JOIN
					grade gr
				ON
					gr.code = ach.gradecode
				WHERE ach.studentid=?
				ORDER BY ach.year, ach.semester, co.id
				';

		$stmt = mysqli_prepare($conn, $query);
		
		$studentid = logged_in_user();
		mysqli_stmt_bind_param($stmt, "s", $studentid);

		mysqli_stmt_execute($stmt);

		$result = mysqli_stmt_get_result($stmt);
		
		?>
		<h2>Your results</h2>
		<table>
		<tr><th>Year</th><th>Semester</th><th>Course</th><th>Grade</th></tr>

		<?php while($row = mysqli_fetch_assoc($result)): ?>
			<tr>
				<td><?php echo htmlentities($row['year']); ?></td>
				<td><?php echo htmlentities($row['semester']); ?></td>
				<td><?php echo htmlentities($row['name']); ?></td>
				<td><?php echo htmlentities($row['description']); ?></td>
			</tr>
		<?php
		endwhile;

		mysqli_stmt_close($stmt);

		?>

		</table>

	</div>

	<?php include "include/footer.php"; ?>

<script>
	
</script>
</body>
</html>