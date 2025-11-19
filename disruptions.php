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
    exit;
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
    exit; 
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

// ========================================================================
// DISRUPTION QUERY 5: AVERAGE RECOVERY TIME (ART) PER REGION
// ========================================================================
$sql_regionart = "SELECT l.ContinentName, AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS AvgRecoveryDays 
                  FROM DisruptionEvent de 
                  INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID 
                  INNER JOIN Company c ON c.CompanyID = ic.AffectedCompanyID 
                  INNER JOIN Location l ON l.LocationID = c.LocationID 
                  WHERE de.EventRecoveryDate IS NOT NULL 
                  GROUP BY l.ContinentName 
                  ORDER BY AvgRecoveryDays DESC, l.ContinentName";
    
$result_regionart = mysqli_query($conn, $sql_regionart);
    if(!$result_regionart) {
        die(json_encode(["error" => "ART query failed: " . mysqli_error($conn)]));
    }
$regionart_data = mysqli_fetch_assoc($result_regionart);
$regionart_rate = $regionart_data && $regionart_data['RegionART'] !== null ? round($regionart_data['RegionART'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 6: AVERAGE RECOVERY TIME (ART) PER TIER
// ========================================================================
$sql_tierart = "SELECT TierLevel, AVG(DATEDIFF(EventRecoveryDate, EventDate)) AS AvgRecoveryDays 
                FROM DisruptionEvent 
                INNER JOIN ImpactsCompany ON DisruptionEvent.EventID = ImpactsCompany.EventID 
                INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                GROUP BY TierLevel";
    
$result_tierart = mysqli_query($conn, $sql_tierart);
    if(!$result_tierart) {
        die(json_encode(["error" => "ART query failed: " . mysqli_error($conn)]));
    }
$tierart_data = mysqli_fetch_assoc($result_tierart);
$tierart_rate = $tierart_data && $tierart_data['TierART'] !== null ? round($tierart_data['TierART'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 7: HIGH-IMPACT DISRUPTION RATE (HDR) PER COMPANY
// ========================================================================
$sql_companyhdr = "SELECT CompanyName, SUM(ImpactLevel = 'High')/COUNT(ImpactLevel)*100 AS HighImpactRate 
                   FROM ImpactsCompany 
                   INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                   GROUP BY CompanyName";
    
$result_companyhdr = mysqli_query($conn, $sql_companyhdr);
    if(!$result_companyhdr) {
        die(json_encode(["error" => "HDR query failed: " . mysqli_error($conn)]));
    }
$companyhdr_data = mysqli_fetch_assoc($result_companyhdr);
$companyhdr_rate = $companyhdr_data && $companyhdr_data['CompanyHDR'] !== null ? round($companyhdr_data['CompanyHDR'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 8: HIGH-IMPACT DISRUPTION RATE (HDR) PER REGION
// ========================================================================
$sql_regionhdr = "SELECT ContinentName, SUM(ImpactLevel = 'High') / COUNT(ImpactLevel)*100 AS HighImpactRate 
                  FROM ImpactsCompany 
                  INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                  INNER JOIN Location ON Company.LocationID = Location.LocationID 
                  GROUP BY ContinentName";
    
$result_regionhdr = mysqli_query($conn, $sql_regionhdr);
    if(!$result_regionhdr) {
        die(json_encode(["error" => "HDR query failed: " . mysqli_error($conn)]));
    }
$regionhdr_data = mysqli_fetch_assoc($result_regionhdr);
$regionhdr_rate = $regionhdr_data && $regionhdr_data['RegionHDR'] !== null ? round($regionhdr_data['RegionHDR'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 9: HIGH-IMPACT DISRUPTION RATE (HDR) PER TIER
// ========================================================================
$sql_tierhdr = "SELECT TierLevel, SUM(ImpactLevel = 'High') / COUNT(ImpactLevel)*100 AS HighImpactRate 
                FROM ImpactsCompany 
                INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                GROUP BY TierLevel";
    
$result_tierhdr = mysqli_query($conn, $sql_tierhdr);
    if(!$result_tierhdr) {
        die(json_encode(["error" => "HDR query failed: " . mysqli_error($conn)]));
    }
$tierhdr_data = mysqli_fetch_assoc($result_tierhdr);
$tierhdr_rate = $tierhdr_data && $tierhdr_data['TierHDR'] !== null ? round($tierhdr_data['TierHDR'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 10: TOTATL DOWNTIME (TD) PER SUPPLIER
// ========================================================================
$sql_suppliertd = "SELECT c.CompanyName, SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS TotalDowntimeDays 
               FROM DisruptionEvent de 
               INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID 
               INNER JOIN Company c ON c.CompanyID = ic.AffectedCompanyID 
               WHERE de.EventRecoveryDate IS NOT NULL 
               GROUP BY c.CompanyID 
               ORDER BY TotalDowntimeDays DESC";
    
$result_suppliertd = mysqli_query($conn, $sql_suppliertd);
    if(!$result_suppliertd) {
        die(json_encode(["error" => "TD query failed: " . mysqli_error($conn)]));
    }
$suppliertd_data = mysqli_fetch_assoc($result_suppliertd);
$suppliertd_rate = $suppliertd_data && $suppliertd_data['SupplierTD'] !== null ? round($suppliertd_data['SupplierTD'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 11: REGIONAL RISK CONCENTRATION (RRC)
// ========================================================================
$sql_rrc = "SELECT ContinentName, (COUNT(*)/d.Disruptions)*100 AS RegionRisk 
            FROM DisruptionEvent 
            CROSS JOIN (SELECT COUNT(*) AS Disruptions 
                FROM DisruptionEvent 
                INNER JOIN ImpactsCompany ON DisruptionEvent.EventID = ImpactsCompany.EventID) AS d 
            INNER JOIN ImpactsCompany ON DisruptionEvent.EventID = ImpactsCompany.EventID 
            INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
            INNER JOIN Location ON Company.LocationID = Location.LocationID 
            GROUP BY ContinentName 
            ORDER BY RegionRisk DESC"; //https://www.w3schools.com/mysql/mysql_join_cross.asp 
    
$result_rrc = mysqli_query($conn, $sql_rrc);
    if(!$result_rrc) {
        die(json_encode(["error" => "RRC query failed: " . mysqli_error($conn)]));
    }
$rrc_data = mysqli_fetch_assoc($result_rrc);
$rrc_rate = $rrc_data && $rrc_data['RRC'] !== null ? round($rrc_data['RRC'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 12: DISRUPTION SEVERITY DISTRIBUTION (DSD) PER COMPANY
// ========================================================================
$sql_companydsd = "SELECT CompanyName, SUM(ImpactLevel = 'Low') AS LowImpact, SUM(ImpactLevel = 'Medium') AS MediumImpact, SUM(ImpactLevel = 'High') AS HighImpact 
                   FROM ImpactsCompany 
                   INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                   GROUP BY CompanyName";
    
$result_companydsd = mysqli_query($conn, $sql_companydsd);
    if(!$result_companydsd) {
        die(json_encode(["error" => "DSD query failed: " . mysqli_error($conn)]));
    }
$companydsd_data = mysqli_fetch_assoc($result_companydsd);
$companydsd_rate = $companydsd_data && $companydsd_data['CompanyDSD'] !== null ? round($companydsd_data['CompanyDSD'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 13: DISRUPTION SEVERITY DISTRIBUTION (DSD) PER REGION
// ========================================================================
$sql_regiondsd = "SELECT ContinentName AS Region, SUM(ImpactLevel = 'Low') AS LowImpact, SUM(ImpactLevel = 'Medium') AS MediumImpact, SUM(ImpactLevel = 'High') AS HighImpact 
                  FROM ImpactsCompany 
                  INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                  INNER JOIN Location ON Company.LocationID = Location.LocationID 
                  GROUP BY ContinentName";
    
$result_regiondsd = mysqli_query($conn, $sql_regiondsd);
    if(!$result_regiondsd) {
        die(json_encode(["error" => "DSD query failed: " . mysqli_error($conn)]));
    }
$regiondsd_data = mysqli_fetch_assoc($result_regiondsd);
$regiondsd_rate = $regiondsd_data && $regiondsd_data['RegionDSD'] !== null ? round($regiondsd_data['RegionDSD'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 14: DISRUPTION SEVERITY DISTRIBUTION (DSD) PER TIER
// ========================================================================
$sql_tierdsd = "SELECT TierLevel, SUM(ImpactLevel = 'Low') AS LowImpact, SUM(ImpactLevel = 'Medium') AS MediumImpact, SUM(ImpactLevel = 'High') AS HighImpact 
                FROM ImpactsCompany 
                INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID 
                GROUP BY TierLevel";
    
$result_tierdsd = mysqli_query($conn, $sql_tierdsd);
    if(!$result_tierdsd) {
        die(json_encode(["error" => "DSD query failed: " . mysqli_error($conn)]));
    }
$tierdsd_data = mysqli_fetch_assoc($result_tierdsd);
$tierdsd_rate = $tierdsd_data && $tierdsd_data['TierDSD'] !== null ? round($tierdsd_data['TierDSD'], 0) : null;

// ========================================================================
// DISRUPTION QUERY 15: DISRUPTION EXPOSURE (DE) PER COMPANY
// ========================================================================
$sql_companyde = "SELECT c.CompanyName, COUNT(*) AS TotalDisruptions, SUM(ic.ImpactLevel = 'High') AS HighImpactCount, COUNT(*) + 2 * SUM(ic.ImpactLevel = 'High') AS ExposureScore 
                  FROM DisruptionEvent de 
                  INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID 
                  INNER JOIN Company c ON c.CompanyID = ic.AffectedCompanyID 
                  WHERE de.EventDate 
                  GROUP BY c.CompanyName 
                  ORDER BY ExposureScore DESC";
    
$result_companyde = mysqli_query($conn, $sql_companyde);
    if(!$result_companyde) {
        die(json_encode(["error" => "DE query failed: " . mysqli_error($conn)]));
    }
$companyde_data = mysqli_fetch_assoc($result_companyde);
$companyde_rate = $companyde_data && $companyde_data['CompanyDE'] !== null ? round($companyde_data['CompanyDE'], 0) : null;

$conn->close();
?> 