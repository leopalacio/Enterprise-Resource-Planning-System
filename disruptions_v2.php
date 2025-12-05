<?php
session_start();

// ==================================================================================
// LOCK THE PAGE - REDIRECT IF NOT LOGGED IN
// ==================================================================================
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// ==================================================================================
// API MODE CHECK
// ==================================================================================
if (isset($_GET['action'])) {
    // ==================================================================================
    // OUTPUT BUFFERING (captures all output so we can control when it's sent to browser)
    // ==================================================================================
    // Source: https://www.php.net/manual/en/function.ob-start.php
    ob_start();

    // ==================================================================================
    // SET TIME ZONE, ERROR HANDLING, AND DATABASE CONNECTION
    // ==================================================================================
    // Source: https://www.php.net/manual/en/function.date-default-timezone-set.php
    date_default_timezone_set('America/Indiana/Indianapolis');

    // Source: https://www.php.net/manual/en/errorfunc.configuration.php, https://www.w3schools.com/php/func_error_reporting.asp
    ini_set('display_errors', 0);
    error_reporting(0);

    // Source: PHP lab materials
    $servername = "mydb.itap.purdue.edu";
    $username   = "g1151928";
    $password   = "JuK3J593";
    $dbname     = "g1151928";

    $conn = mysqli_connect($servername, $username, $password, $dbname);

    // ==================================================================================
    // CHARACTER ENCODING SETUP, CONNECTION TESTING
    // ==================================================================================
    // Source: https://www.php.net/manual/en/mysqli.set-charset.php
    mysqli_set_charset($conn, "utf8mb4");

    if (!$conn) {
        ob_clean(); // Clear any output buffer
        header('Content-Type: application/json');
        die(json_encode(["error" => "Connection failed: " . mysqli_connect_error()]));
    }

    // ============================================================================
    // HANDLE COMPANY SEARCH REQUEST (when user types in search box)
    // ============================================================================
    if (isset($_GET['action']) && $_GET['action'] == 'company_search') {

        $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);
        
        $sql = "SELECT CompanyID, CompanyName 
                FROM Company 
                WHERE CompanyName LIKE '%" . $searchTerm . "%' 
                ORDER BY CompanyName 
                LIMIT 10";
        
        $result = mysqli_query($conn, $sql);
        
        // Error handling for failed query, Source: https://www.php.net/manual/en/mysqli.query.php
        if(!$result) {
            ob_clean();
            header('Content-Type: application/json');
            die(json_encode(["error" => "Query failed"]));
        }
        
        $companies = [];
        while($row = mysqli_fetch_assoc($result)) {
            $companies[] = $row; // Array push shorthand - adds row to end, Source: https://www.php.net/manual/en/function.array-push.php
        }
        
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($companies);
        mysqli_close($conn);
        exit; // Stop script execution - important to prevent extra output, Source: https://www.php.net/manual/en/function.exit.php
    }

    // ============================================================================
    // HANDLE REGION SEARCH REQUEST (when user types in search box)
    // ============================================================================
    elseif (isset($_GET['action']) && $_GET['action'] == 'region_search') {
        $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);
        
        $sql = "SELECT DISTINCT ContinentName 
                FROM Location 
                WHERE ContinentName LIKE '%" . $searchTerm . "%' 
                ORDER BY ContinentName 
                LIMIT 10";
        
        $result = mysqli_query($conn, $sql);
        
        if(!$result) {
            ob_clean();
            header('Content-Type: application/json');
            die(json_encode(["error" => "Query failed"]));
        }
        
        $regions = [];
        while($row = mysqli_fetch_assoc($result)) {
            $regions[] = $row;
        }
        
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($regions);
        mysqli_close($conn);
        exit;
    }

    // =====================================================================
    // EXPORT FULL DISRUPTION DATASET (RAW ROWS FROM DATABASE)
    // =====================================================================
    elseif (isset($_GET['action']) && $_GET['action'] == 'export_events') {

        // Same filter reading logic as in 'disruptions'
        $company_id = isset($_GET['company'])    ? mysqli_real_escape_string($conn, $_GET['company'])    : '';
        $region     = isset($_GET['region'])     ? mysqli_real_escape_string($conn, $_GET['region'])     : '';
        $tier       = isset($_GET['tier'])       ? mysqli_real_escape_string($conn, $_GET['tier'])       : '';
        $start_date = (isset($_GET['start_date']) && $_GET['start_date'] !== '')
                        ? mysqli_real_escape_string($conn, $_GET['start_date'])
                        : '';
        $end_date   = (isset($_GET['end_date']) && $_GET['end_date'] !== '')
                        ? mysqli_real_escape_string($conn, $_GET['end_date'])
                        : '';

        $baseWhere = " WHERE 1=1";
        if ($company_id !== '') {
            $baseWhere .= " AND c.CompanyID = '$company_id'";
        }
        if ($region !== '') {
            $baseWhere .= " AND l.ContinentName = '$region'";
        }
        if ($tier !== '') {
            $baseWhere .= " AND c.TierLevel = '$tier'";
        }
        if ($start_date !== '') {
            $baseWhere .= " AND de.EventDate >= '$start_date'";
        }
        if ($end_date !== '') {
            $baseWhere .= " AND de.EventDate <= '$end_date'";
        }

        // Pull “raw” disruption rows + related info
        $sql = "
            SELECT
                de.EventID,
                de.EventDate,
                de.EventRecoveryDate,
                dc.CategoryName,
                dc.Description,
                ic.ImpactLevel,
                DATEDIFF(de.EventRecoveryDate, de.EventDate) AS TotalDowntimeDays,
                c.CompanyID,
                c.CompanyName,
                c.TierLevel,
                c.Type,
                l.ContinentName AS Region,
                l.CountryName
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            INNER JOIN DisruptionCategory dc ON dc.CategoryID = de.CategoryID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $baseWhere
            ORDER BY de.EventDate, c.CompanyName, de.EventID
        ";

        $result = mysqli_query($conn, $sql);
        if (!$result) {
            ob_clean();
            header('Content-Type: text/plain; charset=utf-8');
            echo "Export query failed: " . mysqli_error($conn);
            mysqli_close($conn);
            exit();
        }

        // Stream CSV
        ob_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="disruptions_full_export.csv"');

        $out = fopen('php://output', 'w');

        // Use DB column names as headers
        $fields = mysqli_fetch_fields($result);
        $headerRow = [];
        foreach ($fields as $f) {
            $headerRow[] = $f->name;
        }
        fputcsv($out, $headerRow);

        // Data rows
        while ($row = mysqli_fetch_assoc($result)) {
            fputcsv($out, $row);
        }

        fclose($out);
        mysqli_close($conn);
        exit();
    }

    // ============================================================================
    // HANDLE DISRUPTION DATA REQUEST (when user selects a company or applies filters)
    // ============================================================================
    elseif (isset($_GET['action']) && $_GET['action'] == 'disruptions') {

      // Define YTD filter defaults
        $today     = date('Y-m-d');
        $yearStart = date('Y-01-01');

      // 1. Read filters from query string, Source: https://www.php.net/manual/en/reserved.variables.get.php
        $company_id = isset($_GET['company'])    ? mysqli_real_escape_string($conn, $_GET['company'])    : '';
        $region     = isset($_GET['region'])     ? mysqli_real_escape_string($conn, $_GET['region'])     : '';
        $tier       = isset($_GET['tier'])       ? mysqli_real_escape_string($conn, $_GET['tier'])       : '';

        // Default to no date filters if not provided
        $start_date = (isset($_GET['start_date']) && $_GET['start_date'] !== '')
            ? mysqli_real_escape_string($conn, $_GET['start_date'])
            : '';

        $end_date   = (isset($_GET['end_date']) && $_GET['end_date'] !== '')
            ? mysqli_real_escape_string($conn, $_GET['end_date'])
            : '';

        // 2. Build ONE base WHERE clause using consistent aliases, Source: https://www.php.net/manual/en/function.mysqli-real-escape-string.php
        $baseWhere = " WHERE 1=1";

        if ($company_id !== '') {
            $baseWhere .= " AND c.CompanyID = '$company_id'";
        }
        if ($region !== '') {
            $baseWhere .= " AND l.ContinentName = '$region'";
        }
        if ($tier !== '') {
            $baseWhere .= " AND c.TierLevel = '$tier'";
        }

        // Add date filters if provided
        if ($start_date !== '') {
            $baseWhere .= " AND de.EventDate >= '$start_date'";
        }
        if ($end_date !== '') {
            $baseWhere .= " AND de.EventDate <= '$end_date'";
        }


        // These variants are used where recovery date is required
        $whereWithRecovery = $baseWhere . " AND de.EventRecoveryDate IS NOT NULL";

        // 3. Run the queries that update the plots, Source: https://www.php.net/manual/en/function.mysqli-query.php

        // 3.1 Disruption Frequency (DF) by company
        $sql_companyfrequency = "
            SELECT c.CompanyName, COUNT(*) AS DisruptionCount
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $baseWhere
            GROUP BY c.CompanyID
            ORDER BY DisruptionCount DESC, c.CompanyName
            ";
        $result_companyfrequency = mysqli_query($conn, $sql_companyfrequency);
        if (!$result_companyfrequency) {
            ob_clean();
            header("Content-Type: application/json");
            echo json_encode(["error" => "DF query failed: " . mysqli_error($conn)]);
            mysqli_close($conn);
            exit();
        }

        // 3.2 Average Recovery Time (ART) per company (for histogram)
        $sql_companyart = "
            SELECT c.CompanyName, AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS AvgRecoveryDays
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $whereWithRecovery
            GROUP BY c.CompanyID
            ORDER BY AvgRecoveryDays DESC, c.CompanyName
            ";
        $result_companyart = mysqli_query($conn, $sql_companyart);
        if (!$result_companyart) {
            ob_clean();
            header("Content-Type: application/json");
            echo json_encode(["error" => "ART query failed: " . mysqli_error($conn)]);
            mysqli_close($conn);
            exit();
        }

        // 3.3 High-Impact Disruption Rate (HDR) by company
        $sql_companyhdr = "
            SELECT c.CompanyName, SUM(ic.ImpactLevel = 'High') / COUNT(*) * 100 AS HighImpactRate
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $baseWhere
            GROUP BY c.CompanyName
            ORDER BY HighImpactRate DESC, c.CompanyName
            ";
        $result_companyhdr = mysqli_query($conn, $sql_companyhdr);
        if (!$result_companyhdr) {
            ob_clean();
            header("Content-Type: application/json");
            echo json_encode(["error" => "HDR query failed: " . mysqli_error($conn)]);
            mysqli_close($conn);
            exit();
        }

        // 3.4 Total Downtime (TD) by company
        $sql_suppliertd = "
            SELECT c.CompanyName, SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS TotalDowntimeDays
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $whereWithRecovery
            GROUP BY c.CompanyID
            ORDER BY TotalDowntimeDays DESC, c.CompanyName
            ";
        $result_suppliertd = mysqli_query($conn, $sql_suppliertd);
        if (!$result_suppliertd) {
            ob_clean();
            header("Content-Type: application/json");
            echo json_encode(["error" => "TD query failed: " . mysqli_error($conn)]);
            mysqli_close($conn);
            exit();
        }

        // 3.5 Regional Risk Concentration (RRC) – we’ll convert counts to % in PHP
        $sql_rrc = "
            SELECT l.ContinentName, COUNT(*) AS DisruptionCount
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $baseWhere
            GROUP BY l.ContinentName
            ORDER BY DisruptionCount DESC, l.ContinentName
            ";
        $result_rrc = mysqli_query($conn, $sql_rrc);
        if (!$result_rrc) {
            ob_clean();
            header("Content-Type: application/json");
            echo json_encode(["error" => "RRC query failed: " . mysqli_error($conn)]);
            mysqli_close($conn);
            exit();
        }

        // 3.6 Disruption Severity Distribution (DSD) by company
        $sql_companydsd = "
            SELECT c.CompanyName,
                SUM(ic.ImpactLevel = 'Low')    AS LowImpact,
                SUM(ic.ImpactLevel = 'Medium') AS MediumImpact,
                SUM(ic.ImpactLevel = 'High')   AS HighImpact
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $baseWhere
            GROUP BY c.CompanyName
            ORDER BY c.CompanyName
            ";
        $result_companydsd = mysqli_query($conn, $sql_companydsd);
        if (!$result_companydsd) {
            ob_clean();
            header("Content-Type: application/json");
            echo json_encode(["error" => "DSD query failed: " . mysqli_error($conn)]);
            mysqli_close($conn);
            exit();
        }

        // 3.7 NEW DISRUPTIONS IN LAST 7 DAYS
        $sql_new = "
            SELECT COUNT(*) AS NewCount
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $baseWhere
            AND de.EventDate >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ";
        $result_new = mysqli_query($conn, $sql_new);
        $newCount = 0;
        if ($result_new && $row = mysqli_fetch_assoc($result_new)) {
            $newCount = (int)$row['NewCount'];
        }

        // 3.8 ONGOING DISRUPTIONS (NO RECOVERY DATE)
        $sql_ongoing = "
            SELECT COUNT(*) AS OngoingCount
            FROM DisruptionEvent de
            INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
            INNER JOIN Company        c  ON c.CompanyID = ic.AffectedCompanyID
            LEFT  JOIN Location       l  ON l.LocationID = c.LocationID
            $baseWhere
            AND de.EventRecoveryDate IS NULL
            ";
        $result_ongoing = mysqli_query($conn, $sql_ongoing);
        $ongoingCount = 0;
        if ($result_ongoing && $row = mysqli_fetch_assoc($result_ongoing)) {
            $ongoingCount = (int)$row['OngoingCount'];
        }

        // 4. Build the single JSON response object, Source: https://www.php.net/manual/en/function.json-encode.php
        $response = [
            "df"  => ["names" => [], "values" => []],
            "art" => ["names" => [], "values" => []],
            "hdr" => ["names" => [], "values" => []],
            "td"  => ["names" => [], "values" => []],
            "rrc" => ["names" => [], "values" => []],
            "dsd" => ["names"  => [], "low"    => [], "medium" => [], "high"   => []],
            "alerts" => ["new_last_week" => $newCount, "ongoing" => $ongoingCount]
        ];

        // DF, Source: https://www.php.net/manual/en/function.mysqli-fetch-assoc.php
        while ($row = mysqli_fetch_assoc($result_companyfrequency)) {
            $response["df"]["names"][]  = $row["CompanyName"];
            $response["df"]["values"][] = (float)$row["DisruptionCount"];
        }

        // ART
        while ($row = mysqli_fetch_assoc($result_companyart)) {
            $response["art"]["names"][]  = $row["CompanyName"];
            $response["art"]["values"][] = (float)$row["AvgRecoveryDays"];
        }

        // HDR
        while ($row = mysqli_fetch_assoc($result_companyhdr)) {
            $response["hdr"]["names"][]  = $row["CompanyName"];
            $response["hdr"]["values"][] = (float)$row["HighImpactRate"];
        }

        // TD
        while ($row = mysqli_fetch_assoc($result_suppliertd)) {
            // $response["td"]["names"][]  = $row["CompanyName"];
            $response["td"]["values"][] = (float)$row["TotalDowntimeDays"];
        }

        // RRC – convert counts to percentages, Source: https://www.php.net/manual/en/control-structures.for.php
        $rrcNames  = [];
        $rrcCounts = [];
        $totalRrc  = 0;

        while ($row = mysqli_fetch_assoc($result_rrc)) {
            $rrcNames[]  = $row["ContinentName"];
            $rrcCounts[] = (int)$row["DisruptionCount"];
            $totalRrc   += (int)$row["DisruptionCount"];
        }

        if ($totalRrc > 0) {
            for ($i = 0; $i < count($rrcNames); $i++) {
                $response["rrc"]["names"][]  = $rrcNames[$i];
                $response["rrc"]["values"][] = 100.0 * $rrcCounts[$i] / $totalRrc;
            }
        }

        // DSD
        while ($row = mysqli_fetch_assoc($result_companydsd)) {
            $response["dsd"]["names"][]  = $row["CompanyName"];
            $response["dsd"]["low"][]    = (int)$row["LowImpact"];
            $response["dsd"]["medium"][] = (int)$row["MediumImpact"];
            $response["dsd"]["high"][]   = (int)$row["HighImpact"];
        }

        // 5. Send JSON and stop script, Source: https://www.php.net/manual/en/function.json-encode.php
        ob_clean();
        header("Content-Type: application/json");
        echo json_encode($response);
        mysqli_close($conn);
        exit();
    }

    // ================================================================================
    // INVALID ACTION (anything that isn't company_search, region_search, disruptions)
    // ================================================================================
    // Source: https://www.php.net/manual/en/reserved.variables.get.php
    else {
        $action = isset($_GET['action']) ? $_GET['action'] : 'none';
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(400); // Bad Request
        echo json_encode([
            "error"           => "Invalid action",
            "action_received" => $action,
            "valid_actions"   => ["company_search", "region_search", "disruptions"]
        ]);
        mysqli_close($conn);
        exit();
    }
} // End of API mode
?>
<!DOCTYPE html>
<html>
<head>
    <title>Disruption Analysis Dashboard</title>
    <script src="https://cdn.plot.ly/plotly-2.35.2.min.js" charset="utf-8"></script>
    <link rel="stylesheet" type="text/css" href="css/dashboard.css?v=15">
