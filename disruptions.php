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

// ============================================================================
// HANDLE COMPANY SEARCH REQUEST (when user types in search box)
// ============================================================================
if(isset($_GET['action']) && $_GET['action'] == 'company_search') {
    // Get the search term from the URL and make it safe for SQL
    $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);
    
    // Search for companies matching the search term
    $sql = "SELECT CompanyID, CompanyName 
            FROM Company 
            WHERE CompanyName LIKE '%" . $searchTerm . "%' 
            ORDER BY CompanyName 
            LIMIT 10";
    
    // Run the query
    $result = mysqli_query($conn, $sql);
    
    // Check if query failed
    if(!$result) {
        die(json_encode(["error" => "Query failed: " . mysqli_error($conn)]));
    }
    
    // Put all matching companies into an array
    $companies = [];
    while($row = mysqli_fetch_assoc($result)) {
        $companies[] = $row;
    }
    
    // Send the results back as JSON
    header('Content-Type: application/json');
    echo json_encode($companies);
}

// ============================================================================
// HANDLE REGION SEARCH REQUEST (when user types in search box)
// ============================================================================
if(isset($_GET['action']) && $_GET['action'] == 'region_search') {
    // Get the search term from the URL and make it safe for SQL
    $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);
    
    // Search for regions matching the search term
    $sql = "SELECT DISTINCT ContinentName 
            FROM Location 
            WHERE ContinentName LIKE '%" . $searchTerm . "%' 
            ORDER BY ContinentName 
            LIMIT 10";
    
    // Run the query
    $result = mysqli_query($conn, $sql);
    
    // Check if query failed
    if(!$result) {
        die(json_encode(["error" => "Query failed: " . mysqli_error($conn)]));
    }
    
    // Put all matching regions into an array
    $regions = [];
    while($row = mysqli_fetch_assoc($result)) {
        $regions[] = $row;
    }
    
    // Send the results back as JSON
    header('Content-Type: application/json');
    echo json_encode($regions);
}

// ============================================================================
// HANDLE DISRUPTION DATA REQUEST (when user selects a company or filters by date)
// ============================================================================
  elseif(isset($_GET['action']) && $_GET['action'] == 'disruptions') {
    
    // Get the company ID from the URL
    $company_id = mysqli_real_escape_string($conn, $_GET['company']);
    
    // Get date range if provided (optional - user may or may not filter by date)
    $start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
  }

// ========================================================================
// DISRUPTION QUERY 1: FREQUENCY PER COMPANY
// ========================================================================
$sql_companyfrequency = "SELECT c.CompanyName,ic.ImpactLevel, COUNT(*) AS DisruptionCount 
                         FROM DisruptionEvent de 
                         INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID 
                         INNER JOIN Company c ON c.CompanyID = ic.AffectedCompanyID 
                         GROUP BY c.CompanyID 
                         ORDER BY DisruptionCount DESC, c.CompanyName";
    
$result_companyfrequency = mysqli_query($conn, $sql_companyfrequency);
    if(!$result_companyfrequency) {
        die(json_encode(["error" => "Frequency query failed: " . mysqli_error($conn)]));
    }
$companyfrequency_data = mysqli_fetch_assoc($result_companyfrequency);
$companyfrequency_rate = $companyfrequency_data && $companyfrequency_data['CompanyFrequency'] !== null ? round($companyfrequency_data['CompanyFrequency'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 2: FREQUENCY PER REGION
// ========================================================================
$sql_regionfrequency = "SELECT l.ContinentName, COUNT(*) AS DisruptionCount 
                        FROM DisruptionEvent de 
                        INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID 
                        INNER JOIN Company c ON c.CompanyID = ic.AffectedCompanyID 
                        INNER JOIN Location l ON l.LocationID = c.LocationID 
                        GROUP BY l.ContinentName 
                        ORDER BY DisruptionCount DESC, l.ContinentName";
    
$result_regionfrequency = mysqli_query($conn, $sql_regionfrequency);
    if(!$result_regionfrequency) {
        die(json_encode(["error" => "Frequency query failed: " . mysqli_error($conn)]));
    }
$regionfrequency_data = mysqli_fetch_assoc($result_regionfrequency);
$regionfrequency_rate = $regionfrequency_data && $regionfrequency_data['RegionFrequency'] !== null ? round($regionfrequency_data['RegionFrequency'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 3: FREQUENCY PER TIER
// ========================================================================
$sql_tierfrequency = "SELECT TierLevel, COUNT(*) AS DisruptionCount 
                      FROM DisruptionEvent 
                      INNER JOIN ImpactsCompany ON DisruptionEvent.EventID = ImpactsCompany.EventID 
                      INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                      GROUP BY TierLevel";
    
$result_tierfrequency = mysqli_query($conn, $sql_tierfrequency);
    if(!$result_tierfrequency) {
        die(json_encode(["error" => "Frequency query failed: " . mysqli_error($conn)]));
    }
$tierfrequency_data = mysqli_fetch_assoc($result_tierfrequency);
$tierfrequency_rate = $tierfrequency_data && $tierfrequency_data['TierFrequency'] !== null ? round($tierfrequency_data['TierFrequency'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 4: AVERAGE RECOVERY TIME (ART) PER COMPANY
// ========================================================================
$sql_companyart = "SELECT c.CompanyName, AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS AvgRecoveryDays 
                   FROM DisruptionEvent de 
                   INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID 
                   INNER JOIN Company c ON c.CompanyID = ic.AffectedCompanyID 
                   WHERE de.EventRecoveryDate IS NOT NULL 
                   GROUP BY c.CompanyID 
                   ORDER BY AvgRecoveryDays DESC, c.CompanyName"; //https://www.w3schools.com/sql/func_sqlserver_datediff.asp
    
$result_companyart = mysqli_query($conn, $sql_companyart);
    if(!$result_companyart) {
        die(json_encode(["error" => "ART query failed: " . mysqli_error($conn)]));
    }
$companyart_data = mysqli_fetch_assoc($result_companyart);
$companyart_rate = $companyart_data && $companyart_data['CompanyART'] !== null ? round($companyart_data['CompanyART'], 0) : null;

$conn->close();
?> 