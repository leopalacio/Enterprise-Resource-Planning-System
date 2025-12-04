<?php
//Start Session
session_start();
if(!isset($_SESSION['username'])) {
  header("Location:index.php");
  exit();
}


$canEdit = ($_SESSION['role'] === 'SupplyChainManager');






// ✅ ADD THIS: Require login for ALL operations
if (!isset($_SESSION['username'])) {
   header('Content-Type: application/json');
   echo json_encode(["error" => "Please log in to access this resource."]);
   exit();
}




// ✅ FIXED: Allow both roles OR no session check for read-only operations
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');


// Only require authentication for write operations
$write_actions = ['update_company', 'add_location', 'update_shipment', 'update_adjustment'];


if (in_array($action, $write_actions)) {
   if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['SupplyChainManager', 'SeniorManager'])) {
       header('Content-Type: application/json');
       echo json_encode(["error" => "Access denied. Please log in."]);
       exit();
   }
}




ini_set('display_errors', 1); 
error_reporting(E_ALL);          


// ✅ ONLY run API code if there's an action or company parameter
if (isset($_GET['action']) || isset($_POST['action']) || isset($_GET['company'])) {
   ob_start();
  
   // Clear any previous output
   if (ob_get_level()) {
       ob_clean();
   }
  
   header('Content-Type: application/json');




$servername = "mydb.itap.purdue.edu";
$username   = "g1151928";
$password   = "JuK3J593";
$database   = "g1151928";


$conn = mysqli_connect($servername, $username, $password, $database);
mysqli_set_charset($conn, "utf8mb4");


//i was getting time zone error so i added this here, might be able to take out later
date_default_timezone_set('America/Indiana/Indianapolis');




if (!$conn) {
   die(json_encode(["error" => "Connection failed"]));
}


// ============================================================================
// SEARCH COMPANIES
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'search') {
   $term = mysqli_real_escape_string($conn, $_GET['query']);
   $sql = "SELECT CompanyID, CompanyName FROM Company WHERE CompanyName LIKE '$term%' ORDER BY CompanyName LIMIT 10";
   $res = mysqli_query($conn, $sql);
   $out = [];
   while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
   echo json_encode($out);
   mysqli_close($conn);
   exit;
}


// ============================================================================
// SEARCH LOCATIONS
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'search_location') {
   $term = mysqli_real_escape_string($conn, $_GET['query']);
   $sql = "SELECT LocationID, CountryName, ContinentName
           FROM Location
           WHERE CountryName LIKE '$term%'
              OR ContinentName LIKE '$term%'
           ORDER BY CountryName
           LIMIT 10";
   $res = mysqli_query($conn, $sql);
  
   if (!$res) {
       echo json_encode(["error" => mysqli_error($conn)]);
       mysqli_close($conn);
       exit;
   }
  
   $out = [];
   while ($r = mysqli_fetch_assoc($res)) {
       $out[] = [
           'LocationID' => $r['LocationID'],
           'CountryName' => $r['CountryName'],
           'ContinentName' => $r['ContinentName']
       ];
   }
   echo json_encode($out);
   mysqli_close($conn);
   exit;
}
// ============================================================================
// ADD NEW LOCATION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_location') {
   // ✅ FIXED: No more city
   $country = mysqli_real_escape_string($conn, $_POST['country']);
   $continent = mysqli_real_escape_string($conn, $_POST['continent']);
  
   $valid_continents = ['Africa', 'Asia', 'Europe', 'North America', 'South America', 'Oceania', 'Antarctica'];
   if (!in_array($continent, $valid_continents)) {
       echo json_encode(["error" => "Invalid continent"]);
       mysqli_close($conn);
       exit;
   }
  
   // Check if location already exists
   $check_sql = "SELECT LocationID FROM Location WHERE CountryName='$country' AND ContinentName='$continent'";
   $check_res = mysqli_query($conn, $check_sql);
  
   if (mysqli_num_rows($check_res) > 0) {
       $existing = mysqli_fetch_assoc($check_res);
       echo json_encode(["success" => true, "location_id" => $existing['LocationID'], "message" => "Location already exists"]);
       mysqli_close($conn);
       exit;
   }
  
   // Insert new location (no City column)
   $sql = "INSERT INTO Location (CountryName, ContinentName) VALUES ('$country', '$continent')";
  
   if (mysqli_query($conn, $sql)) {
       $location_id = mysqli_insert_id($conn);
       echo json_encode(["success" => true, "location_id" => $location_id]);
   } else {
       echo json_encode(["error" => mysqli_error($conn)]);
   }
   mysqli_close($conn);
   exit;
}


// ============================================================================
// UPDATE COMPANY
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_company') {
   $company_id = mysqli_real_escape_string($conn, $_POST['company_id']);
   $tier = mysqli_real_escape_string($conn, $_POST['tier']);
   $type = mysqli_real_escape_string($conn, $_POST['type']);
   $location_id = mysqli_real_escape_string($conn, $_POST['location_id']);
  
   // ✅ TEMPORARY DEBUG - Remove after testing
   error_log("UPDATE DEBUG - Company: $company_id, Tier: $tier, Type: $type, Location: $location_id");
  
   if (!in_array($tier, ['1','2','3']) || !in_array($type, ['Manufacturer','Distributor','Retailer'])) {
       echo json_encode(["error" => "Invalid input"]);
       mysqli_close($conn);
       exit;
   }
  
   // Verify location exists
   $loc_check = mysqli_query($conn, "SELECT LocationID FROM Location WHERE LocationID='$location_id'");
   if (mysqli_num_rows($loc_check) == 0) {
       echo json_encode(["error" => "Invalid location - ID: $location_id"]); // ✅ Added location ID to error
       mysqli_close($conn);
       exit;
   }
  
   $sql = "UPDATE Company SET TierLevel='$tier', Type='$type', LocationID='$location_id' WHERE CompanyID='$company_id'";
  
   // ✅ TEMPORARY DEBUG
   error_log("SQL Query: " . $sql);
  
   if (mysqli_query($conn, $sql)) {
       // ✅ Check how many rows were actually affected
       $affected = mysqli_affected_rows($conn);
       error_log("Rows affected: " . $affected);
       echo json_encode(["success" => true, "affected_rows" => $affected, "sql" => $sql]); // ✅ Added debug info
   } else {
       echo json_encode(["error" => mysqli_error($conn)]);
   }
   mysqli_close($conn);
   exit;
}
// ============================================================================
// UPDATE SHIPMENT (only if not yet delivered)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_shipment') {
   $shipment_id = mysqli_real_escape_string($conn, $_POST['shipment_id']);
   $actual_date = mysqli_real_escape_string($conn, $_POST['actual_date']);
   $quantity = mysqli_real_escape_string($conn, $_POST['quantity']);
  
   // Check if shipment is already completed
   $check_sql = "SELECT ActualDate FROM Shipping WHERE ShipmentID='$shipment_id'";
   $check_res = mysqli_query($conn, $check_sql);
  
   if (!$check_res || mysqli_num_rows($check_res) == 0) {
       echo json_encode(["error" => "Shipment not found"]);
       mysqli_close($conn);
       exit;
   }
  
   $shipment = mysqli_fetch_assoc($check_res);
   if ($shipment['ActualDate'] !== null) {
       echo json_encode(["error" => "Cannot edit completed shipment"]);
       mysqli_close($conn);
       exit;
   }
  
   // Update the shipment
   $sql = "UPDATE Shipping SET ActualDate='$actual_date', Quantity='$quantity' WHERE ShipmentID='$shipment_id'";
  
   if (mysqli_query($conn, $sql)) {
       echo json_encode(["success" => true]);
   } else {
       echo json_encode(["error" => mysqli_error($conn)]);
   }
   mysqli_close($conn);
   exit;
}