</head>
<body class = "disruptions-page">

<nav>
  <div class="nav-links">
      <a href="company.php">Company Information</a>
      <a href="disruptions.php" class="active">Disruption Events</a>
      <a href="transactions.php">Transactions</a>
      <button class="logout-btn" onclick="logout()">
        <img src="logout.png" alt="Log Out">
      </button>
  </div>

  <script>
    function logout() {
      let answer = confirm("Are you sure you want to log out?");
      if (answer) {
        window.location.href = "logout.php";
      }
    }
  </script>

  <!-- keep links left, filters right -->
  <div style="flex-grow: 1;"></div>

  <!-- FILTERS – same structure as Transactions, different fields -->
  <div class="nav-filter-container">

    <!-- Dates -->
    <input type="date" id="disruption-start-date">
    <input type="date" id="disruption-end-date">

    <!-- Region search -->
    <div class="search-box-two">
      <input type="text"
             id="region-search-input"
             placeholder="Region"
             autocomplete="off">
      <div class="search-results" id="region-search-results"></div>
    </div>

    <!-- Company search -->
    <div class="search-box-two">
      <input type="text"
             id="company-search-input"
             placeholder="Search Company"
             autocomplete="off">
      <div class="search-results" id="company-search-results"></div>
    </div>

    <!-- Tier -->
    <select id="tier-filter">
      <option value="">Tier level</option>
      <option value="1">1</option>
      <option value="2">2</option>
      <option value="3">3</option>
    </select>

    <!-- Buttons -->
    <button class="btn" onclick="applyFilters()">Apply Filters</button>
    <button class="btn" onclick="clearFilters()">Clear Filters</button>
  </div>
