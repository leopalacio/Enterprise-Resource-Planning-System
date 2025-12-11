<?php
session_start();

//Direct Senior Managers to Senior Module and Supply Chain Managers to Company page
if (isset($_SESSION['username']) && isset($_SESSION['role'])) {
  if ($_SESSION['role'] == 'SupplyChainManager') {
    header("Location: company.php");
    exit();
  } elseif ($_SESSION['role'] == 'SeniorManager') {
    header("Location: senior_manager.php");
    exit();
  }
}
?>

<!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/login.css?v=6">
  <title>Login Form - IE 332 Group 18</title>
</head>

<body>
  <h1>IE 332 - Enterprise Resource Planning System</h1>

  <div class="login-wrapper">
    <h2>Log-in</h2>

    <form action="login.php" method="post" name="loginForm" onsubmit="return validate()">

      <div class="form-row">
        <label for="username">
          <i class="fas fa-user"></i>
          Username:
        </label>
        <input type="text" name="username" id="username" placeholder="Enter username">
      </div>

      <div class="form-row">
        <label for="password">
          <i class="fas fa-lock"></i>
          Password:
        </label>
        <input type="password" name="password" id="password" placeholder="Enter password">
      </div>

      <div class="button-group">
        <input type="submit" name="login" id="login" value="Login">
        <input type="reset" value="Reset">
      </div>

    </form>

  </div>

  <!-- Meet the Team Section -->
  <div class="team-section">
    <h3>Meet Group 18</h3>

    <div class="photodiv">
      <div class="person">
        <a href="https://www.linkedin.com/in/leonel-palacio/" target="_blank">
          <img src="Headshots/Leo_Palacio.jpg" alt="Leonel Palacio">
        </a>
        <p>Leonel Palacio</p>
      </div>
      <div class="person">
        <a href="https://www.linkedin.com/in/maximovedoyab/" target="_blank">
          <img src="Headshots/Maximo_Vedoya_Headshot.jpg" alt="Maximo Vedoya">
        </a>
        <p>Maximo Vedoya</p>
      </div>
      <div class="person">
        <a href="https://www.linkedin.com/in/daria-surmach/" target="_blank">
          <img src="Headshots/Daria_Surmach_headshot.jpeg" alt="Daria Surmach">
        </a>
        <p>Daria Surmach</p>
      </div>
      <div class="person">
        <a href="https://www.linkedin.com/in/emily-johns13" target="_blank">
          <img src="Headshots/Emily_Johns_Headshot.jpg" alt="Emily Johns">
        </a>
        <p>Emily Johns</p>
      </div>
      <div class="person">
        <a href="https://www.linkedin.com/in/nikhai-tonwar-a897a3294/" target="_blank">
          <img src="Headshots/Nikhai_Tonwar_Headshot.jpg" alt="Nikhai Tonwar">
        </a>
        <p>Nikhai Tonwar</p>
      </div>
      <div class="person">
        <a href="https://www.linkedin.com/in/caseywilliamsii" target="_blank">
          <img src="Headshots/Casey_Williams_Headshot.jpg" alt="Casey Williams">
        </a>
        <p>Casey Williams</p>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer>
    <p>&copy; 2025 IE 332 - Group 18 | Purdue University | Enterprise Resource Planning System </p>
  </footer>

  <script>
    function validate() {
      if (document.loginForm.username.value == "") {
        alert("Please provide your username!");
        document.loginForm.username.focus();
        return false;
      }

      if (document.loginForm.password.value == "") {
        alert("Please provide your password!");
        document.loginForm.password.focus();
        return false;
      }
      return true;
    }
  </script>
</body>

</html>