// ============================================================================
// UPDATE ADJUSTMENT (only if not yet executed)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_adjustment') {
   $adjustment_id = mysqli_real_escape_string($conn, $_POST['adjustment_id']);
   $quantity_change = mysqli_real_escape_string($conn, $_POST['quantity_change']);
   $reason = mysqli_real_escape_string($conn, $_POST['reason']);
  
   // Check if adjustment date is in the future
   $check_sql = "SELECT AdjustmentDate FROM InventoryAdjustment WHERE AdjustmentID='$adjustment_id'";
   $check_res = mysqli_query($conn, $check_sql);
  
   if (!$check_res || mysqli_num_rows($check_res) == 0) {
       echo json_encode(["error" => "Adjustment not found"]);
       mysqli_close($conn);
       exit;
   }
  
   $adjustment = mysqli_fetch_assoc($check_res);
   $adjustment_date = new DateTime($adjustment['AdjustmentDate']);
   $today = new DateTime();
  
   if ($adjustment_date < $today) {
       echo json_encode(["error" => "Cannot edit past adjustments"]);
       mysqli_close($conn);
       exit;
   }
  
   // Update the adjustment
   $sql = "UPDATE InventoryAdjustment SET QuantityChange='$quantity_change', Reason='$reason' WHERE AdjustmentID='$adjustment_id'";
  
   if (mysqli_query($conn, $sql)) {
       echo json_encode(["success" => true]);
   } else {
       echo json_encode(["error" => mysqli_error($conn)]);
   }
   mysqli_close($conn);
   exit;
}


// ============================================================================
// TRANSACTIONS - FIXED
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'transactions') {
   $cid = mysqli_real_escape_string($conn, $_GET['company']);
   $s = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
   $e = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
   $filter = ($s && $e) ? "AND (s.PromisedDate BETWEEN '$s' AND '$e' OR r.ReceivedDate BETWEEN '$s' AND '$e' OR a.AdjustmentDate BETWEEN '$s' AND '$e')" : "";
  
   // Fixed: Get product info through Shipping for Receiving records
   $sql = "SELECT it.Type, s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity,
           ps.ProductName AS ShipProduct, cs.CompanyName AS SourceCompany, cd.CompanyName AS DestinationCompany,
           r.ReceivingID, r.ReceivedDate, r.QuantityReceived,
           psr.ProductName AS RecProduct, cr.CompanyName AS RecCompany,
           a.AdjustmentID, a.QuantityChange, a.Reason, a.AdjustmentDate, pa.ProductName AS AdjProduct, ca.CompanyName AS AdjCompany
           FROM InventoryTransaction it
           LEFT JOIN Shipping s ON it.TransactionID = s.TransactionID
           LEFT JOIN Receiving r ON it.TransactionID = r.TransactionID
           LEFT JOIN Shipping sr ON r.ShipmentID = sr.ShipmentID
           LEFT JOIN InventoryAdjustment a ON it.TransactionID = a.TransactionID
           LEFT JOIN Product ps ON ps.ProductID = s.ProductID
           LEFT JOIN Product psr ON psr.ProductID = sr.ProductID
           LEFT JOIN Product pa ON pa.ProductID = a.ProductID
           LEFT JOIN Company cs ON cs.CompanyID = s.SourceCompanyID
           LEFT JOIN Company cd ON cd.CompanyID = s.DestinationCompanyID
           LEFT JOIN Company cr ON cr.CompanyID = r.ReceiverCompanyID
           LEFT JOIN Company ca ON ca.CompanyID = a.CompanyID
           WHERE (cs.CompanyID='$cid' OR cd.CompanyID='$cid' OR cr.CompanyID='$cid' OR ca.CompanyID='$cid') $filter";
  
   $res = mysqli_query($conn, $sql);
  
   if (!$res) {
       echo json_encode(["error" => mysqli_error($conn)]);
       mysqli_close($conn);
       exit;
   }
  
   $ship = [];
   $recv = [];
   $adj = [];
  
   while ($r = mysqli_fetch_assoc($res)) {
       if ($r['Type'] === 'Shipping' && $r['ShipmentID']) {
           $ship[] = [
               'ShipmentID' => $r['ShipmentID'],
               'Product' => $r['ShipProduct'],
               'SourceCompany' => $r['SourceCompany'],
               'DestinationCompany' => $r['DestinationCompany'],
               'PromisedDate' => $r['PromisedDate'],
               'ActualDate' => $r['ActualDate'],
               'Quantity' => $r['Quantity']
           ];
       }
       if ($r['Type'] === 'Receiving' && $r['ReceivingID']) {
           $recv[] = [
               'ReceivingID' => $r['ReceivingID'],
               'Product' => $r['RecProduct'],
               'Company' => $r['RecCompany'],
               'ReceivedDate' => $r['ReceivedDate'],
               'Quantity' => $r['QuantityReceived']
           ];
       }
       if ($r['Type'] === 'Adjustment' && $r['AdjustmentID']) {
           $adj[] = [
               'AdjustmentID' => $r['AdjustmentID'],
               'Product' => $r['AdjProduct'],
               'Company' => $r['AdjCompany'],
               'Date' => $r['AdjustmentDate'],
               'QuantityChange' => $r['QuantityChange'],
               'Reason' => $r['Reason']
           ];
       }
   }
  
   echo json_encode(['shipping'=>$ship, 'receiving'=>$recv, 'adjustments'=>$adj]);
   mysqli_close($conn);
   exit;
}