</nav>

<div id="box-overlay"></div>

<!-- ==================== DASHBOARD CONTENT ==================== -->
<div class="container">
  <h1 class="page-title">Disruption Analysis Dashboard</h1>

  <!-- DISRUPTION ALERT BANNER -->
  <div id="disruption-alert" class="alert-banner" style="display:none;"></div>

  <!-- EXPORT FULL DATASET BUTTON -->
  <div class="export-row">
  <button class="btn btn-export" onclick="exportFullDataset()">
    Export Full Disruption Dataset
  </button>
</div>

  <div class="dashboard-grid">

  <!-- DISRUPTION METRICS CARDS -->
  <!--DF-->
  <div class="card" data-box-id="df">
  <h2 class="card-title">Disruption Frequency (DF)</h2>
  <button class="zoom-btn" title="Expand chart">+</button>
  <div id="df-bar" class="graph-container"></div>
  <button class="btn btn-export" onclick="exportDF()">Export DF Data</button>
  </div>

  <!--ART-->
  <div class="card" data-box-id="art">
  <h2 class="card-title">Average Recovery Time (ART)</h2>
  <button class="zoom-btn" title="Expand chart">+</button>
  <div id="art-histogram" class="graph-container"></div>
  <button class="btn btn-export" onclick="exportART()">Export ART Data</button>
  </div>

  <!--HDR-->
  <div class="card" data-box-id="hdr">
  <h2 class="card-title">High-Impact Disruption Rate (HDR)</h2>
  <button class="zoom-btn" title="Expand chart">+</button>
  <div id="hdr-bar" class="graph-container"></div>
  <button class="btn btn-export" onclick="exportHDR()">Export HDR Data</button>
  </div>

  <!--TD-->
  <div class="card" data-box-id="td">
  <h2 class="card-title">Total Downtime (TD)</h2>
  <button class="zoom-btn" title="Expand chart">+</button>
  <div id="td-histogram" class="graph-container"></div>
  <button class="btn btn-export" onclick="exportTD()">Export TD Data</button>
  </div>

  <!--RRC-->
  <div class="card" data-box-id="rrc">
  <h2 class="card-title">Regional Risk Concentration (RRC)</h2>
  <button class="zoom-btn" title="Expand chart">+</button>
  <div id="rrc-heatmap" class="graph-container"></div>
  <button class="btn btn-export" onclick="exportRRC()">Export RRC Data</button>
  </div>

  <!--DSD-->
  <div class="card" data-box-id="dsd">
  <h2 class="card-title">Disruption Severity Distribution (DSD)</h2>
  <button class="zoom-btn" title="Expand chart">+</button>
  <div id="dsd-bar" class="graph-container"></div>
  <button class="btn btn-export" onclick="exportDSD()">Export DSD Data</button>
  </div>

