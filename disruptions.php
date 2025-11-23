<?php
// session_start();
// if(!isset($_SESSION['role'])) {
//     header("Location: index.php");
//     exit();
// }

// Output buffering - captures all output so we can control when it's sent to browser
// This prevents "headers already sent" errors if there's any accidental output
// Source: https://www.php.net/manual/en/function.ob-start.php
ob_start();

// Set timezone to match Indiana time (Purdue is in Indianapolis)
// Source: https://www.php.net/manual/en/function.date-default-timezone-set.php
date_default_timezone_set('America/Indiana/Indianapolis');

// Turn off error display for production (errors would break our JSON response)
ini_set('display_errors', 0);
error_reporting(0);

// Database connection info - this pattern is from the PHP lab materials
$servername = "mydb.itap.purdue.edu";
$username   = "g1151928";
$password   = "JuK3J593";
$dbname     = "g1151928";

// Create connection to MySQL database using mysqli
// This mysqli_connect pattern is from the PHP lab (test.php example)
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Set character encoding to handle special characters properly
// Source: https://www.php.net/manual/en/mysqli.set-charset.php
mysqli_set_charset($conn, "utf8mb4");

// Check if connection failed - from PHP lab materials
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
    
    // Error handling for failed query
    if(!$result) {
        ob_clean();
        header('Content-Type: application/json');
        die(json_encode(["error" => "Query failed"]));
    }
    
    $companies = [];
    while($row = mysqli_fetch_assoc($result)) {
        $companies[] = $row; // Array push shorthand - adds row to end
    }
    
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($companies);
    mysqli_close($conn);
    exit; // Stop script execution - important to prevent extra output
}

// ============================================================================
// HANDLE REGION SEARCH REQUEST (when user types in search box)
// ============================================================================
elseif (isset($_GET['action']) && $_GET['action'] == 'region_search') {
    $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);
    
    // DISTINCT keyword - SQL for unique values only
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

// ============================================================================
// HANDLE DISRUPTION DATA REQUEST (when user selects a company or applies filters)
// ============================================================================
elseif (isset($_GET['action']) && $_GET['action'] == 'disruptions') {

    // --------------------------------------------------
    // 1. Read filters from query string
    // --------------------------------------------------
    $company_id = isset($_GET['company'])    ? mysqli_real_escape_string($conn, $_GET['company'])    : '';
    $region     = isset($_GET['region'])     ? mysqli_real_escape_string($conn, $_GET['region'])     : '';
    $tier       = isset($_GET['tier'])       ? mysqli_real_escape_string($conn, $_GET['tier'])       : '';
    $start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
    $end_date   = isset($_GET['end_date'])   ? mysqli_real_escape_string($conn, $_GET['end_date'])   : '';

    // --------------------------------------------------
    // 2. Build ONE base WHERE clause using consistent aliases:
    //    de = DisruptionEvent, ic = ImpactsCompany, c = Company, l = Location
    // --------------------------------------------------
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

    // These variants are used where recovery date is required
    $whereWithRecovery = $baseWhere . " AND de.EventRecoveryDate IS NOT NULL";

    // --------------------------------------------------
    // 3. Run the SIX queries that back your plots
    //    (DF, ART, HDR, TD, RRC, DSD)
    // --------------------------------------------------

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
        SELECT 
            c.CompanyName,
            AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS AvgRecoveryDays
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
        SELECT 
            c.CompanyName,
            SUM(ic.ImpactLevel = 'High') / COUNT(*) * 100 AS HighImpactRate
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
        SELECT 
            c.CompanyName,
            SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS TotalDowntimeDays
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
        SELECT 
            l.ContinentName,
            COUNT(*) AS DisruptionCount
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
        SELECT 
            c.CompanyName,
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

    // --------------------------------------------------
    // 4. Build the single JSON response object
    // --------------------------------------------------
    $response = [
        "df"  => ["names" => [], "values" => []],
        "art" => ["names" => [], "values" => []],
        "hdr" => ["names" => [], "values" => []],
        "td"  => ["names" => [], "values" => []],
        "rrc" => ["names" => [], "values" => []],
        "dsd" => [
            "names"  => [],
            "low"    => [],
            "medium" => [],
            "high"   => []
        ]
    ];

    // DF
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

    // RRC – convert counts to percentages
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

    // --------------------------------------------------
    // 5. Send JSON and stop script
    // --------------------------------------------------
    ob_clean();
    header("Content-Type: application/json");
    echo json_encode($response);
    mysqli_close($conn);
    exit();
}

// ============================================================================
// INVALID ACTION (anything that isn't company_search, region_search, disruptions)
// ============================================================================
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
?>
