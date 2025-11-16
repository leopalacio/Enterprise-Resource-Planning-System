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
if(isset($_GET['action']) && $_GET['action'] == 'search') {
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

// ============================================================================
// HANDLE REGION SEARCH REQUEST (when user types in search box)
// ============================================================================
if(isset($_GET['action']) && $_GET['action'] == 'search') {
    // Get the search term from the URL and make it safe for SQL
    $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);
    
    // Search for regions matching the search term
    $sql = "SELECT LocationID, ContinentName AS Region 
            FROM Location 
            WHERE Region LIKE '%" . $searchTerm . "%' 
            ORDER BY Region 
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
    
    $result_companyfrequency = mysqli_query($conn, $sql_delivery);
    if(!$result_companyfrequency) {
        die(json_encode(["error" => "Frequency query failed: " . mysqli_error($conn)]));
    }
    $companyfrequency_data = mysqli_fetch_assoc($result_companyfrequency);
    $companyfrequency_rate = $companyfrequency_data && $companyfrequency_data['CompanyFrequency'] !== null ? round($companyfrequency_data['CompanyFrequency'], 0) : null;
    