</div>
</div>
 
<script>
// ===================== WAIT FOR PAGE TO LOAD =====================
//Source: https://developer.mozilla.org/en-US/docs/Web/API/Window/DOMContentLoaded_event
document.addEventListener('DOMContentLoaded', function () {
  console.log("Page loaded, initializing search functionality...");
});

  // ====== SET DEFAULT DATE FILTERS TO YTD ON LOAD ======
  (function setYTDDefaults() {
    var startInput = document.getElementById("disruption-start-date");
    var endInput   = document.getElementById("disruption-end-date");
    if (!startInput || !endInput) return;

    var today = new Date();
    var yearStart = new Date(today.getFullYear(), 0, 1);

    function fmt(d) {
      return d.toISOString().slice(0, 10);
    }

    startInput.value = fmt(yearStart);
    endInput.value   = fmt(today);

    // Store YTD values globally to reset
    window._ytdStart = fmt(yearStart);
    window._ytdEnd   = fmt(today);
  })();

  // ===================== STATE VARIABLES =====================
  var currentCompanyId = null;
  var currentCompanyName = '';
  var currentRegion = '';
  var currentTier = '';
  var latestDisruptionData = null;

  // ===================== GET DOM ELEMENTS =====================
  var companyInput = document.getElementById("company-search-input");
  var companyResults = document.getElementById("company-search-results");
  var regionInput = document.getElementById("region-search-input");
  var regionResults = document.getElementById("region-search-results");
  var tierSelect = document.getElementById("tier-filter");

  console.log("Elements found:", {
    companyInput: !!companyInput,
    companyResults: !!companyResults,
    regionInput: !!regionInput,
    regionResults: !!regionResults
  });

  // ===================== AJAX HELPER FUNCTION =====================
  //Source: https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest/Using_XMLHttpRequest
  //Source: https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API/Using_Fetch
  //Source: https://developer.mozilla.org/en-US/docs/Web/API/WindowOrWorkerGlobalScope/fetch
  //Source: https://developer.mozilla.org/en-US/docs/Web/API/Response/json
  //Source: https://www.w3schools.com/xml/ajax_xmlhttprequest_create.asp
  //Source: https://www.w3schools.com/xml/ajax_xmlhttprequest_send.asp
  //Source: https://www.w3schools.com/xml/ajax_xmlhttprequest_response.asp
  function ajaxGet(url, onSuccess, onError) {
    console.log("AJAX GET:", url);
    var xhr = new XMLHttpRequest();
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          try {
            var data = JSON.parse(xhr.responseText);
            console.log("Success:", data);
            onSuccess(data);
          } catch (e) {
            console.error("JSON parse error:", e, xhr.responseText);
            if (onError) onError(e);
          }
        } else {
          console.error("HTTP error:", xhr.status, xhr.statusText);
          if (onError) onError(new Error("HTTP " + xhr.status));
        }
      }
    };
    xhr.open("GET", url, true);
    xhr.send();
  }

  // ===================== COMPANY SEARCH =====================
  // Source: https://developer.mozilla.org/en-US/docs/Web/API/Element/closest
  function displayCompanies(data) {
    console.log("Displaying companies:", data);
    companyResults.innerHTML = "";

    if (!data || data.error || data.length === 0) {
      companyResults.innerHTML = '<div class="no-results">No companies found</div>';
      companyResults.classList.add("show");
      return;
    }

    for (var i = 0; i < data.length; i++) {
      var item = document.createElement("div");
      item.className = "search-result-item";
      item.textContent = data[i].CompanyName;
      item.setAttribute("data-id", data[i].CompanyID);

      item.onclick = function () {
        currentCompanyId = this.getAttribute("data-id");
        currentCompanyName = this.textContent;
        companyInput.value = currentCompanyName;
        companyResults.classList.remove("show");
        console.log("Company selected:", currentCompanyName, currentCompanyId);
      };

      companyResults.appendChild(item);
    }
    companyResults.classList.add("show");
  }

  function loadAllCompanies() {
    console.log("Loading all companies...");
    ajaxGet("disruptions.php?action=company_search&query=", displayCompanies);
  }

  if (companyInput) {
    companyInput.addEventListener("focus", function () {
      console.log("Company input focused");
      loadAllCompanies();
    });

    companyInput.addEventListener("input", function () {
      var searchTerm = companyInput.value.trim();
      console.log("Company search term:", searchTerm);

      if (searchTerm.length < 1) {
        loadAllCompanies();
        return;
      }

      ajaxGet(
        "disruptions.php?action=company_search&query=" + encodeURIComponent(searchTerm),
        displayCompanies
      );
    });
  }

  // ===================== REGION SEARCH =====================
  function displayRegions(data) {
    console.log("Displaying regions:", data);
    regionResults.innerHTML = "";

    if (!data || data.error || data.length === 0) {
      regionResults.innerHTML = '<div class="no-results">No regions found</div>';
      regionResults.classList.add("show");
      return;
    }

    for (var i = 0; i < data.length; i++) {
      var item = document.createElement("div");
      item.className = "search-result-item";
      item.textContent = data[i].ContinentName;

      item.onclick = function () {
        currentRegion = this.textContent;
        regionInput.value = currentRegion;
        regionResults.classList.remove("show");
        console.log("Region selected:", currentRegion);
      };

      regionResults.appendChild(item);
    }
    regionResults.classList.add("show");
  }

  function loadAllRegions() {
    console.log("Loading all regions...");
    ajaxGet("disruptions.php?action=region_search&query=", displayRegions);
  }

  if (regionInput) {
    regionInput.addEventListener("focus", function () {
      console.log("Region input focused");
      loadAllRegions();
    });

    regionInput.addEventListener("input", function () {
      var searchTerm = regionInput.value.trim();
      console.log("Region search term:", searchTerm);

      if (searchTerm.length < 1) {
        loadAllRegions();
        return;
      }

      ajaxGet(
        "disruptions.php?action=region_search&query=" + encodeURIComponent(searchTerm),
        displayRegions
      );
    });
  }

  // ===================== CLOSE DROPDOWNS ON CLICK OUTSIDE =====================
  document.addEventListener("click", function (event) {
    if (companyInput && companyResults &&
        !event.target.closest("#company-search-input") &&
        !event.target.closest("#company-search-results")) {
      companyResults.classList.remove("show");
    }
    
    if (regionInput && regionResults &&
        !event.target.closest("#region-search-input") &&
        !event.target.closest("#region-search-results")) {
      regionResults.classList.remove("show");
    }
  });

  // ===================== TIER DROPDOWN =====================
  if (tierSelect) {
    tierSelect.addEventListener("change", function () {
      currentTier = this.value || "";
      console.log("Tier changed:", currentTier);
    });
  }

  // ===================== APPLY FILTERS (GLOBAL) =====================
  window.applyFilters = function () {
    console.log("Applying filters...");
    var startDate = document.getElementById("disruption-start-date").value;
    var endDate = document.getElementById("disruption-end-date").value;

    var params = "action=disruptions";

    if (currentCompanyId) params += "&company=" + encodeURIComponent(currentCompanyId);
    if (currentRegion) params += "&region=" + encodeURIComponent(currentRegion);
    if (currentTier) params += "&tier=" + encodeURIComponent(currentTier);
    if (startDate) params += "&start_date=" + encodeURIComponent(startDate);
    if (endDate) params += "&end_date=" + encodeURIComponent(endDate);

    console.log("Filter params:", params);
    ajaxGet("disruptions.php?" + params, renderDisruptionData);
  };

  // ===================== CLEAR FILTERS (GLOBAL) =====================
  window.clearFilters = function () {
    console.log("Clearing filters...");
    if (companyInput) companyInput.value = "";
    if (regionInput) regionInput.value = "";
    if (tierSelect) tierSelect.value = "";

    var startDateInput = document.getElementById("disruption-start-date");
    var endDateInput = document.getElementById("disruption-end-date");
    if (startDateInput) startDateInput.value = "";
    if (endDateInput) endDateInput.value = "";

    if (companyResults) companyResults.classList.remove("show");
    if (regionResults) regionResults.classList.remove("show");

    currentCompanyId = null;
    currentCompanyName = "";
    currentRegion = "";
    currentTier = "";

    ajaxGet("disruptions.php?action=disruptions", renderDisruptionData);

    console.log("Filters cleared.");
  };

  // ===================== RENDER DISRUPTION DATA =====================
  function renderDisruptionData(data) {
    console.log("Rendering disruption data:", data);

    if (!data || data.error) {
      console.error("Bad disruption data:", data);
      alert("Error loading data: " + (data.error || "Unknown error"));
      return;
    }

    latestDisruptionData = data;// Store for export functions

  // ===== ALERT BANNER FOR NEW / ONGOING DISRUPTIONS ===================
  var alertBanner = document.getElementById("disruption-alert");
    if (alertBanner && data.alerts) {
      var msgs = [];

      if (data.alerts.new_last_week && data.alerts.new_last_week > 0) {
        msgs.push(data.alerts.new_last_week + " new disruption(s) in the last 7 days.");
      }

      if (data.alerts.ongoing && data.alerts.ongoing > 0) {
        msgs.push(data.alerts.ongoing + " ongoing disruption(s) with no recovery date.");
      }

      if (msgs.length > 0) {
        // use HTML entity for warning symbol to avoid weird encoding chars
        alertBanner.innerHTML = "&#9888; " + msgs.join(" ");
        alertBanner.style.display = "block";
      } else {
        alertBanner.style.display = "none";
        alertBanner.textContent = "";
      }
    }

    const MAX_COMPANIES = 20; // Limit for DF, HDR, and DSD bar charts

    const plotConfig = {
    responsive: true,
    displayModeBar: false,
    staticPlot: false
  };

  // ===================== CSV EXPORT FUNCTIONS =====================
    window.exportDF = function () {
  if (!latestDisruptionData || !latestDisruptionData.df) {
    alert("No DF data to export. Apply filters first.");
    return;
  }

  const names  = latestDisruptionData.df.names;
  const values = latestDisruptionData.df.values;

  const rows = names.map((name, i) => [name, values[i]]);
  downloadCSV(["Company", "Disruptions"], rows, "disruption_frequency.csv");
};

window.exportART = function () {
  if (!latestDisruptionData || !latestDisruptionData.art) {
    alert("No ART data to export. Apply filters first.");
    return;
  }

  const values = latestDisruptionData.art.values;
  const rows = values.map((v, i) => [i + 1, v]); // simple index + value

  downloadCSV(["Record", "AvgRecoveryDays"], rows, "average_recovery_time.csv");
};

window.exportHDR = function () {
  if (!latestDisruptionData || !latestDisruptionData.hdr) {
    alert("No HDR data to export. Apply filters first.");
    return;
  }

  const names  = latestDisruptionData.hdr.names;
  const values = latestDisruptionData.hdr.values;

  const rows = names.map((name, i) => [name, values[i]]);
  downloadCSV(["Company", "HighImpactRatePercent"], rows, "high_impact_disruption_rate.csv");
};

window.exportTD = function () {
  if (!latestDisruptionData || !latestDisruptionData.td) {
    alert("No TD data to export. Apply filters first.");
    return;
  }

  const values = latestDisruptionData.td.values;
  const rows = values.map((v, i) => [i + 1, v]);

  downloadCSV(["Record", "TotalDowntimeDays"], rows, "total_downtime.csv");
};

window.exportRRC = function () {
  if (!latestDisruptionData || !latestDisruptionData.rrc) {
    alert("No RRC data to export. Apply filters first.");
    return;
  }

  const names  = latestDisruptionData.rrc.names;
  const values = latestDisruptionData.rrc.values;

  const rows = names.map((name, i) => [name, values[i]]);
  downloadCSV(["Region", "RiskPercent"], rows, "regional_risk_concentration.csv");
};

window.exportDSD = function () {
  if (!latestDisruptionData || !latestDisruptionData.dsd) {
    alert("No DSD data to export. Apply filters first.");
    return;
  }

  const names  = latestDisruptionData.dsd.names;
  const low    = latestDisruptionData.dsd.low;
  const medium = latestDisruptionData.dsd.medium;
  const high   = latestDisruptionData.dsd.high;

  const rows = names.map((name, i) => [
    name,
    low[i]    || 0,
    medium[i] || 0,
    high[i]   || 0
  ]);

  downloadCSV(["Company", "Low", "Medium", "High"], rows, "disruption_severity_distribution.csv");
};


    // ===== DF BAR =====
    // Source: https://plotly.com/javascript/bar-charts/
    if (data.df && data.df.names && data.df.names.length > 0) {
      const names  = data.df.names.slice(0, MAX_COMPANIES);
      const values = data.df.values.slice(0, MAX_COMPANIES);

      const dfTraces = [{
        x: values,
        y: names,
        type: "bar",
        orientation: "h",
        marker: { color: '#4a90e2' }
      }];

      const dfLayout = {
        //title: "Disruption Frequency by Company",
        margin: { t: 40, r: 20, b: 40, l: 160 },
        xaxis: { title: "Number of Disruptions" },
        yaxis: { automargin: true} 
      };

      Plotly.newPlot("df-bar", dfTraces, dfLayout, plotConfig);
    } else {
      Plotly.purge('df-bar');
    }

    // ===== ART HISTOGRAM =====
    // Source: https://plotly.com/javascript/histograms/
    if (data.art && data.art.values && data.art.values.length > 0) {
        const artTraces = [{
        x: data.art.values,
        type: "histogram",
        marker: { color: '#22c55e' }
      }];

      const artLayout = {
        //title: "Average Recovery Time (Days)",
        xaxis: { title: "Number of Days" },
        yaxis: { title: "Count of Occurences" }
      };

      Plotly.newPlot("art-histogram", artTraces, artLayout, plotConfig);
    } else {
      Plotly.purge('art-histogram');
    }

    // ===== HDR BAR =====
    // Source: https://plotly.com/javascript/bar-charts/
    if (data.hdr && data.hdr.names && data.hdr.names.length > 0) {
      const names  = data.hdr.names.slice(0, MAX_COMPANIES);
      const values = data.hdr.values.slice(0, MAX_COMPANIES);

      const hdrTraces = [{
        x: values,
        y: names,
        type: "bar",
        orientation: "h",
        marker: { color: '#fbbf24' }
      }];

      const hdrLayout = {
        //title: "High Impact Disruption Rate (%)",
        margin: { t: 40, r: 20, b: 40, l: 160 },
        xaxis: { title: "Percentage (%)" },
        yaxis: { automargin: true }
      };

      Plotly.newPlot("hdr-bar", hdrTraces, hdrLayout, plotConfig);
    } else {
      Plotly.purge('hdr-bar');
    }

    // ===== TD HISTOGRAM =====
    // Source: https://plotly.com/javascript/histograms/
    if (data.td && data.td.values && data.td.values.length > 0) {
      const tdTraces = [{
        x: data.td.values,
        type: "histogram",
        marker: { color: '#ef4444' }
      }];

      const tdLayout = {
        //title: "Total Downtime (Days)",
        xaxis: { title: "Number of Days" },
        yaxis: { title: "Count of Occurences" }
      };

      Plotly.newPlot("td-histogram", tdTraces, tdLayout, plotConfig);
    } else {
      Plotly.purge('td-histogram');
    }

    // ===== RRC HEATMAP =====
    // Source: https://plotly.com/javascript/heatmaps/
    if (data.rrc && data.rrc.names && data.rrc.names.length > 0) {
      const rrcTraces = [{
        z: [data.rrc.values],
        x: data.rrc.names,
        y: ["Risk %"],
        type: "heatmap",
        colorscale: "Reds"
      }];

      const rrcLayout = {
        //title: "Regional Risk Concentration",
        margin: { t: 40, r: 20, b: 80, l: 60 }
      };

      Plotly.newPlot("rrc-heatmap", rrcTraces, rrcLayout, plotConfig);
    } else {
      Plotly.purge('rrc-heatmap');
    }

    // ===== DSD STACKED BAR =====
    // Source: https://plotly.com/javascript/bar-charts/#stacked-bar-chart
    if (data.dsd && data.dsd.names && data.dsd.names.length > 0) {
      const names  = data.dsd.names.slice(0, MAX_COMPANIES);
      const low    = data.dsd.low.slice(0, MAX_COMPANIES);
      const medium = data.dsd.medium.slice(0, MAX_COMPANIES);
      const high   = data.dsd.high.slice(0, MAX_COMPANIES);

      const dsdTraces = [
        {
          x: low,
          y: names,
          name: "Low",
          type: "bar",
          orientation: "h",
          marker: { color: '#22c55e' }
        },
        {
          x: medium,
          y: names,
          name: "Medium",
          type: "bar",
          orientation: "h",
          marker: { color: '#fbbf24' }
        },
        {
          x: high,
          y: names,
          name: "High",
          type: "bar",
          orientation: "h",
          marker: { color: '#ef4444' }
        }
      ];

      const dsdLayout = {
        barmode: "stack",
        //title: "Disruption Severity Distribution",
        margin: { t: 40, r: 20, b: 40, l: 160 },
        xaxis: { title: "Number of Disruptions" },
        yaxis: { automargin: true }
      };

      Plotly.newPlot("dsd-bar", dsdTraces, dsdLayout, plotConfig);
    } else {
      Plotly.purge('dsd-bar');
    }

    console.log("✅ All plots rendered!");
  }