// ============================================================================
// KPI
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'kpi') {
   $cid = mysqli_real_escape_string($conn, $_GET['company']);
   $s = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
   $e = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
   $df = ($s && $e) ? "AND s.PromisedDate BETWEEN '$s' AND '$e'" : '';
  
   // On-time delivery rate
   $q1 = "SELECT SUM(CASE WHEN s.ActualDate<=s.PromisedDate THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0) AS Rate
          FROM Shipping s
          WHERE s.DestinationCompanyID='$cid' $df";
   $r1 = mysqli_query($conn, $q1);
   $rate = ($r1 && ($x=mysqli_fetch_assoc($r1)) && $x['Rate'] !== null) ? round($x['Rate'], 1) : null;
  
   // Delay stats
   $q2 = "SELECT AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS avgDelay,
                 STDDEV(DATEDIFF(s.ActualDate, s.PromisedDate)) AS stdDelay
          FROM Shipping s
          WHERE s.DestinationCompanyID='$cid' AND s.ActualDate IS NOT NULL $df";
   $r2 = mysqli_query($conn, $q2);
   $row2 = $r2 ? mysqli_fetch_assoc($r2) : [];
  
   // Financial status (most recent)
   $q3 = "SELECT HealthScore FROM FinancialReport
          WHERE CompanyID='$cid'
          ORDER BY RepYear DESC, FIELD(Quarter,'Q4','Q3','Q2','Q1') LIMIT 1";
   $r3 = mysqli_query($conn, $q3);
   $fin = ($r3 && ($y=mysqli_fetch_assoc($r3))) ? $y['HealthScore'] : 'N/A';
  
   // ✅ NEW: Financial trend data for graph (past 4 quarters)
   $q4 = "SELECT Quarter, RepYear, HealthScore
          FROM FinancialReport
          WHERE CompanyID='$cid'
          ORDER BY RepYear DESC, FIELD(Quarter,'Q4','Q3','Q2','Q1')
          LIMIT 8";
   $r4 = mysqli_query($conn, $q4);
   $financialTrend = [];
   if ($r4) {
       while ($row = mysqli_fetch_assoc($r4)) {
           $financialTrend[] = [
               'quarter' => $row['Quarter'] . ' ' . $row['RepYear'],
               'score' => (int)$row['HealthScore']
           ];
       }
       // Reverse so oldest is first (for graph x-axis)
       $financialTrend = array_reverse($financialTrend);
   }
  
  




// ✅ FIXED: Show ALL disruption events regardless of date range
$q5 = "SELECT dc.CategoryName as EventName, de.EventDate, ic.ImpactLevel
      FROM DisruptionEvent de
      INNER JOIN DisruptionCategory dc ON dc.CategoryID = de.CategoryID
      INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
      WHERE ic.AffectedCompanyID='$cid'
      ORDER BY de.EventDate DESC
      LIMIT 10";








   $r5 = mysqli_query($conn, $q5);
   $events = [];
   if ($r5) {
       while ($row = mysqli_fetch_assoc($r5)) {
           $events[] = [
               'name' => $row['EventName'],
               'date' => $row['EventDate'],
               'impact' => $row['ImpactLevel']
           ];
       }
   }
  
   echo json_encode([
       'deliveryRate' => $rate,
       'avgDelay' => isset($row2['avgDelay']) ? round($row2['avgDelay'], 1) : 0,
       'stdDelay' => isset($row2['stdDelay']) ? round($row2['stdDelay'], 1) : 0,
       'financialStatus' => $fin,
       'financialTrend' => $financialTrend,  // ✅ Added
       'events' => $events                    // ✅ Added
   ]);
   mysqli_close($conn);
   exit;
}


// ============================================================================
// COMPANY INFO
// ============================================================================
if (isset($_GET['company'])) {
   ob_clean();
   $cid = mysqli_real_escape_string($conn, $_GET['company']);
  
   // Main company info
   $res = mysqli_query($conn, "SELECT CompanyName, Type, TierLevel, LocationID FROM Company WHERE CompanyID='$cid'");
   $c = mysqli_fetch_assoc($res);
  
   if (!$c) {
       echo json_encode(["error" => "Company not found"]);
       mysqli_close($conn);
       exit;
   }
  
   // Location
   $loc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT CountryName, ContinentName FROM Location WHERE LocationID='{$c['LocationID']}'"));
  
   // Upstream suppliers
   $up = mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(c.CompanyName SEPARATOR ', ') AS Suppliers FROM DependsOn d JOIN Company c ON c.CompanyID=d.UpstreamCompanyID WHERE d.DownstreamCompanyID='$cid'"));
  
   // Downstream dependencies
   $down = mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(c.CompanyName SEPARATOR ', ') AS Dependencies FROM DependsOn d JOIN Company c ON c.CompanyID=d.DownstreamCompanyID WHERE d.UpstreamCompanyID='$cid'"));
  
   // Financial status
   $fin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT HealthScore, Quarter, RepYear FROM FinancialReport WHERE CompanyID='$cid' ORDER BY RepYear DESC, FIELD(Quarter,'Q4','Q3','Q2','Q1') LIMIT 1"));
  
   // Capacity (for manufacturers)
   $cap = mysqli_fetch_assoc(mysqli_query($conn, "SELECT FactoryCapacity FROM Manufacturer WHERE CompanyID='$cid'"));
  
// Routes (for distributors)
$routes_query = mysqli_query($conn, "SELECT GROUP_CONCAT(CONCAT(f.CompanyName,' → ',t.CompanyName) SEPARATOR ', ') AS Routes FROM OperatesLogistics ol JOIN Company f ON f.CompanyID=ol.FromCompanyID JOIN Company t ON t.CompanyID=ol.ToCompanyID WHERE ol.DistributorID='$cid'");
$routes = $routes_query ? mysqli_fetch_assoc($routes_query) : null;
// Products (next line - NO extra routes code here!)
$prod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(DISTINCT p.ProductName SEPARATOR ', ') AS Products, GROUP_CONCAT(DISTINCT p.Category SEPARATOR ', ') AS Categories FROM SuppliesProduct sp JOIN Product p ON p.ProductID=sp.ProductID WHERE sp.SupplierID='$cid'"));
   echo json_encode([
       "CompanyName" => $c['CompanyName'],
       "Address" => ($loc ? $loc['CountryName'].', '.$loc['ContinentName'] : 'N/A'),
       "LocationID" => $c['LocationID'],  // ✅ ADD THIS LINE
       "Type" => $c['Type'],
       "TierLevel" => $c['TierLevel'],
       "UpstreamSuppliers" => isset($up['Suppliers']) && $up['Suppliers'] ? $up['Suppliers'] : 'N/A',
       "DownstreamDependencies" => isset($down['Dependencies']) && $down['Dependencies'] ? $down['Dependencies'] : 'N/A',
       "FinancialStatus" => $fin ? ($fin['HealthScore']." ({$fin['Quarter']} {$fin['RepYear']})") : "N/A",
       "Capacity" => $cap ? $cap['FactoryCapacity']." units/day" : "Not a Manufacturer",
       "RoutesOperated" => isset($routes['Routes']) && $routes['Routes'] ? $routes['Routes'] : "Not a Distributor",
       "Products" => isset($prod['Products']) && $prod['Products'] ? $prod['Products'] : "N/A",
       "ProductDiversity" => isset($prod['Categories']) && $prod['Categories'] ? $prod['Categories'] : "N/A"
   ]);
   mysqli_close($conn);
   exit;
}


