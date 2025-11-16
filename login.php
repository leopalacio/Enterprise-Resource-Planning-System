<?php
// --- Step 1: Connect to your Purdue MySQL database ---
$servername = "mydb.itap.purdue.edu";
$username = "g1151928"; // your Purdue MySQL username
$password = "JuK3J593"; // <-- replace this with your Purdue MySQL password
$dbname = "g1151928"; // your database name (same as your username)

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// --- Step 2: Collect form data ---
$user = $_POST['username'];
$pass = $_POST['password'];

// --- Step 3: Query the User table ---
$sql = "SELECT * FROM User WHERE BINARY Username='$user' AND BINARY Password='$pass'";
$result = mysqli_query($conn, $sql);

// --- Step 4: If found, redirect based on role ---
if (mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    
    if ($row['Role'] == 'SupplyChainManager') {
        header("Location: company.html");
        exit();
    } elseif ($row['Role'] == 'SeniorManager') {
        header("Location: senior_manager.html");
        exit();
    } else {
        echo "Unknown role type."; //user exists but has an unknown/invalid role
    }
} else {
    //the <script> is for PHP to output JavaScript code that will run in the browser
    //the alert is for the pop up
    echo "<script> 
            alert('Invalid username or password. Please try again.'); 
            window.location.href = 'index.html';
          </script>";
}

// Close connection
mysqli_close($conn);
?>