// ===================== CSV EXPORT HELPER FUNCTION =====================
function downloadCSV(headersArray, rowsArray, filename) {
  // headersArray: ["Company","Disruptions"]
  // rowsArray:   [ ["A",10], ["B",5], ... ]

  let csv = "";
  csv += headersArray.join(",") + "\n";

  rowsArray.forEach(function(row) {
    csv += row.map(v => `"${String(v).replace(/"/g, '""')}"`).join(",") + "\n";
  });

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);

  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

  window.exportFullDataset = function () {
    var qs = buildFilterQuery('export_events');
    // Trigger file download
    window.location.href = "disruptions.php?" + qs;
  };


// Build a query string for the current filters + an action name
function buildFilterQuery(actionName) {
    var params = "action=" + encodeURIComponent(actionName);

    if (currentCompanyId) params += "&company=" + encodeURIComponent(currentCompanyId);
    if (currentRegion)   params += "&region="  + encodeURIComponent(currentRegion);
    if (currentTier)     params += "&tier="    + encodeURIComponent(currentTier);

    var startDate = document.getElementById("disruption-start-date").value;
    var endDate   = document.getElementById("disruption-end-date").value;

    if (startDate) params += "&start_date=" + encodeURIComponent(startDate);
    if (endDate)   params += "&end_date="   + encodeURIComponent(endDate);

    return params;
  }