// If we're in API mode but no action matched


   echo json_encode(["error" => "Invalid request"]);
   ob_end_flush();
   mysqli_close($conn);
   exit;
}
?>




<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Company Information</title>
  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js" charset="utf-8"></script>
  <link rel="stylesheet" href="css/dashboard.css?v=14">

<style>
  /* Three-column grid - all aligned at top */
  .company-page .dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
    align-items: stretch !important;
  }
  
  /* Default card styles */
  .company-page .card {
    display: flex;
    flex-direction: column;
  }
  
  /* Company Info and Transactions cards - tall with scroll */
  .company-page .dashboard-grid > .card:nth-child(1),
  .company-page .dashboard-grid > .card:nth-child(2) {
    min-height: 800px;
    max-height: 800px;
    overflow-y: auto;
  }
  
  /* Right column wrapper - specific targeting */
  .company-page .dashboard-grid > div:last-child {
    display: flex !important;
    flex-direction: column !important;
    gap: 20px !important;
    height: 800px !important;
  }
  
  /* KPI card - no scroll, takes up space it needs */
  .company-page .kpi-card {
    overflow: visible !important;
    flex-shrink: 0 !important;
    height: auto !important;
  }
  
  /* Disruption card - fills remaining space with scroll */
  .company-page .disruption-card {
    flex: 1 !important;
    overflow-y: auto !important;
    min-height: 0 !important;
    display: flex !important;
    flex-direction: column !important;
  }
  
  /* Make the update button stick to bottom */
  /* .company-page .card .btn, */
  .company-page .card > p:last-child {
    margin-top: auto !important;
  }
  
  /* KPI graph sizing */
  .company-page .graph-container {
    height: 200px;
    margin: 10px 0;
  }
</style>

</head>

<body class = "company-page">
 <!-- ===== NAVIGATION BAR ===== -->
 <nav>
   <div class="nav-links">
    <a href="company.php" class="active">Company Information</a>
    <a href="disruptions.php">Disruption Events</a>
    <a href="transactions.php">Transactions</a> 
     <button class="logout-btn" onclick="logout()">
        <img src="logout.png" alt="Log Out">
    </button>
    <script>
       function logout() {
           let answer = confirm("Are you sure you want to log out?");
           if (answer) {
               window.location.href = "logout.php";
           }
       }
    </script>
   </div>


   <!-- Search box with dropdown -->
   <div class="search-box">
     <input type="text" id="search-input" placeholder="Search company" autocomplete="off">
     <div class="search-results" id="search-results"></div>
   </div>
 </nav>


 <!-- ===== MAIN CONTENT CONTAINER ===== -->
 <div class="container">
   <h1 class="page-title">Company Name</h1>


   <div class="dashboard-grid">
     <!-- ===== LEFT COLUMN: COMPANY INFORMATION ===== -->
     <div class="card">
       <h2 class="card-title">Company Information</h2>


       <div class="form-row">
         <div class="form-group">
           <label>Address:</label>
           <div style="position: relative;">
             <input type="text" id="address" placeholder="Start typing country name..." autocomplete="off"
               <?php echo $canEdit ? '' : 'readonly style="background-color: #f5f5f5; cursor: not-allowed;"'; ?>>
           <?php if ($canEdit): ?>
               <div class="search-results" id="location-results" style="position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;"></div>
           <?php endif; ?>


           </div>
           <input type="hidden" id="location-id" value="">
          
           <?php if ($canEdit): ?>
               <button class="btn" onclick="showAddLocationForm()" style="margin-top: 5px; padding: 4px 10px; font-size: 12px; width: auto;">+ Add New Location</button>
           <?php endif; ?>


       </div>


         <div class="form-group">
           <label>Company Type:</label>
           <select id="company-type" <?php echo $canEdit ? '' : 'disabled style="background-color: #f5f5f5; cursor: not-allowed;"'; ?>>
             <option value="Manufacturer">Manufacturer</option>
             <option value="Distributor">Distributor</option>
             <option value="Retailer">Retailer</option>
           </select>
         </div>
       </div>


       <div class="form-row">
         <div class="form-group">
           <label>Tier Level:</label>
           <select id="tier-level" <?php echo $canEdit ? '' : 'disabled style="background-color: #f5f5f5; cursor: not-allowed;"'; ?>>
             <option value="1">1</option>
             <option value="2">2</option>
             <option value="3">3</option>
           </select>
         </div>


         <div class="form-group">
           <label>Most Recent Financial Status:</label>
           <input type="text" id="financial-status" readonly>
         </div>
       </div>


       <div class="form-row">
         <div class="form-group full-width">
           <label>Depends On:</label>
           <textarea id="depends-on" readonly rows="2" style="resize: vertical; white-space: pre-line;"></textarea>
         </div>
       </div>


       <div class="form-row">
         <div class="form-group full-width">
           <label>Dependencies:</label>
           <textarea id="dependencies" readonly rows="2" style="resize: vertical; white-space: pre-line;"></textarea>
         </div>
       </div>


       <div class="form-row">
         <div class="form-group">
           <label>Capacity:</label>
           <input type="text" id="capacity" readonly>
         </div>


         <div class="form-group">
           <label>Routes Operated:</label>
           <input type="text" id="routes-operated" readonly>
         </div>
       </div>


       <div class="form-row">
         <div class="form-group full-width">
           <label>Products Supplied:</label>
           <textarea id="products" readonly rows="2" style="resize: vertical; white-space: pre-line;"></textarea>
         </div>
       </div>


       <div class="form-row">
         <div class="form-group full-width">
           <label>Product Diversity:</label>
           <input type="text" id="product-diversity" readonly>
         </div>
       </div>


       <?php if ($canEdit): ?>
       <button class="btn" onclick="updateCompanyInfo()">Update Company Info</button>
       <?php else: ?>
       <p style="color: #666; font-style: italic; text-align: center; margin-top: 5px;">
            View-Only Mode (Senior Manager)
       </p>
       <?php endif; ?>
     </div>


     <!-- ===== MIDDLE COLUMN: TRANSACTIONS ===== -->
     <div class="card">
       <h2 class="card-title">Transactions</h2>


       <div class="date-inputs" style="margin-bottom: 15px;">
         <input type="date" id="transaction-start-date" style="margin-right: 8px;">
         <input type="date" id="transaction-end-date" style="margin-right: 8px;">
         <button class="btn" onclick="filterTransactionsByDate()" style="margin-top: 0; padding: 6px 15px;">Filter by Date</button>
       </div>


       <div class="transaction-section">
         <h3 class="section-label">Shipping</h3>
         <div id="shipping-list" class="transaction-list">
           <p style="color: #999;">No shipping data</p>
         </div>
       </div>


       <div class="transaction-section">
         <h3 class="section-label">Receiving</h3>
         <div id="receiving-list" class="transaction-list">
           <p style="color: #999;">No receiving data</p>
         </div>
       </div>


       <div class="transaction-section">
         <h3 class="section-label">Adjustments</h3>
         <div id="adjustments-list" class="transaction-list">
           <p style="color: #999;">No adjustment data</p>
         </div>
       </div>


       <!-- <button class="btn" onclick="updateTransactions()" style="margin-top: auto;">Update Transaction Data</button> -->
     </div>

