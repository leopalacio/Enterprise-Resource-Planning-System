<?php
session_start();
//Sources: Lab 8: PHP & ChatGPT

// Connect to phpMyAdmin
$servername = "mydb.itap.purdue.edu";
$username = "g1151928";
$password = "JuK3J593";
$dbname = "g1151928";

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

//Retrieve user inputs to prevent SQL injection
$user = mysqli_real_escape_string($conn, $_POST['username']);
$pass = mysqli_real_escape_string($conn, $_POST['password']);


//Query the User table
$sql = "SELECT * FROM User WHERE BINARY Username='$user' AND BINARY Password='$pass'";
$result = mysqli_query($conn, $sql);

//Redirect to appropriate page based on role
if (mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);

    $_SESSION['role'] = $row['Role'];
    $_SESSION['username'] = $row['Username'];
    $_SESSION['logged_in'] = true;
    
    if ($row['Role'] == 'SupplyChainManager') {
        header("Location: company.php");
        exit();
    } elseif ($row['Role'] == 'SeniorManager') {
        header("Location: senior_manager.php");
        exit();
    } else {
        //User exists but with invalid role
        echo "<script> 
                alert('Access denied. Please contact system administrator.'); 
                window.location.href = 'index.php';
              </script>";
    }
} else {
    // Invalid credentials message
    echo "<script> 
            alert('Invalid username or password. Please try again.'); 
            window.location.href = 'index.php';
          </script>";
}

// Close connection
mysqli_close($conn);
?>