// ====== Card zoom (reuse pattern from Senior Manager) ======
function setupCardZoom() {
  const boxes   = document.querySelectorAll('.card[data-box-id]');
  const overlay = document.getElementById('box-overlay');
  if (!overlay || boxes.length === 0) return;

  boxes.forEach(box => {
    const zoomBtn = box.querySelector('.zoom-btn');
    if (!zoomBtn) return;

    zoomBtn.addEventListener('click', function (e) {
      e.stopPropagation();

      const isExpanded = box.classList.contains('expanded');

      // collapse any other expanded cards
      document.querySelectorAll('.card.expanded').forEach(b => {
        if (b !== box) {
          b.classList.remove('expanded');
          const otherBtn = b.querySelector('.zoom-btn');
          if (otherBtn) {
            otherBtn.textContent = '+';
            otherBtn.title = 'Expand chart';
          }
        }
      });

      if (isExpanded) {
        box.classList.remove('expanded');
        overlay.classList.remove('show');
        zoomBtn.textContent = '+';
        zoomBtn.title = 'Expand chart';
        resizeChartsInBox(box);
      } else {
        box.classList.add('expanded');
        overlay.classList.add('show');
        zoomBtn.textContent = '-';
        zoomBtn.title = 'Close';
        setTimeout(() => resizeChartsInBox(box), 150);
      }
    });
  });

  // clicking on the dark overlay collapses everything
  overlay.addEventListener('click', () => {
    document.querySelectorAll('.card.expanded').forEach(b => {
      b.classList.remove('expanded');
      const btn = b.querySelector('.zoom-btn');
      if (btn) {
        btn.textContent = '+';
        btn.title = 'Expand chart';
      }
      resizeChartsInBox(b);
    });
    overlay.classList.remove('show');
  });
}