<!-- ===== RIGHT COLUMN: TWO STACKED CARDS ===== -->
     <div style="display: flex; flex-direction: column; gap: 20px;">
       
       <!-- KPI CARD (NO SCROLL) -->
       <div class="card kpi-card">
         <h2 class="card-title">Key Performance Indicators</h2>

         <div class="date-inputs" style="margin-bottom: 15px;">
           <input type="date" id="kpi-start-date" style="margin-right: 8px;">
           <input type="date" id="kpi-end-date" style="margin-right: 8px;">
           <button class="btn" onclick="filterKPIsByDate()" style="margin-top: 0; padding: 6px 15px;">Filter by Date</button>
         </div>

         <div class="kpi-metric">
           <div class="kpi-label">On-Time Delivery Rate</div>
           <div class="kpi-value" id="delivery-rate">--</div>
           <div class="kpi-description">Shipments where delivery date ≤ promised date</div>
         </div>

         <div class="kpi-metric">
           <div class="kpi-label">Delay Statistics</div>
           <div style="font-size: 13px; color: #555;">
             <div id="avg-delay">Average Delay: --</div>
             <div id="std-delay">Standard Deviation of Delay: --</div>
           </div>
         </div>

         <div class="kpi-metric">
           <div class="kpi-label">Financial Health Status (Past Year)</div>
           <div class="status-healthy" id="financial-health-status">--</div>
           <div id="financial-graph" class="graph-container"></div>
         </div>
       </div>

       <!-- DISRUPTION EVENTS CARD (SCROLLABLE) -->
       <div class="card disruption-card">
         <h2 class="card-title">Disruption Events</h2>
         <div class="kpi-metric">
           <div class="kpi-label">Recent Events (All Time)</div>
           <div id="event-list" style="max-height: 300px; overflow-y: auto; margin-top: 8px;">
             <p style="color: #999; font-size: 12px;">No events</p>
           </div>
         </div>
       </div>
     
     </div>
   </div>
   
<!-- Add New Location Modal -->
<div id="add-location-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center;">
   <div style="background: white; padding: 30px; border-radius: 8px; width: 400px; max-width: 90%;">
       <h3 style="margin-top: 0;">Add New Location</h3>
       <div style="margin-bottom: 15px;">
           <label style="display: block; margin-bottom: 5px;">Country:</label>
           <input type="text" id="new-country" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
       </div>
       <div style="margin-bottom: 20px;">
           <label style="display: block; margin-bottom: 5px;">Continent:</label>
           <select id="new-continent" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
               <option value="">Select Continent</option>
               <option value="Africa">Africa</option>
               <option value="Asia">Asia</option>
               <option value="Europe">Europe</option>
               <option value="North America">North America</option>
               <option value="South America">South America</option>
               <option value="Oceania">Oceania</option>
               <option value="Antarctica">Antarctica</option>
           </select>
       </div>
       <div style="display: flex; gap: 10px;">
           <button class="btn" onclick="saveNewLocation()" style="flex: 1;">Save</button>
           <button class="btn" onclick="closeAddLocationForm()" style="flex: 1; background: #666;">Cancel</button>
       </div>
   </div>
</div>
</div>


 <!-- ===== JAVASCRIPT CODE ===== -->
 <script>


   var userRole = '<?php echo $_SESSION['role']; ?>';
   var canEdit = <?php echo $canEdit ? 'true' : 'false'; ?>;
   console.log("User role:", userRole, "Can edit:", canEdit);






   var searchInput = document.getElementById("search-input");
   var searchResults = document.getElementById("search-results");
   var currentCompanyId = null;


   // SEARCH FUNCTIONALITY
   searchInput.addEventListener("input", function() {
       var searchTerm = searchInput.value.trim();
       if (searchTerm.length < 1) {
           searchResults.classList.remove("show");
           return;
       }


       var xhttp = new XMLHttpRequest();
       xhttp.onload = function() {
           if (this.readyState == 4 && this.status == 200) {
               var data = JSON.parse(this.responseText);
               searchResults.innerHTML = "";
               if (data.length == 0) {
                   searchResults.innerHTML = '<div class="no-results">No companies found</div>';
                   searchResults.classList.add("show");
                   return;
               }
               data.forEach(c => {
                   var item = document.createElement("div");
                   item.className = "search-result-item";
                   item.textContent = c.CompanyName;
                   item.setAttribute("data-id", c.CompanyID);
                   item.onclick = function() {
                       loadCompanyInfo(this.getAttribute("data-id"));
                       searchResults.classList.remove("show");
                       searchInput.value = this.textContent;
                   };
                   searchResults.appendChild(item);
               });
               searchResults.classList.add("show");
           }
       };
       xhttp.open("GET", "company.php?action=search&query=" + encodeURIComponent(searchTerm), true);
       xhttp.send();
   });


   document.addEventListener("click", function(event) {
       if (!event.target.closest(".search-box")) {
           searchResults.classList.remove("show");
       }
   });


   // =====================================================================
// LOCATION SEARCH FUNCTIONALITY
// =====================================================================
var addressInput = document.getElementById("address");
var locationResults = document.getElementById("location-results");
var locationIdInput = document.getElementById("location-id");


if (canEdit) {
addressInput.addEventListener("input", function() {
   var searchTerm = addressInput.value.trim();
  
   if (searchTerm.length < 1) {
       locationResults.classList.remove("show");
       locationIdInput.value = "";
       return;
   }
  
   var xhttp = new XMLHttpRequest();
   xhttp.onload = function() {
       if (this.readyState == 4 && this.status == 200) {
           var data = JSON.parse(this.responseText);
           locationResults.innerHTML = "";
          
           if (data.length == 0) {
               locationResults.innerHTML = '<div class="no-results">No locations found - click "+ Add New Location" to create one</div>';
               locationResults.classList.add("show");
               locationIdInput.value = "";
               return;
           }
          
           data.forEach(function(loc) {
               var item = document.createElement("div");
               item.className = "search-result-item";
               item.textContent = loc.CountryName + " (" + loc.ContinentName + ")";
               item.setAttribute("data-id", loc.LocationID);
               item.setAttribute("data-display", loc.CountryName + ", " + loc.ContinentName);
              
               item.onclick = function() {
                   locationIdInput.value = this.getAttribute("data-id");
                   addressInput.value = this.getAttribute("data-display");
                   locationResults.classList.remove("show");
               };
              
               locationResults.appendChild(item);
           });
          
           locationResults.classList.add("show");
       }
   };
   xhttp.open("GET", "company.php?action=search_location&query=" + encodeURIComponent(searchTerm), true);
   xhttp.send();
});


document.addEventListener("click", function(event) {
   if (!event.target.closest("#address") && !event.target.closest("#location-results")) {
       locationResults.classList.remove("show");
   }
 });


}




// =====================================================================
// ADD NEW LOCATION MODAL
// =====================================================================
function showAddLocationForm() {
   if (!canEdit) {
       alert("You don't have permission to add locations.");
       return;
   }
   document.getElementById("add-location-modal").style.display = "flex";
   document.getElementById("new-country").value = "";
   document.getElementById("new-continent").value = "";
}


function closeAddLocationForm() {
   document.getElementById("add-location-modal").style.display = "none";
}


function saveNewLocation() {
   var country = document.getElementById("new-country").value.trim();
   var continent = document.getElementById("new-continent").value;
  
   if (!country || !continent) {
       alert("Please fill in all fields");
       return;
   }
  
   // ✅ Validate country exists using REST Countries API
   var countryCheckXhr = new XMLHttpRequest();
   countryCheckXhr.onload = function() {
       if (this.status === 200) {
           try {
               var countries = JSON.parse(this.responseText);
              
               if (countries.length === 0 || countries.status === 404) {
                   alert("Country '" + country + "' not found. Please check spelling.");
                   return;
               }
              
               // Country exists, now check if continent matches
               var actualContinent = countries[0].continents ? countries[0].continents[0] : null;
              
               if (!actualContinent) {
                   alert("Could not verify continent for this country.");
                   return;
               }
              
               // Normalize continent names for comparison
               var continentMap = {
                   'North America': 'North America',
                   'South America': 'South America',
                   'Europe': 'Europe',
                   'Asia': 'Asia',
                   'Africa': 'Africa',
                   'Oceania': 'Oceania',
                   'Antarctica': 'Antarctica'
               };
              
               if (actualContinent !== continent) {
                   var confirmMsg = "Warning: '" + country + "' is in " + actualContinent + ", not " + continent + ". Continue anyway?";
                   if (!confirm(confirmMsg)) {
                       return;
                   }
               }
              
               // Validation passed, save location
               var formData = new FormData();
               formData.append("action", "add_location");
               formData.append("country", country);
               formData.append("continent", continent);
              
               var xhttp = new XMLHttpRequest();
               xhttp.onload = function() {
                   if (this.status === 200) {
                       try {
                           var response = JSON.parse(this.responseText);
                           if (response.success) {
                               alert("Location added successfully!");
                               locationIdInput.value = response.location_id;
                               addressInput.value = country + ", " + continent;
                               closeAddLocationForm();
                           } else {
                               alert("Error: " + response.error);
                           }
                       } catch (e) {
                           alert("Unexpected server response.");
                           console.log(this.responseText);
                       }
                   }
               };
               xhttp.open("POST", "company.php", true);
               xhttp.send(formData);
              
           } catch (e) {
               alert("Could not verify country. Please check spelling.");
               console.error(e);
           }
       } else if (this.status === 404) {
           alert("Country '" + country + "' not found. Please check spelling.");
       } else {
           alert("Could not verify country. Please check your internet connection.");
       }
   };
  
   // Call REST Countries API to validate country
   countryCheckXhr.open("GET", "https://restcountries.com/v3.1/name/" + encodeURIComponent(country) + "?fullText=true", true);
   countryCheckXhr.send();
}
   // LOAD COMPANY INFORMATION
   function loadCompanyInfo(companyId) {
       currentCompanyId = companyId;
       var xhttp = new XMLHttpRequest();
       xhttp.onload = function() {
           if (this.readyState == 4 && this.status == 200) {
               var data = JSON.parse(this.responseText);
               if (data.error) {
                   alert("Error: " + data.error);
                   return;
               }


               document.querySelector(".page-title").textContent = data.CompanyName;
               document.getElementById("address").value = data.Address;
               document.getElementById("location-id").value = data.LocationID;
               document.getElementById("company-type").value = data.Type;
               document.getElementById("tier-level").value = data.TierLevel;
               document.getElementById("depends-on").value = data.UpstreamSuppliers.replace(/, /g, "\n");
               document.getElementById("dependencies").value = data.DownstreamDependencies.replace(/, /g, "\n");
               document.getElementById("financial-status").value = data.FinancialStatus;
               document.getElementById("capacity").value = data.Capacity;
               document.getElementById("routes-operated").value = data.RoutesOperated;
               document.getElementById("products").value = data.Products.replace(/, /g, "\n");
           document.getElementById("product-diversity").value = data.ProductDiversity;
          // ✅ Auto-resize textareas to fit content
setTimeout(function() {
    document.querySelectorAll('textarea').forEach(function(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = (textarea.scrollHeight + 4) + 'px';
    });
}, 200);
           // ✅ AUTO-SET YEAR-TO-DATE (YTD) dates
           var today = new Date();
           var year = today.getFullYear();
           var month = String(today.getMonth() + 1).padStart(2, '0');
           var day = String(today.getDate()).padStart(2, '0');
           var todayStr = year + '-' + month + '-' + day;
           var ytdStart = year + '-01-01';
          
           // Set transaction dates
           document.getElementById("transaction-start-date").value = ytdStart;
           document.getElementById("transaction-end-date").value = todayStr;
          
           // Set KPI dates
           document.getElementById("kpi-start-date").value = ytdStart;
           document.getElementById("kpi-end-date").value = todayStr;
          
           // Load data with YTD dates
           loadTransactionData(companyId, ytdStart, todayStr);
           loadKPIData(companyId, ytdStart, todayStr);
       }
   };
   xhttp.open("GET", "company.php?company=" + companyId, true);
   xhttp.send();
}


// UPDATE COMPANY INFO (tier/type/location)
function updateCompanyInfo() {
 
   if (!canEdit) {
       alert("You don't have permission to update company information.");
       return;
   }


   if (!currentCompanyId) {
       alert("Please select a company first.");
       return;
   }
  
   var newTier = document.getElementById("tier-level").value;
   var newType = document.getElementById("company-type").value;
   var newLocationId = document.getElementById("location-id").value;
  
   if (!newLocationId) {
       alert("Please select a valid location from the dropdown or add a new one.");
       return;
   }
  
   var formData = new FormData();
   formData.append("action", "update_company");
   formData.append("company_id", currentCompanyId);
   formData.append("tier", newTier);
   formData.append("type", newType);
   formData.append("location_id", newLocationId);
  
   var xhttp = new XMLHttpRequest();
   xhttp.onload = function() {
       if (this.status === 200) {
           try {
               var response = JSON.parse(this.responseText);
               if (response.success) {
                   alert("Company updated successfully!");
                   loadCompanyInfo(currentCompanyId);
               } else {
                   alert("Update failed: " + response.error);
               }
           } catch (e) {
               alert("Unexpected server response.");
               console.log(this.responseText);
           }
       }
   };
   xhttp.open("POST", "company.php", true);
   xhttp.send(formData);
}


   // ✅ UPDATED: LOAD TRANSACTION DATA WITH EDIT CAPABILITY