// resize any Plotly charts inside the card
function resizeChartsInBox(box) {
  const chartDivs = box.querySelectorAll('.graph-container[id]');
  chartDivs.forEach(div => {
    if (window.Plotly) {
      Plotly.Plots.resize(div);
    }
  });
}

// run after DOM is ready
document.addEventListener('DOMContentLoaded', function () {
  setupCardZoom();
});

  // ===================== LOAD INITIAL DATA (YTD) =====================
  var initialStart = window._ytdStart;
  var initialEnd   = window._ytdEnd;

  var initialParams = "action=disruptions";
  if (initialStart) initialParams += "&start_date=" + encodeURIComponent(initialStart);
  if (initialEnd)   initialParams += "&end_date="   + encodeURIComponent(initialEnd);

  ajaxGet("disruptions.php?" + initialParams, renderDisruptionData);

  // ===================== SENIOR MANAGER TAB =====================
  fetch('role.php')
    .then(response => response.json())
    .then(data => {
      if (!data || data.role !== 'SeniorManager') {
        return;
      }

      const navLinks = document.querySelector('.nav-links');
      if (!navLinks) return;

      if (document.getElementById('SeniorModuleTab')) return;

      const seniorLink = document.createElement('a');
      seniorLink.id = 'SeniorModuleTab';
      seniorLink.href = 'senior_manager.php';
      seniorLink.textContent = 'Senior Module';

      navLinks.insertBefore(seniorLink, navLinks.firstChild);
    })
    .catch(err => {
      console.error('Role check failed:', err);
    })

</script>

</body>
</html>