function loadTransactionData(companyId, startDate, endDate) {
   if (!companyId) {
       return;
   }


   var url = "company.php?action=transactions&company=" + companyId;
   if (startDate && endDate) {
       url += "&start_date=" + startDate + "&end_date=" + endDate;
   }


   var xhttp = new XMLHttpRequest();
   xhttp.onload = function() {
       if (this.readyState == 4 && this.status == 200) {
           try {
               var data = JSON.parse(this.responseText);
               if (data.error) {
                   alert("Error loading transactions: " + data.error);
                   return;
               }


               // ===== UPDATE SHIPPING (with edit capability) =====
               var shippingList = document.getElementById("shipping-list");
               if (data.shipping.length === 0) {
                   shippingList.innerHTML = '<p style="color: #999;">No shipping data</p>';
               } else {
                   shippingList.innerHTML = "";
                   data.shipping.forEach(function(item) {
                       var div = document.createElement("div");
                       div.className = "transaction-item";
                      
                       // Check if editable (ActualDate is NULL)
                       var isEditable = !item.ActualDate && canEdit;
                      
                       var html = "<strong>Shipment #" + item.ShipmentID + "</strong><br>" +
                           "Product: " + item.Product + "<br>" +
                           "From: " + item.SourceCompany + " &rarr; " + item.DestinationCompany + "<br>" +
                           "Promised: " + item.PromisedDate + "<br>";
                      
                       if (isEditable) {
                           // Editable fields
                           html += "Actual Date: <input type='date' id='actual-" + item.ShipmentID + "' " +
                               "style='width: 130px; padding: 2px; font-size: 11px;' /><br>" +
                               "Quantity: <input type='number' id='qty-" + item.ShipmentID + "' " +
                               "value='" + item.Quantity + "' " +
                               "style='width: 80px; padding: 2px; font-size: 11px;' /><br>" +
                               "<button class='btn' onclick='window.updateShipment(" + item.ShipmentID + ")' " +
                               "style='margin-top: 5px; padding: 3px 8px; font-size: 11px;'>Save Changes</button>";
                       } else {
                           // Read-only (completed)
                           html += "Actual: " + (item.ActualDate || "Pending") + "<br>" +
                           "Quantity: " + item.Quantity + " <em>" +
                           (!item.ActualDate && !canEdit ? "(View-only)" : "(Completed)") + "</em>";
                       }
                      
                       div.innerHTML = html;
                       shippingList.appendChild(div);
                   });
               }


               // ===== UPDATE RECEIVING (read-only, already completed) =====
               var receivingList = document.getElementById("receiving-list");
               if (data.receiving.length === 0) {
                   receivingList.innerHTML = '<p style="color: #999;">No receiving data</p>';
               } else {
                   receivingList.innerHTML = "";
                   data.receiving.forEach(function(item) {
                       var div = document.createElement("div");
                       div.className = "transaction-item";
                       div.innerHTML = "<strong>Receipt #" + item.ReceivingID + "</strong><br>" +
                           "Product: " + item.Product + "<br>" +
                           "Company: " + item.Company + "<br>" +
                           "Received: " + item.ReceivedDate + "<br>" +
                           "Quantity: " + item.Quantity + " <em>(Completed)</em>";
                       receivingList.appendChild(div);
                   });
               }


               // ===== UPDATE ADJUSTMENTS (with edit capability) =====
               var adjustmentsList = document.getElementById("adjustments-list");
               if (data.adjustments.length === 0) {
                   adjustmentsList.innerHTML = '<p style="color: #999;">No adjustment data</p>';
               } else {
                   adjustmentsList.innerHTML = "";
                   data.adjustments.forEach(function(item) {
                       var div = document.createElement("div");
                       div.className = "transaction-item";
                      
                       // Adjustments are editable if they're in the future
                       var adjustmentDate = new Date(item.Date);
                       var today = new Date();
                       var isEditable = (adjustmentDate >= today) && canEdit;
                      
                       var html = "<strong>Adjustment #" + item.AdjustmentID + "</strong><br>" +
                           "Product: " + item.Product + "<br>" +
                           "Company: " + item.Company + "<br>" +
                           "Date: " + item.Date + "<br>";
                      
                       if (isEditable) {
                           html += "Quantity Change: <input type='number' id='adj-qty-" + item.AdjustmentID + "' " +
                               "value='" + item.QuantityChange + "' " +
                               "style='width: 80px; padding: 2px; font-size: 11px;' /><br>" +
                               "Reason: <input type='text' id='adj-reason-" + item.AdjustmentID + "' " +
                               "value='" + item.Reason + "' " +
                               "style='width: 200px; padding: 2px; font-size: 11px;' /><br>" +
                               "<button class='btn' onclick='window.updateAdjustment(" + item.AdjustmentID + ")' " +
                               "style='margin-top: 5px; padding: 3px 8px; font-size: 11px;'>Save Changes</button>";
                       } else {
                           html += "Quantity Change: " + item.QuantityChange + "<br>" +
                           "Reason: " + item.Reason + " <em>" +
                           (adjustmentDate >= today && !canEdit ? "(View-only)" : "(Completed)") + "</em>";
                       }
                      
                       div.innerHTML = html;
                       adjustmentsList.appendChild(div);
                   });
               }
           } catch (e) {
               console.error("Parse error:", e);
           }
       }
   };
   xhttp.open("GET", url, true);
   xhttp.send();
}


   function filterTransactionsByDate() {
       if (!currentCompanyId) {
           alert("Please select a company first.");
           return;
       }


       var startDate = document.getElementById("transaction-start-date").value;
       var endDate = document.getElementById("transaction-end-date").value;


       if (!startDate || !endDate) {
           alert("Please select both start and end dates.");
           return;
       }


       loadTransactionData(currentCompanyId, startDate, endDate);
   }


   // function updateTransactions() {
   //     if (!currentCompanyId) {
   //         alert("Please select a company first.");
   //         return;
   //     }
   //     loadTransactionData(currentCompanyId);
   // }


// LOAD KPI DATA WITH GRAPH AND EVENTS
function loadKPIData(companyId, startDate, endDate) {
   if (!companyId) {
       return;
   }
   var url = "company.php?action=kpi&company=" + companyId;
   if (startDate && endDate) {
       url += "&start_date=" + startDate + "&end_date=" + endDate;
   }
   var xhttp = new XMLHttpRequest();
   xhttp.onload = function() {
       if (this.readyState == 4 && this.status == 200) {
           try {
               var data = JSON.parse(this.responseText);
              
               if (data.error) {
                   alert("Error loading KPIs: " + data.error);
                   return;
               }
              
               document.getElementById("delivery-rate").textContent =
                   data.deliveryRate !== null ? data.deliveryRate + "%" : "--";
               document.getElementById("avg-delay").textContent =
                   "Average Delay: " + (data.avgDelay !== null ? data.avgDelay : "--") + " days";
               document.getElementById("std-delay").textContent =
                   "Standard Deviation of Delay: " + (data.stdDelay !== null ? data.stdDelay : "--") + " days";
               document.getElementById("financial-health-status").textContent =
                   data.financialStatus || "--";
              
               if (data.financialTrend && data.financialTrend.length > 0) {
                   var financialTrace = {
                       x: data.financialTrend.map(function(item) { return item.quarter; }),
                       y: data.financialTrend.map(function(item) { return item.score; }),
                       type: 'scatter',
                       mode: 'lines+markers',
                       line: { color: '#22c55e', width: 2 },
                       marker: { size: 6 }
                   };
                  
                   var financialLayout = {
                       margin: { t: 10, r: 10, b: 60, l: 40 },  // ✅ Increased bottom margin from 40 to 60
                       xaxis: {
                           title: 'Quarter',
                           tickangle: -45,
                           tickfont: { size: 9 },  // ✅ Slightly smaller font
                           automargin: true  // ✅ Auto-adjust margins to prevent overlap
                       },
                       yaxis: {
                           title: 'Health Score',
                           range: [0, 100]
                       },
                       height: 220  // ✅ Increased height from 200 to 220 to accommodate labels
                   };
                  
                   Plotly.newPlot('financial-graph', [financialTrace], financialLayout, {displayModeBar: false});
               } else {
                   document.getElementById("financial-graph").innerHTML = '<p style="color: #999; font-size: 12px; text-align: center;">No financial data available</p>';
               }
              
               var eventList = document.getElementById("event-list");
               if (data.events && data.events.length > 0) {
                   eventList.innerHTML = "";
                   data.events.forEach(function(event) {
                       var eventItem = document.createElement("div");
                       eventItem.className = "event-list-item";
                       eventItem.style.cssText = "padding: 8px; margin: 5px 0; background: #f5f5f5; border-radius: 4px; font-size: 12px;";
                       eventItem.innerHTML = "<strong>" + event.name + "</strong><br>" +
                           "Date: " + event.date + "<br>" +
                           "Impact: " + event.impact;
                       eventList.appendChild(eventItem);
                   });
               } else {
                   eventList.innerHTML = '<p style="color: #999; font-size: 12px;">No events</p>';
               }
              
           } catch (e) {
               console.error("Parse error:", e);
           }
       }
   };
   xhttp.open("GET", url, true);
   xhttp.send();
}


   function filterKPIsByDate() {
       if (!currentCompanyId) {
           alert("Please select a company first.");
           return;
       }


       var startDate = document.getElementById("kpi-start-date").value;
       var endDate = document.getElementById("kpi-end-date").value;


       if (!startDate || !endDate) {
           alert("Please select both start and end dates.");
           return;
       }


       loadKPIData(currentCompanyId, startDate, endDate);
   }


   function updateKPIs() {
       if (!currentCompanyId) {
           alert("Please select a company first.");
           return;
       }
       loadKPIData(currentCompanyId);
   }


// Ask server for the role
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
   });


   // ✅ NEW: UPDATE SHIPMENT
function updateShipment(shipmentId) {
   var actualDate = document.getElementById("actual-" + shipmentId).value;
   var quantity = document.getElementById("qty-" + shipmentId).value;
   if (!canEdit) {
       alert("You don't have permission to update shipments.");
       return;
   }


   if (!actualDate) {
       alert("Please enter an actual delivery date.");
       return;
   }
  
   if (!quantity || quantity <= 0) {
       alert("Please enter a valid quantity.");
       return;
   }
  
   var formData = new FormData();
   formData.append("action", "update_shipment");
   formData.append("shipment_id", shipmentId);
   formData.append("actual_date", actualDate);
   formData.append("quantity", quantity);
  
   var xhttp = new XMLHttpRequest();
   xhttp.onload = function() {
       if (this.status === 200) {
           try {
               var response = JSON.parse(this.responseText);
               if (response.success) {
                   alert("Shipment updated successfully!");
                   loadTransactionData(currentCompanyId);
               } else {
                   alert("Update failed: " + response.error);
               }
           } catch (e) {
               alert("Unexpected server response.");
               console.log(this.responseText);
           }
       }
   };
   xhttp.open("POST", "company.php", true);
   xhttp.send(formData);
}


function updateAdjustment(adjustmentId) {
   if (!canEdit) {
       alert("You don't have permission to update adjustments.");
       return;
   }
  
   var quantityChange = document.getElementById("adj-qty-" + adjustmentId).value;
   var reason = document.getElementById("adj-reason-" + adjustmentId).value;
  
   if (!quantityChange) {
       alert("Please enter a quantity change.");
       return;
   }
  
   if (!reason || reason.trim() === "") {
       alert("Please enter a reason for the adjustment.");
       return;
   }
  
   var formData = new FormData();
   formData.append("action", "update_adjustment");
   formData.append("adjustment_id", adjustmentId);
   formData.append("quantity_change", quantityChange);
   formData.append("reason", reason);
  
   var xhttp = new XMLHttpRequest();
   xhttp.onload = function() {
       console.log("Status:", this.status); // ✅ ADD THIS
       console.log("Response:", this.responseText); // ✅ ADD THIS
      
       if (this.status === 200) {
           try {
               var response = JSON.parse(this.responseText);
               if (response.success) {
                   alert("Adjustment updated successfully!");
                   loadTransactionData(currentCompanyId);
               } else {
                   alert("Update failed: " + response.error);
               }
           } catch (e) {
               alert("Unexpected server response.");
               console.log("Parse error:", e); // ✅ ADD THIS
               console.log(this.responseText);
           }
       }
   };
   xhttp.open("POST", "company.php", true);
   xhttp.send(formData);
}

(function() {
    // Replace current history entry
    if (window.history && window.history.pushState) {
        // Push current state multiple times to fill history
        for (let i = 0; i < 10; i++) {
            window.history.pushState(null, null, window.location.href);
        }
        
        // When user presses back, push forward again
        window.onpopstate = function() {
            window.history.pushState(null, null, window.location.href);
        };
    }
})();

// ✅ AUTO-LOAD ABBOTT-MUNOZ ON PAGE LOAD
window.addEventListener('DOMContentLoaded', function() {
    // Search for Abbott-Munoz
    var xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
        if (this.readyState == 4 && this.status == 200) {
            var data = JSON.parse(this.responseText);
            if (data && data.length > 0) {
                // Find Abbott-Munoz in results
                var abbottMunoz = data.find(function(company) {
                    return company.CompanyName === 'Abbott-Munoz';
                });
                
                if (abbottMunoz) {
                    // Set search box text
                    searchInput.value = 'Abbott-Munoz';
                    // Load company data
                    loadCompanyInfo(abbottMunoz.CompanyID);
                }
            }
        }
    };
    xhttp.open("GET", "company.php?action=search&query=Abbott-Munoz", true);
    xhttp.send();
});


</script>
</body>
</html>

