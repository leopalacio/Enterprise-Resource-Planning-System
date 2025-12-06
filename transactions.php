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

    // =====================================================================
    // OUTPUT BUFFERING
    // ob_start() - prevents "headers already sent" errors
    // Source: https://www.php.net/manual/en/function.ob-start.php
    // =====================================================================
    ob_start();

    // =====================================================================
    // TIMEZONE SETTING
    // date_default_timezone_set() - ensures consistent time handling
    // Source: https://www.php.net/manual/en/function.date-default-timezone-set.php
    // =====================================================================
    date_default_timezone_set('America/Indiana/Indianapolis');

    // =====================================================================
    // ERROR REPORTING CONFIGURATION
    // Turn off error display for production - errors would break JSON response
    // Source: https://www.php.net/manual/en/function.ini-set.php
    // =====================================================================
    ini_set('display_errors', 0);
    error_reporting(0);

    // =====================================================================
    // DATABASE CONNECTION CONFIGURATION
    // Connection pattern - from test.php and PHP lab (php_lab_sols1__1_.pdf Section 2)
    // =====================================================================
    $servername = "mydb.itap.purdue.edu";
    $username = "g1151928";
    $password = "JuK3J593";
    $dbname = "g1151928";

    // =====================================================================
    // CREATE DATABASE CONNECTION
    // mysqli_connect() - from test.php line 33 and PHP lab Section 2
    // This is the standard MySQLi procedural connection method
    // =====================================================================
    $conn = mysqli_connect($servername, $username, $password, $dbname);

    // =====================================================================
    // SET CHARACTER ENCODING
    // mysqli_set_charset() - handles special characters properly
    // Source: https://www.php.net/manual/en/mysqli.set-charset.php
    // =====================================================================
    mysqli_set_charset($conn, "utf8mb4");

    // =====================================================================
    // CHECK CONNECTION
    // Connection error handling - from test.php lines 36-38 and PHP lab
    // die() immediately stops script execution
    // =====================================================================
    if (!$conn) {
        ob_clean();
        header('Content-Type: application/json');
        die(json_encode(["error" => "Connection failed: " . mysqli_connect_error()]));
    }

    // =====================================================================
    // HANDLE COMPANY SEARCH
    // isset() and $_GET - from PHP lab (php_lab_sols1__1_.pdf problem 2)
    // Action-based routing pattern for handling multiple request types
    // =====================================================================
    if(isset($_GET['action']) && $_GET['action'] == 'company_search') {

        // =====================================================================
        // SQL INJECTION PREVENTION - CURRENT METHOD
        // mysqli_real_escape_string() - from PHP lab
        // NOTE: PHP lab problem 24 recommends using prepared statements instead
        // for better security (see recommendations at end of file)
        // =====================================================================
        $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);

        // =====================================================================
        // BUILD SQL QUERY
        // LIKE with % wildcards for partial matching - standard SQL pattern
        // LIMIT 10 - restricts results for performance
        // =====================================================================
        $sql = "SELECT CompanyID, CompanyName FROM Company WHERE CompanyName LIKE '" . $searchTerm . "%' ORDER BY CompanyName LIMIT 10";

        // =====================================================================
        // EXECUTE QUERY
        // mysqli_query() - from test.php line 55 and PHP lab
        // Returns result set or false on failure
        // =====================================================================
        $result = mysqli_query($conn, $sql);

        // Error handling for failed query
        if(!$result) {
            ob_clean();
            header('Content-Type: application/json');
            die(json_encode(["error" => "Query failed"]));
        }

        // =====================================================================
        // FETCH RESULTS INTO ARRAY
        // mysqli_fetch_assoc() - from test.php line 59 and PHP lab
        // Fetches one row at a time as associative array
        // =====================================================================
        $companies = [];
        while($row = mysqli_fetch_assoc($result)) {
            $companies[] = $row; // Array push shorthand - adds row to end
        }

        // =====================================================================
        // SEND JSON RESPONSE
        // ob_clean() - clears output buffer before sending response
        // header() - sets content type to JSON - from PHP lab
        // json_encode() - converts PHP array to JSON - from test.php line 68
        // =====================================================================
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($companies);
        exit;

        // =====================================================================
        // HANDLE REGION SEARCH
        // Same pattern as company search
        // =====================================================================
        // =====================================================================
        // HANDLE REGION SEARCH
        // Same pattern as company search
        // =====================================================================
    } elseif(isset($_GET['action']) && $_GET['action'] == 'region_search') {
        $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);

        // DISTINCT keyword - SQL for unique values only
        $sql = "SELECT DISTINCT ContinentName FROM Location WHERE ContinentName LIKE '%" . $searchTerm . "%' ORDER BY ContinentName LIMIT 10";
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
        exit;

        // =====================================================================
        // HANDLE TRANSACTION DATA REQUEST
        // This is the main data retrieval for the dashboard
        // =====================================================================
    } elseif(isset($_GET['action']) && $_GET['action'] == 'get_transactions') {

        // =====================================================================
        // GET FILTER PARAMETERS FROM URL
        // Ternary operator (condition ? true : false) - standard PHP
        // isset() checks if variable exists - from PHP lab
        // =====================================================================
        $start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
        $region = isset($_GET['region']) ? mysqli_real_escape_string($conn, $_GET['region']) : '';
        $source_company = isset($_GET['source_company']) ? mysqli_real_escape_string($conn, $_GET['source_company']) : '';
        $destination_company = isset($_GET['destination_company']) ? mysqli_real_escape_string($conn, $_GET['destination_company']) : '';

        // Initialize response array - will hold all query results
        $response = [];

        // =====================================================================
        // QUERY 1: SHIPMENT VOLUME BY DISTRIBUTOR
        // Uses REGION if available, otherwise uses DATE RANGE
        // =====================================================================

        // =====================================================================
        // DYNAMIC WHERE CLAUSE BUILDING
        // Using array to build WHERE conditions dynamically
        // This pattern allows flexible query construction based on filters
        // =====================================================================
        $where_volume = [];
        $joins_volume = "FROM Shipping s INNER JOIN Company d ON d.CompanyID = s.DistributorID";

        // REGION filter takes priority
        if (!empty($region)) {
            // Additional JOINs needed to access location data
            $joins_volume .= " INNER JOIN Company src ON src.CompanyID = s.SourceCompanyID INNER JOIN Location l ON l.LocationID = src.LocationID";
            $where_volume[] = "l.ContinentName = '$region'";
        }
        // If no region, use date range
        elseif (!empty($start_date) && !empty($end_date)) {
            // BETWEEN operator - SQL for range checking
            $where_volume[] = "s.PromisedDate BETWEEN '$start_date' AND '$end_date'";
        }

        if (!empty($source_company)) {
            $where_volume[] = "s.SourceCompanyID = '$source_company'";
        }

        if (!empty($destination_company)) {
            $where_volume[] = "s.DestinationCompanyID = '$destination_company'";
        }

        // =====================================================================
        // IMPLODE FUNCTION
        // implode() - joins array elements into string with delimiter
        // Source: https://www.php.net/manual/en/function.implode.php
        // count() - gets array length - standard PHP function
        // =====================================================================
        $where_clause_volume = count($where_volume) > 0 ? "WHERE " . implode(" AND ", $where_volume) : "";

        // =====================================================================
        // SQL AGGREGATE FUNCTION
        // SUM() - SQL aggregate function to total quantities
        // GROUP BY - groups results by distributor
        // =====================================================================
        $sql_volume = "SELECT d.CompanyName AS DistributorName, SUM(s.Quantity) AS TotalQty $joins_volume $where_clause_volume GROUP BY s.DistributorID, d.CompanyName ORDER BY TotalQty DESC";
        $result_volume = mysqli_query($conn, $sql_volume);

        $volume_data = [];
        if($result_volume) {
            while($row = mysqli_fetch_assoc($result_volume)) {
                $volume_data[] = $row;
            }
        }
        $response['shipment_volume'] = $volume_data;

        // =====================================================================
        // QUERY 2: ON-TIME DELIVERY RATE
        // Complex query using subquery to find relevant distributors
        // then calculating their global delivery rate
        // =====================================================================

        $where_distributors = [];
        $joins_distributors = "FROM Shipping s INNER JOIN Distributor dist ON s.DistributorID = dist.CompanyID";

        // Same filter logic as Query 1
        if (!empty($region)) {
            $joins_distributors .= " INNER JOIN Company src ON src.CompanyID = s.SourceCompanyID INNER JOIN Location l ON l.LocationID = src.LocationID";
            $where_distributors[] = "l.ContinentName = '$region'";
        } elseif (!empty($start_date) && !empty($end_date)) {
            $where_distributors[] = "s.PromisedDate BETWEEN '$start_date' AND '$end_date'";
        }

        if (!empty($source_company)) {
            $where_distributors[] = "s.SourceCompanyID = '$source_company'";
        }

        if (!empty($destination_company)) {
            $where_distributors[] = "s.DestinationCompanyID = '$destination_company'";
        }

        $where_clause_distributors = count($where_distributors) > 0 ? "WHERE " . implode(" AND ", $where_distributors) : "";

        // =====================================================================
        // ADVANCED SQL: SUBQUERY AND PERCENTAGE CALCULATION
        // Uses subquery to find relevant distributors, then calculates rate
        // SUM(condition) * 100.0 / COUNT(*) - percentage calculation in SQL
        // =====================================================================
        $sql_delivery = "SELECT d.CompanyName AS DistributorName, SUM(s2.ActualDate <= s2.PromisedDate) * 100.0 / COUNT(*) AS DeliveryRate
            FROM (
                SELECT DISTINCT dist.CompanyID
                $joins_distributors
                $where_clause_distributors
            ) AS relevant_distributors
            INNER JOIN Distributor dist ON dist.CompanyID = relevant_distributors.CompanyID
            INNER JOIN Company d ON dist.CompanyID = d.CompanyID
            INNER JOIN Shipping s2 ON s2.DistributorID = dist.CompanyID
            WHERE s2.ActualDate IS NOT NULL
            GROUP BY d.CompanyName
            ORDER BY DeliveryRate DESC";

        $result_delivery = mysqli_query($conn, $sql_delivery);

        $delivery_data = [];
        if($result_delivery) {
            while($row = mysqli_fetch_assoc($result_delivery)) {
                $delivery_data[] = $row;
            }
        }
        $response['delivery_rate'] = $delivery_data;
        // =====================================================================
        // QUERY 3: SHIPMENT STATUS
        // Uses LEFT JOIN to include shipments without receiving records
        // =====================================================================

        $where_status = [];
        $joins_status = "FROM Shipping s LEFT JOIN Receiving r ON r.ShipmentID = s.ShipmentID";

        if (!empty($region)) {
            $joins_status .= " INNER JOIN Company src ON src.CompanyID = s.SourceCompanyID INNER JOIN Location l ON l.LocationID = src.LocationID";
            $where_status[] = "l.ContinentName = '$region'";
        }
        elseif (!empty($start_date) && !empty($end_date)) {
            $where_status[] = "s.PromisedDate BETWEEN '$start_date' AND '$end_date'";
        }

        if (!empty($source_company)) {
            $where_status[] = "s.SourceCompanyID = '$source_company'";
        }

        if (!empty($destination_company)) {
            $where_status[] = "s.DestinationCompanyID = '$destination_company'";
        }

        $where_clause_status = count($where_status) > 0 ? "WHERE " . implode(" AND ", $where_status) : "";

        // =====================================================================
        // CASE STATEMENT IN SQL
        // CASE provides conditional logic within SQL query
        // Similar to if/else in programming languages
        // =====================================================================
        $sql_status = "SELECT 
                CASE 
                    WHEN r.ReceivingID IS NULL THEN 'In Transit'
                    WHEN r.ReceivedDate <= s.PromisedDate THEN 'Delivered On Time'
                    ELSE 'Delivered Late'
                END AS Status,
                COUNT(*) AS ShipmentCount
            $joins_status
            $where_clause_status
            GROUP BY Status
            ORDER BY ShipmentCount DESC";

        $result_status = mysqli_query($conn, $sql_status);

        $status_data = [];
        if($result_status) {
            while($row = mysqli_fetch_assoc($result_status)) {
                $status_data[] = $row;
            }
        }
        $response['shipment_status'] = $status_data;

        // =====================================================================
        // QUERY 4: PRODUCT HANDLING MIX
        // Multi-table JOIN to gather product information
        // =====================================================================

        $where_products = [];
        $joins_products = "FROM Shipping s 
            INNER JOIN Company c ON c.CompanyID = s.DistributorID
            INNER JOIN Product p ON p.ProductID = s.ProductID
            LEFT JOIN Receiving r ON r.ShipmentID = s.ShipmentID";

        if (!empty($region)) {
            $joins_products .= " INNER JOIN Company src ON src.CompanyID = s.SourceCompanyID 
                                 INNER JOIN Location l ON l.LocationID = src.LocationID";
            $where_products[] = "l.ContinentName = '$region'";
        }
        elseif (!empty($start_date) && !empty($end_date)) {
            $where_products[] = "s.PromisedDate BETWEEN '$start_date' AND '$end_date'";
        }

        if (!empty($source_company)) {
            $where_products[] = "s.SourceCompanyID = '$source_company'";
        }
        if (!empty($destination_company)) {
            $where_products[] = "s.DestinationCompanyID = '$destination_company'";
        }

        $where_clause_products = count($where_products) > 0 ? "WHERE " . implode(" AND ", $where_products) : "";

        // Multiple GROUP BY fields for detailed product breakdown
        $sql_products = "SELECT 
                c.CompanyName AS DistributorName,
                p.ProductID,
                p.ProductName,
                p.Category,
                COUNT(*) AS ShipmentCount,
                SUM(s.Quantity) AS TotalUnits,
                SUM(CASE WHEN r.ReceivingID IS NULL THEN 1 ELSE 0 END) AS InTransitCount
            $joins_products
            $where_clause_products
            GROUP BY c.CompanyName, p.ProductID, p.ProductName, p.Category
            ORDER BY ShipmentCount DESC
            LIMIT 20";

        $result_products = mysqli_query($conn, $sql_products);

        $products_data = [];
        if($result_products) {
            while($row = mysqli_fetch_assoc($result_products)) {
                $products_data[] = $row;
            }
        }
        $response['product_mix'] = $products_data;

        // =====================================================================
        // QUERY 5: DISRUPTION EXPOSURE
        // ALWAYS uses DATE RANGE (never region) - business logic requirement
        // Disruption events are time-based, not location-based
        // =====================================================================

        // Initialize default values
        $total = 0;
        $high = 0;
        $exposure = 0;

        // Only query if we have both dates
        if (!empty($start_date) && !empty($end_date)) {

            // Check destination company first, fall back to source company
            $company_to_check = !empty($destination_company) ? $destination_company : $source_company;

            if (!empty($company_to_check)) {

                // =====================================================================
                // CONDITIONAL SUM IN SQL
                // SUM(CASE WHEN condition THEN 1 ELSE 0 END) counts matching rows
                // =====================================================================
                $sql_disruption = "SELECT 
                        COUNT(*) AS TotalDisruptions,
                        SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighImpactEvents
                    FROM DisruptionEvent de
                    INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
                    WHERE ic.AffectedCompanyID = '$company_to_check'
                    AND de.EventDate BETWEEN '$start_date' AND '$end_date'";

                $result_disruption = mysqli_query($conn, $sql_disruption);

                if($result_disruption) {
                    $disruption_data = mysqli_fetch_assoc($result_disruption);

                    // intval() - converts to integer, handles NULL safely
                    // Source: https://www.php.net/manual/en/function.intval.php
                    $total = isset($disruption_data['TotalDisruptions']) ? intval($disruption_data['TotalDisruptions']) : 0;
                    $high = isset($disruption_data['HighImpactEvents']) ? intval($disruption_data['HighImpactEvents']) : 0;
                }

                // Calculate exposure score using business formula
                $exposure = $total + (2 * $high);
            }
        }

        // Build response array for disruption data
        $response['disruption_exposure'] = [
            'total_disruptions' => $total,
            'high_impact_events' => $high,
            'exposure_score' => $exposure
        ];
        // =====================================================================
        // QUERY 6: ALL TRANSACTIONS DETAIL
        // Complex query joining multiple transaction types
        // =====================================================================

        $where_all_trans = [];
        $joins_all_trans = "FROM InventoryTransaction it
            LEFT JOIN Shipping s ON s.TransactionID = it.TransactionID
            LEFT JOIN Receiving r ON r.TransactionID = it.TransactionID
            LEFT JOIN InventoryAdjustment a ON a.TransactionID = it.TransactionID
            LEFT JOIN Company cs ON cs.CompanyID = s.SourceCompanyID
            LEFT JOIN Company cd ON cd.CompanyID = s.DestinationCompanyID
            LEFT JOIN Company cr ON cr.CompanyID = r.ReceiverCompanyID
            LEFT JOIN Company ca ON ca.CompanyID = a.CompanyID
            LEFT JOIN Product ps ON ps.ProductID = s.ProductID
            LEFT JOIN Product pa ON pa.ProductID = a.ProductID";

        // Region filter with multiple location checks
        if (!empty($region)) {
            $joins_all_trans .= " LEFT JOIN Location ls ON ls.LocationID = cs.LocationID
                                  LEFT JOIN Location lr ON lr.LocationID = cr.LocationID
                                  LEFT JOIN Location la ON la.LocationID = ca.LocationID";

            // OR conditions to check all relevant location fields
            $where_all_trans[] = "(ls.ContinentName = '$region' 
                OR lr.ContinentName = '$region' 
                OR la.ContinentName = '$region')";
        }
        elseif (!empty($start_date) && !empty($end_date)) {

            // Check dates across all transaction types
            $where_all_trans[] = "(
                (s.PromisedDate BETWEEN '$start_date' AND '$end_date')
                OR (r.ReceivedDate BETWEEN '$start_date' AND '$end_date')
                OR (a.AdjustmentDate BETWEEN '$start_date' AND '$end_date')
            )";
        }

        // =====================================================================
        // COMPANY FILTER LOGIC
        // Different filters show different transaction types
        // Source company: Shows Shipping (as source) and Adjustments
        // Destination company: Shows Receiving and Adjustments
        // =====================================================================

        if (!empty($source_company)) {
            $where_all_trans[] = "(
                (it.Type = 'Shipping' AND s.SourceCompanyID = '$source_company')
                OR (it.Type = 'Adjustment' AND ca.CompanyID = '$source_company')
            )";
        }

        if (!empty($destination_company)) {
            $where_all_trans[] = "(
                (it.Type = 'Receiving' AND cr.CompanyID = '$destination_company')
                OR (it.Type = 'Adjustment' AND ca.CompanyID = '$destination_company')
            )";
        }

        $where_clause_all_trans = count($where_all_trans) > 0 
            ? "WHERE " . implode(" AND ", $where_all_trans)
            : "";

        // =====================================================================
        // LARGE SELECT WITH ALIASED COLUMNS
        // Using AS to create descriptive column names for frontend processing
        // =====================================================================

        $sql_all_trans = "SELECT 
                it.TransactionID,
                it.Type,

                s.ShipmentID AS Shipping_ShipmentID,
                cs.CompanyName AS Shipping_SourceCompany,
                cd.CompanyName AS Shipping_DestinationCompany,
                ps.ProductName AS Shipping_Product,
                s.PromisedDate AS Shipping_PromisedDate,
                s.ActualDate AS Shipping_ActualDate,
                s.Quantity AS Shipping_Quantity,

                r.ReceivingID AS Receiving_ReceivingID,
                r.ShipmentID AS Receiving_ShipmentID,
                cr.CompanyName AS Receiving_Company,
                r.ReceivedDate AS Receiving_ReceivedDate,
                r.QuantityReceived AS Receiving_Quantity,

                a.AdjustmentID AS Adjustment_AdjustmentID,
                ca.CompanyName AS Adjustment_Company,
                pa.ProductName AS Adjustment_Product,
                a.AdjustmentDate AS Adjustment_Date,
                a.QuantityChange AS Adjustment_QuantityChange,
                a.Reason AS Adjustment_Reason

            $joins_all_trans
            $where_clause_all_trans
            ORDER BY it.Type
            LIMIT 100";

        $result_all_trans = mysqli_query($conn, $sql_all_trans);

        $all_transactions_data = [];
        if($result_all_trans) {
            while($row = mysqli_fetch_assoc($result_all_trans)) {
                $all_transactions_data[] = $row;
            }
        }

        $response['all_transactions'] = $all_transactions_data;

        // =====================================================================
        // SEND COMPLETE JSON RESPONSE
        // All query results are combined into single JSON response
        // =====================================================================

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;

    // Invalid action parameter - error handling
    } else {

        ob_clean();
        header('Content-Type: application/json');
        die(json_encode(["error" => "Invalid request"]));
    }

    // =====================================================================
    // CLOSE DATABASE CONNECTION
    // mysqli_close() - from test.php line 71 and PHP lab
    // Important for resource management
    // =====================================================================

    mysqli_close($conn);

    // =====================================================================
    // RECOMMENDED IMPROVEMENTS (from PHP Lab problem 24)
    // =====================================================================

    // The current code uses mysqli_real_escape_string() for SQL injection
    // prevention. The PHP lab recommends using prepared statements instead:
    //
    // CURRENT METHOD:
    // $searchTerm = mysqli_real_escape_string($conn, $_GET['query']);
    // $sql = "SELECT ... WHERE CompanyName LIKE '%" . $searchTerm . "%'";
    //
    // RECOMMENDED METHOD (Prepared Statements):
    // $sql = "SELECT CompanyID, CompanyName FROM Company WHERE CompanyName LIKE ?";
    // $stmt = mysqli_prepare($conn, $sql);
    // $searchParam = "%{$searchTerm}%";
    // mysqli_stmt_bind_param($stmt, "s", $searchParam);
    // mysqli_stmt_execute($stmt);
    // $result = mysqli_stmt_get_result($stmt);
    //
    // Prepared statements provide better security by separating SQL logic
    // from data, preventing SQL injection even with malformed input.
    //
    // Source: PHP Lab (php_lab_sols1__1_.pdf problem 24)
    // Documentation: https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    // =====================================================================

} // END OF "if (isset($_GET['action']))"
?>
<!DOCTYPE html>
<html>
<head>
<title>Transactions Information</title>

<!-- Plotly library - from ajax_example.html line 6 -->
<script src="https://cdn.plot.ly/plotly-2.35.2.min.js" charset="utf-8"></script>

<link rel="stylesheet" href="css/dashboard.css?v=15">

</head>
<body>

<!-- Navigation Bar Structure - Inspired by HTML lab (html_lab_sols1.pdf problem 25.1) -->
<nav>
    <div class="nav-links">
      <a href="company.php">Company Information</a>
      <a href="disruptions.php">Disruption Events</a>
      <a href="transactions.php" class="active">Transactions</a>
       <button class="logout-btn" onclick="logout()">
        <img src="logout.png" alt="Log Out">
      <script>
        function logout() {
            let answer = confirm("Are you sure you want to log out?");
            if (answer) {
                window.location.href = "logout.php";
            }
        }
        </script>

    </div>

    <!-- Flexbox spacer - from CSS best practices -->
    <!-- Source: https://css-tricks.com/snippets/css/a-guide-to-flexbox/ -->
    <div style="flex-grow: 1;"></div>

    <!-- Filter controls: dates, region, and companies -->
    <div style="display: flex; align-items: center; gap: 6px;">

        <!-- Date input elements - from HTML lab (html_lab_sols1.pdf) -->
        <input type="date" id="transaction-start-date"
            style="width: 130px; height: 34px; padding: 6px 10px;
                   font-size: 13px; border: 1px solid #999; border-radius: 4px;">

        <input type="date" id="transaction-end-date"
            style="width: 130px; height: 34px; padding: 6px 10px;
                   font-size: 13px; border: 1px solid #999; border-radius: 4px;">

        <!-- Search box structure for dropdown results -->
        <div class="search-box-two" style="position: relative; width: 90px;">

            <!-- Input with autocomplete off - standard HTML practice -->
            <input type="text" id="region-search-input"
                placeholder="Region" autocomplete="off"
                style="height: 34px;">

            <div class="search-results" id="region-search-results"></div>
        </div>

        <!-- Source company search -->
        <div class="search-box-two" style="position: relative; width: 120px;">

            <input type="text" id="source-company-input"
                placeholder="Company (leaving)"
                autocomplete="off" style="height: 34px;">

            <div class="search-results" id="source-company-results"></div>
        </div>

        <!-- Destination company search -->
        <div class="search-box-two" style="position: relative; width: 120px;">

            <input type="text" id="destination-company-input"
                placeholder="Company (arriving)"
                autocomplete="off" style="height: 34px;">

            <div class="search-results" id="destination-company-results"></div>
        </div>

        <!-- Button onclick handler - from JS lab (js_lab_sols2__1_.pdf problem 9) -->
     <button class="btn"
      onclick="applyFilters()"
      style="height: 34px; padding: 0 4px;">
      Apply Filters
    </button>

    <button class="btn"
      onclick="clearFilters()"
      style="height: 34px; padding: 0 4px;">
      Clear Filters
    </button>

    </div>
</nav>
<!-- Main container using div layout - from HTML lab (html_lab_sols1.pdf Section 1.1) -->
<div class="container">

    <h1 class="page-title">Transaction Analysis Dashboard</h1>

    <!-- ALL TRANSACTIONS - Fixed height with scrolling -->
    <div class="card" id="all-transactions-card" 
     style="padding: 15px; margin-bottom: 15px; height: 200px;">

    <button class="zoom-btn" type="button">+</button>

    <h3 style="font-size: 14px; font-weight: 600;
               margin-bottom: 10px; color: #333;">
        All Transactions (Detailed View)
    </h3>
    

        <!-- Div for dynamic content population - innerHTML usage from JS lab problem 9 -->
        <div id="data-all-transactions"
            style="font-size: 10px; height: 280px; overflow-y: auto;">
            <p style="color: #999;">No data - Apply filters to see transactions</p>
        </div>

    </div>

    <!-- 5 DATA BOXES - CSS Grid layout -->
    <!-- Grid system - from HTML lab (html_lab_sols1.pdf problem 23) -->
    <div class="card"
        style="padding: 12px; margin-bottom: 15px; height: 210px; overflow: visible;">

        <div style="display: grid;
                    grid-template-columns: repeat(5, 1fr);
                    gap: 15px; height: 100%;">

            <!-- Each data box follows same pattern -->

            <div style="display: flex; flex-direction: column;">
                <h3 style="font-size: 12px; font-weight: 600;
                           margin-bottom: 6px; color: #333;">
                    Shipment Volume
                </h3>
                <!-- Independent scrolling containers - CSS overflow property -->
                <div id="data-shipment-volume"
                    style="font-size: 10px; height: 160px;
                           overflow-y: auto; overflow-x: hidden;">
                    <p style="color: #999;">No data</p>
                </div>
            </div>

            <div style="display: flex; flex-direction: column;">
                <h3 style="font-size: 12px; font-weight: 600;
                           margin-bottom: 6px; color: #333;">
                    On-Time Delivery Rate
                </h3>
                <div id="data-delivery-rate"
                    style="font-size: 10px; height: 160px;
                           overflow-y: auto; overflow-x: hidden;">
                    <p style="color: #999;">No data</p>
                </div>
            </div>

            <div style="display: flex; flex-direction: column;">
                <h3 style="font-size: 12px; font-weight: 600;
                           margin-bottom: 6px; color: #333;">
                    Shipment Status
                </h3>
                <div id="data-status"
                    style="font-size: 10px; height: 160px;
                           overflow-y: auto; overflow-x: hidden;">
                    <p style="color: #999;">No data</p>
                </div>
            </div>

            <div style="display: flex; flex-direction: column;">
                <h3 style="font-size: 12px; font-weight: 600;
                           margin-bottom: 6px; color: #333;">
                    Products Handled
                </h3>
                <div id="data-products"
                    style="font-size: 10px; height: 160px;
                           overflow-y: auto; overflow-x: hidden;">
                    <p style="color: #999;">No data</p>
                </div>
            </div>

            <div style="display: flex; flex-direction: column;">
                <h3 style="font-size: 12px; font-weight: 600;
                           margin-bottom: 6px; color: #333;">
                    Disruption Exposure
                </h3>
                <div id="data-disruption-exposure"
                    style="font-size: 10px; height: 160px;
                           overflow-y: auto; overflow-x: hidden;">

                    <p style="color: #999; font-size: 10px;">
                        Formula: Total + 2 x High Impact
                    </p>
                    <p style="color: #999; font-size: 10px;">
                        Select destination company and dates
                    </p>

                </div>
            </div>

        </div>

    </div>

    <!-- PLOTS - Grid layout for visualizations -->
    <!-- Grid system - from HTML lab (html_lab_sols1.pdf problem 23) -->
    <div style="display: grid;
                grid-template-columns: 1fr 1fr 1fr 1fr;
                gap: 12px; margin-top: 15px;">

        <div class="card" style="padding: 12px; height: 280px;">
            <button class="zoom-btn" type="button">+</button>
            <h3 class="card-title" style="font-size: 12px; margin-bottom: 6px;">
                Distributors By Volume
            </h3>
            <div id="plot-1" class="graph-container"
                style="border-radius: 4px;">
            </div>
        </div>


        <div class="card" style="padding: 12px; height: 280px;">
            <button class="zoom-btn" type="button">+</button>
            <h3 class="card-title" style="font-size: 12px; margin-bottom: 6px;">
                On-Time Delivery Rates
            </h3>
            <div id="plot-2" class="graph-container"
                style="border-radius: 4px;">
            </div>
        </div>

        <div class="card" style="padding: 12px; height: 280px;">
            <button class="zoom-btn" type="button">+</button>
            <h3 class="card-title" style="font-size: 12px; margin-bottom: 6px;">
                Shipment Status Distribution
            </h3>
            <div id="plot-3" class="graph-container"
                style="border-radius: 4px;">
            </div>
        </div>

        <div class="card" style="padding: 12px; height: 280px;">
            <button class="zoom-btn" type="button">+</button>
            <h3 class="card-title" style="font-size: 12px; margin-bottom: 6px;">
                Products By Volume
            </h3>
            <div id="plot-4" class="graph-container"
                style="border-radius: 4px;">
            </div>
        </div>

    </div>

</div>

<div id="box-overlay"></div>
<script>
    // =====================================================================
    // VARIABLE DECLARATIONS AND ELEMENT REFERENCES
    // getElementById() - from JS lab (js_lab_sols2__1_.pdf problem 9)
    // =====================================================================

    var regionSearchInput = document.getElementById("region-search-input");
    var regionSearchResults = document.getElementById("region-search-results");
    var sourceCompanyInput = document.getElementById("source-company-input");
    var sourceCompanyResults = document.getElementById("source-company-results");
    var destinationCompanyInput = document.getElementById("destination-company-input");
    var destinationCompanyResults = document.getElementById("destination-company-results");

    // Store currently selected values
    var currentSourceCompanyId = null;
    var currentDestinationCompanyId = null;
    var currentRegion = null;

    // =====================================================================
    // REGION SEARCH FUNCTIONALITY
    // =====================================================================

    // addEventListener pattern - from JS lab (js_lab_sols2__1_.pdf problem 10)
    regionSearchInput.addEventListener("focus", function() {
        loadAllRegions();
    });

    // Input event listener for dynamic search
    regionSearchInput.addEventListener("input", function() {
        // trim() - from JS lab (js_lab_sols2__1_.pdf problem 13)
        // Source: https://www.w3schools.com/jsref/jsref_trim_string.asp
        var searchTerm = regionSearchInput.value.trim();
        if(searchTerm.length < 1) {
            loadAllRegions();
            return;
        }

        // =====================================================================
        // AJAX REQUEST PATTERN
        // XMLHttpRequest - from ajax_example.html lines 43-77
        // This is the core AJAX pattern taught in the lab materials
        // =====================================================================

        var xhttp = new XMLHttpRequest();

        // onload callback - from ajax_example.html line 46
        xhttp.onload = function() {
            // readyState and status check - from ajax_example.html line 50
            if (this.readyState == 4 && this.status == 200) {
                try {
                    // JSON.parse() - from ajax_example.html line 54
                    var data = JSON.parse(this.responseText);
                    displayRegions(data);
                } catch(e) {
                    console.error("Parse error:", e);
                }
            }
        };

        // encodeURIComponent for URL safety
        // Source: https://www.w3schools.com/jsref/jsref_encodeuricomponent.asp
        xhttp.open("GET", "transactions.php?action=region_search&query=" + encodeURIComponent(searchTerm), true);
        xhttp.send();
    });

    // Load all regions function
    function loadAllRegions() {
        // Same AJAX pattern as above
        var xhttp = new XMLHttpRequest();
        xhttp.onload = function() {
            if (this.readyState == 4 && this.status == 200) {
                try {
                    displayRegions(JSON.parse(this.responseText));
                } catch(e) {
                    console.error("Parse error:", e);
                }
            }
        };
        xhttp.open("GET", "transactions.php?action=region_search&query=", true);
        xhttp.send();
    }

    // Display regions in dropdown
    function displayRegions(data) {
        // innerHTML manipulation - from JS lab (js_lab_sols2__1_.pdf problem 9)
        regionSearchResults.innerHTML = "";

        if(data.error || data.length == 0) {
            regionSearchResults.innerHTML = '<div class="no-results">No regions found</div>';
            // classList.add() - from JS lab
            // Source: https://www.w3schools.com/jsref/prop_element_classlist.asp
            regionSearchResults.classList.add("show");
            return;
        }

        // Loop through results
        for(var i = 0; i < data.length; i++) {
            // createElement - from JS lab (js_lab_sols2__1_.pdf problem 12)
            var item = document.createElement("div");
            item.className = "search-result-item";
            // textContent - standard DOM property
            item.textContent = data[i].ContinentName;

            // onclick event handler - from JS lab (js_lab_sols2__1_.pdf problem 9)
            item.onclick = function() {
                currentRegion = this.textContent;
                regionSearchInput.value = currentRegion;
                regionSearchResults.classList.remove("show");
            };

            // appendChild - from JS lab (js_lab_sols2__1_.pdf problem 12)
            regionSearchResults.appendChild(item);
        }

        regionSearchResults.classList.add("show");
    }

    // =====================================================================
    // SOURCE COMPANY SEARCH (where shipment is leaving from)
    // Same AJAX and DOM manipulation patterns as region search
    // =====================================================================

    sourceCompanyInput.addEventListener("input", function() {
        var searchTerm = sourceCompanyInput.value.trim();
        if(searchTerm.length < 1) {
            sourceCompanyResults.classList.remove("show");
            currentSourceCompanyId = null;
            return;
        }

        // AJAX call - same pattern as above
        var xhttp = new XMLHttpRequest();
        xhttp.onload = function() {
            if (this.readyState == 4 && this.status == 200) {
                try {
                    var data = JSON.parse(this.responseText);
                    sourceCompanyResults.innerHTML = "";

                    if(data.error || data.length == 0) {
                        sourceCompanyResults.innerHTML = '<div class="no-results">No companies found</div>';
                        sourceCompanyResults.classList.add("show");
                        return;
                    }

                    for(var i = 0; i < data.length; i++) {
                        var item = document.createElement("div");
                        item.className = "search-result-item";
                        item.textContent = data[i].CompanyName;

                        // setAttribute for storing data attributes
                        // Source: https://www.w3schools.com/jsref/met_element_setattribute.asp
                        item.setAttribute("data-id", data[i].CompanyID);

                        item.onclick = function() {
                            // getAttribute retrieves stored data
                            // Source: https://www.w3schools.com/jsref/met_element_getattribute.asp
                            currentSourceCompanyId = this.getAttribute("data-id");
                            sourceCompanyInput.value = this.textContent;
                            sourceCompanyResults.classList.remove("show");
                        };

                        sourceCompanyResults.appendChild(item);
                    }

                    sourceCompanyResults.classList.add("show");
                } catch(e) {
                    console.error("Parse error:", e);
                }
            }
        };

        xhttp.open("GET", "transactions.php?action=company_search&query=" + encodeURIComponent(searchTerm), true);
        xhttp.send();
    });

    // =====================================================================
    // DESTINATION COMPANY SEARCH (where shipment is going to)
    // Identical pattern to source company search
    // =====================================================================

    destinationCompanyInput.addEventListener("input", function() {
        var searchTerm = destinationCompanyInput.value.trim();
        if(searchTerm.length < 1) {
            destinationCompanyResults.classList.remove("show");
            currentDestinationCompanyId = null;
            return;
        }

        var xhttp = new XMLHttpRequest();
        xhttp.onload = function() {
            if (this.readyState == 4 && this.status == 200) {
                try {
                    var data = JSON.parse(this.responseText);
                    destinationCompanyResults.innerHTML = "";

                    if(data.error || data.length == 0) {
                        destinationCompanyResults.innerHTML = '<div class="no-results">No companies found</div>';
                        destinationCompanyResults.classList.add("show");
                        return;
                    }

                    for(var i = 0; i < data.length; i++) {
                        var item = document.createElement("div");
                        item.className = "search-result-item";
                        item.textContent = data[i].CompanyName;
                        item.setAttribute("data-id", data[i].CompanyID);

                        item.onclick = function() {
                            currentDestinationCompanyId = this.getAttribute("data-id");
                            destinationCompanyInput.value = this.textContent;
                            destinationCompanyResults.classList.remove("show");
                        };

                        destinationCompanyResults.appendChild(item);
                    }

                    destinationCompanyResults.classList.add("show");
                } catch(e) {
                    console.error("Parse error:", e);
                }
            }
        };

        xhttp.open("GET", "transactions.php?action=company_search&query=" + encodeURIComponent(searchTerm), true);
        xhttp.send();
    });

    // =====================================================================
    // CLICK OUTSIDE TO CLOSE DROPDOWNS
    // Event delegation pattern
    // =====================================================================

    // Document-level click listener
    document.addEventListener("click", function(event) {
        // closest() method for event delegation
        // Source: https://developer.mozilla.org/en-US/docs/Web/API/Element/closest
        if (!event.target.closest("#region-search-input") &&
            !event.target.closest("#region-search-results")) {
            regionSearchResults.classList.remove("show");
        }

        if (!event.target.closest("#source-company-input") &&
            !event.target.closest("#source-company-results")) {
            sourceCompanyResults.classList.remove("show");
        }

        if (!event.target.closest("#destination-company-input") &&
            !event.target.closest("#destination-company-results")) {
            destinationCompanyResults.classList.remove("show");
        }
    });

    // =====================================================================
    // LOAD TRANSACTION DATA
    // Main data loading function - AJAX pattern from ajax_example.html
    // =====================================================================

    function loadTransactionData() {
        // Get date values - getElementById from JS lab
        var startDate = document.getElementById("transaction-start-date").value;
        var endDate = document.getElementById("transaction-end-date").value;

        // Check what filters are selected
        var hasDateRange = (startDate && endDate);
        var hasRegion = (currentRegion !== null);
        var hasSourceCompany = (currentSourceCompanyId !== null);
        var hasDestinationCompany = (currentDestinationCompanyId !== null);

        // Validation logic - alert() from JS lab (js_lab_sols2__1_.pdf problem 11)
        if (hasSourceCompany && hasDestinationCompany) {
            alert("ERROR: Please select EITHER Company (leaving) OR Company (arriving), not both!");
            return;
        }

        if (!hasDateRange && !hasRegion) {
            alert("Please select one or both:\n• Date Range (both dates)\n• Region");
            return;
        }

        if (!hasSourceCompany && !hasDestinationCompany) {
            alert("Please select one:\n• Company (leaving)\n• Company (arriving)");
            return;
        }

        // Build query string - similar to ajax_example.html line 76
        var params = "action=get_transactions";

        if(hasDateRange) {
            params += "&start_date=" + encodeURIComponent(startDate);
            params += "&end_date=" + encodeURIComponent(endDate);
        }

        if(hasRegion) {
            params += "&region=" + encodeURIComponent(currentRegion);
        }

        if(hasSourceCompany) {
            params += "&source_company=" + encodeURIComponent(currentSourceCompanyId);
        }

        if(hasDestinationCompany) {
            params += "&destination_company=" + encodeURIComponent(currentDestinationCompanyId);
        }

        // AJAX request - from ajax_example.html
        var xhttp = new XMLHttpRequest();
        xhttp.onload = function() {
            if (this.readyState == 4 && this.status == 200) {
                try {
                    var data = JSON.parse(this.responseText);
                    console.log("Data loaded:", data);
                    displayData(data);
                } catch(e) {
                    console.error("Parse error:", e);
                    alert("Error loading data");
                }
            }
        };

        xhttp.open("GET", "transactions.php?" + params, true);
        xhttp.send();
    }

    // =====================================================================
    // GET FILTER DESCRIPTION
    // Helper function to build user-friendly filter description
    // =====================================================================

    function getFilterDescription() {
        var startDate = document.getElementById("transaction-start-date").value;
        var endDate = document.getElementById("transaction-end-date").value;

        var hasDateRange = (startDate && endDate);
        var hasRegion = (currentRegion !== null);

        // Get company name from input field
        var companyName = "";
        if (currentSourceCompanyId) {
            companyName = document.getElementById("source-company-input").value;
        } else if (currentDestinationCompanyId) {
            companyName = document.getElementById("destination-company-input").value;
        }

        // Build description string
        var description = "";
        if (hasRegion) {
            description = "Over region " + currentRegion + " and company " + companyName;
        } else if (hasDateRange) {
            description = "From " + startDate + " to " + endDate + " and company " + companyName;
        }

        return description;
    }

    // =====================================================================
    // DISPLAY DATA
    // Takes JSON response and displays it in the page
    // innerHTML manipulation - from JS lab (js_lab_sols2__1_.pdf problem 9)
    // =====================================================================

    function displayData(data) {
        // Store data globally for plot access
        window.transactionData = data;

        var filterDesc = getFilterDescription();

        // =====================================================================
        // 1. SHIPMENT VOLUME TABLE
        // HTML table building - from JS lab and HTML lab (html_lab_sols1.pdf problem 12)
        // =====================================================================

        if(data.shipment_volume && data.shipment_volume.length > 0) {
            var html = '<p style="font-size: 10px; color: #666; margin-bottom: 5px; font-style: italic;">' +
                filterDesc + '</p>';
            html += '<table style="width:100%; border-collapse: collapse;">';
            html += '<tr style="background:#f5f5f5;">' +
                '<th style="padding:4px; text-align:left; font-size:10px;">Distributor</th>' +
                '<th style="padding:4px; text-align:right; font-size:10px;">Qty</th>' +
                '</tr>';

            // Loop through data array
            for(var i = 0; i < data.shipment_volume.length; i++) {
                html += '<tr>' +
                    '<td style="padding:4px; border-top:1px solid #eee;">' +
                    data.shipment_volume[i].DistributorName + '</td>';
                html += '<td style="padding:4px; border-top:1px solid #eee; text-align:right;">' +
                    data.shipment_volume[i].TotalQty + '</td></tr>';
            }

            html += '</table>';
            document.getElementById('data-shipment-volume').innerHTML = html;
        } else {
            document.getElementById('data-shipment-volume').innerHTML =
                '<p style="color: #999;">No data</p>';
        }

        // =====================================================================
        // 2. ON-TIME DELIVERY RATE
        // parseFloat and toFixed - from JS lab (js_lab_sols2__1_.pdf problem 1-2)
        // Source: https://www.w3schools.com/jsref/jsref_parsefloat.asp
        // =====================================================================

        if(data.delivery_rate && data.delivery_rate.length > 0) {
            var html = '<p style="font-size: 10px; color: #666; margin-bottom: 5px; font-style: italic;">' +
                filterDesc + '</p>';
            html += '<table style="width:100%; border-collapse: collapse;">';
            html += '<tr style="background:#f5f5f5;">' +
                '<th style="padding:4px; text-align:left; font-size:10px;">Distributor</th>' +
                '<th style="padding:4px; text-align:right; font-size:10px;">Rate</th>' +
                '</tr>';

            for(var i = 0; i < data.delivery_rate.length; i++) {
                var rate = parseFloat(data.delivery_rate[i].DeliveryRate).toFixed(1);
                html += '<tr>' +
                    '<td style="padding:4px; border-top:1px solid #eee;">' +
                    data.delivery_rate[i].DistributorName + '</td>';
                html += '<td style="padding:4px; border-top:1px solid #eee; text-align:right;">' +
                    rate + '%</td></tr>';
            }

            html += '</table>';
            document.getElementById('data-delivery-rate').innerHTML = html;
        } else {
            document.getElementById('data-delivery-rate').innerHTML =
                '<p style="color: #999;">No data</p>';
        }

        // =====================================================================
        // 3. SHIPMENT STATUS
        // Same table building pattern
        // =====================================================================

        if(data.shipment_status && data.shipment_status.length > 0) {
            var html = '<p style="font-size: 10px; color: #666; margin-bottom: 5px; font-style: italic;">' +
                filterDesc + '</p>';
            html += '<table style="width:100%; border-collapse: collapse;">';
            html += '<tr style="background:#f5f5f5;">' +
                '<th style="padding:4px; text-align:left; font-size:10px;">Status</th>' +
                '<th style="padding:4px; text-align:right; font-size:10px;">Count</th>' +
                '</tr>';

            for(var i = 0; i < data.shipment_status.length; i++) {
                html += '<tr>' +
                    '<td style="padding:4px; border-top:1px solid #eee;">' +
                    data.shipment_status[i].Status + '</td>';
                html += '<td style="padding:4px; border-top:1px solid #eee; text-align:right;">' +
                    data.shipment_status[i].ShipmentCount + '</td></tr>';
            }

            html += '</table>';
            document.getElementById('data-status').innerHTML = html;
        } else {
            document.getElementById('data-status').innerHTML =
                '<p style="color: #999;">No data</p>';
        }

        // =====================================================================
        // 4. PRODUCTS HANDLED
        // Math.min() - from JS lab
        // Source: https://www.w3schools.com/jsref/jsref_min.asp
        // =====================================================================

        if(data.product_mix && data.product_mix.length > 0) {
            var html = '<p style="font-size: 10px; color: #666; margin-bottom: 5px; font-style: italic;">' +
                filterDesc + '</p>';
            html += '<table style="width:100%; border-collapse: collapse;">';
            html += '<tr style="background:#f5f5f5;">' +
                '<th style="padding:4px; text-align:left; font-size:10px;">Product</th>' +
                '<th style="padding:4px; font-size:10px;">Category</th>' +
                '<th style="padding:4px; text-align:right; font-size:10px;">Units</th>' +
                '</tr>';

            // Show top 10 products only
            for(var i = 0; i < Math.min(10, data.product_mix.length); i++) {
                html += '<tr>' +
                    '<td style="padding:4px; border-top:1px solid #eee;">' +
                    data.product_mix[i].ProductName + '</td>';
                html += '<td style="padding:4px; border-top:1px solid #eee;">' +
                    data.product_mix[i].Category + '</td>';
                html += '<td style="padding:4px; border-top:1px solid #eee; text-align:right;">' +
                    data.product_mix[i].TotalUnits + '</td></tr>';
            }

            html += '</table>';
            document.getElementById('data-products').innerHTML = html;
        } else {
            document.getElementById('data-products').innerHTML =
                '<p style="color: #999;">No data</p>';
        }

        // =====================================================================
        // 5. DISRUPTION EXPOSURE
        // Only works with date ranges (business logic requirement)
        // =====================================================================

        var startDate = document.getElementById("transaction-start-date").value;
        var endDate = document.getElementById("transaction-end-date").value;
        var hasDateRange = (startDate && endDate);

        if(!hasDateRange) {
            document.getElementById('data-disruption-exposure').innerHTML =
                '<p style="color: #e67e22; font-size: 11px; font-weight: bold;">Please input a date range</p>';
        } else if(data.disruption_exposure) {
            var companyName = "";
            if (currentSourceCompanyId) {
                companyName = document.getElementById("source-company-input").value;
            } else if (currentDestinationCompanyId) {
                companyName = document.getElementById("destination-company-input").value;
            }

            var html = '<p style="font-size: 10px; color: #666; margin-bottom: 5px; font-style: italic;">From ' +
                startDate + ' to ' + endDate + ' and company ' + companyName + '</p>';
            html += '<div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 5px;">';
            html += '<div style="display: flex; gap: 15px; font-size: 11px;">';
            html += '<div><strong>Total:</strong> ' +
                '<span style="font-size: 16px; color: #3498db; font-weight: bold;">' +
                data.disruption_exposure.total_disruptions + '</span></div>';
            html += '<div><strong>High Impact:</strong> ' +
                '<span style="font-size: 16px; color: #e67e22; font-weight: bold;">' +
                data.disruption_exposure.high_impact_events + '</span></div>';
            html += '<div><strong>Score:</strong> ' +
                '<span style="font-size: 18px; color: #e74c3c; font-weight: bold;">' +
                data.disruption_exposure.exposure_score + '</span></div>';
            html += '</div>';
            html += '<p style="font-size: 10px; color: #666; margin-top: 5px;">' +
                data.disruption_exposure.total_disruptions + ' + (2 x ' +
                data.disruption_exposure.high_impact_events + ') = ' +
                data.disruption_exposure.exposure_score + '</p>';
            html += '</div>';

            document.getElementById('data-disruption-exposure').innerHTML = html;
        }

        // =====================================================================
        // 6. ALL TRANSACTIONS DETAIL
        // Complex HTML building with conditional logic
        // =====================================================================

        if(data.all_transactions && data.all_transactions.length > 0) {
            var html = '<p style="font-size: 10px; color: #666; margin-bottom: 8px; font-style: italic;">' +
                filterDesc + ' (showing up to 100 transactions)</p>';
            html += '<table style="width:100%; border-collapse: collapse; font-size: 10px;">';
            html += '<tr style="background:#f5f5f5;">' +
                '<th style="padding:4px; text-align:left;">Type</th>' +
                '<th style="padding:4px; text-align:left;">Details</th>' +
                '</tr>';

            for(var i = 0; i < data.all_transactions.length; i++) {
                var tx = data.all_transactions[i];
                var details = '';

                // Conditional formatting based on transaction type
                if(tx.Type === 'Shipping') {
                    details = '<strong>Shipment #' + tx.Shipping_ShipmentID + '</strong><br>';
                    details += 'From: ' + tx.Shipping_SourceCompany +
                        ' -> To: ' + tx.Shipping_DestinationCompany + '<br>';
                    details += 'Product: ' + tx.Shipping_Product +
                        ' (Qty: ' + tx.Shipping_Quantity + ')<br>';
                    details += 'Promised: ' + tx.Shipping_PromisedDate;
                    if(tx.Shipping_ActualDate)
                        details += ' | Actual: ' + tx.Shipping_ActualDate;

                } else if(tx.Type === 'Receiving') {
                    details = '<strong>Receipt #' + tx.Receiving_ReceivingID + '</strong> ' +
                        '(Shipment #' + tx.Receiving_ShipmentID + ')<br>';
                    details += 'Company: ' + tx.Receiving_Company + '<br>';
                    details += 'Received: ' + tx.Receiving_ReceivedDate +
                        ' (Qty: ' + tx.Receiving_Quantity + ')';

                } else if(tx.Type === 'Adjustment') {
                    details = '<strong>Adjustment #' + tx.Adjustment_AdjustmentID + '</strong><br>';
                    details += 'Company: ' + tx.Adjustment_Company + '<br>';
                    details += 'Product: ' + tx.Adjustment_Product + '<br>';
                    details += 'Date: ' + tx.Adjustment_Date +
                        ' | Change: ' + tx.Adjustment_QuantityChange + '<br>';
                    details += 'Reason: ' + tx.Adjustment_Reason;
                }

                html += '<tr>' +
                    '<td style="padding:6px; border-top:1px solid #eee; vertical-align:top; width:80px;">' +
                    tx.Type + '</td>';
                html += '<td style="padding:6px; border-top:1px solid #eee;">' +
                    details + '</td></tr>';
            }

            html += '</table>';
            document.getElementById('data-all-transactions').innerHTML = html;
        } else {
            document.getElementById('data-all-transactions').innerHTML =
                '<p style="color: #999;">No transactions found with current filters</p>';
        }

        // =====================================================================
        // PLOTLY VISUALIZATIONS
        // Plotly.newPlot() - from ajax_example.html lines 65-71
        // Documentation: https://plotly.com/javascript/
        // =====================================================================

        // =====================================================================
        // PLOT 1: Shipment Volume by Distributor (Bar Chart)
        // =====================================================================

        if(data.shipment_volume && data.shipment_volume.length > 0) {
            // Array map() method - ES6 JavaScript feature
            // Used to extract specific properties from array of objects
            var distributorNames = data.shipment_volume.map(function(item) {
                return item.DistributorName;
            });
            var quantities = data.shipment_volume.map(function(item) {
                return item.TotalQty;
            });

            // Plotly trace object - from ajax_example.html line 66
            var trace1 = {
                x: distributorNames.slice(0, 10), // Top 10 only
                y: quantities.slice(0, 10),
                type: 'bar',
                marker: { color: '#4a90e2' }
            };

            // =====================================================================
            // Plotly layout with optimized fonts and margins
            // tickfont size: 6px for compact display
            // Bottom margin increased to 90px for angled labels
            // automargin: true allows Plotly to auto-adjust for labels
            // Source: https://plotly.com/javascript/axes/
            // Source: https://plotly.com/javascript/reference/layout/xaxis/#layout-xaxis-automargin
            // =====================================================================

            var layout1 = {
                autosize: true,
                xaxis: {
                    tickangle: -45,
                    tickfont: { size: 6 },
                    automargin: true
                },
                yaxis: {
                    title: 'Quantity',
                    titlefont: { size: 9 },
                    automargin: true
                },
                margin: { t: 35, r: 15, b: 90, l: 45 }
            };

            // Plotly.newPlot() - from ajax_example.html line 65
Plotly.newPlot('plot-1', [trace1], layout1, {
    displayModeBar: false,
    responsive: true
});

        } else {
            Plotly.purge('plot-1');
        }

        // =====================================================================
        // PLOT 2: On-Time Delivery Rate (Horizontal Bar Chart)
        // =====================================================================

        if(data.delivery_rate && data.delivery_rate.length > 0) {
            var distributorNames2 = data.delivery_rate.map(function(item) {
                return item.DistributorName;
            });
            var rates = data.delivery_rate.map(function(item) {
                return parseFloat(item.DeliveryRate).toFixed(1);
            });

            // Horizontal bar chart - orientation: 'h'
            var trace2 = {
                y: distributorNames2.slice(0, 10).reverse(),
                x: rates.slice(0, 10).reverse(),
                type: 'bar',
                orientation: 'h',
                marker: { color: '#22c55e' }
            };

            // =====================================================================
            // Layout optimized for horizontal bars
            // yaxis tickfont: 6px for distributor names
            // Left margin: 160px to accommodate longer names
            // automargin: true for automatic label space adjustment
            // =====================================================================

            var layout2 = {
                autosize: true,
                xaxis: {
                    title: 'Delivery Rate (%)',
                    titlefont: { size: 9 },
                    tickfont: { size: 7 },
                    automargin: true
                },
                yaxis: {
                    tickfont: { size: 6 },
                    automargin: true
                },
                margin: { t: 35, r: 15, b: 35, l: 60 }
            };

Plotly.newPlot('plot-2', [trace2], layout2, {
    displayModeBar: false,
    responsive: true
});

        } else {
            Plotly.purge('plot-2');
        }

        // =====================================================================
        // PLOT 3: Shipment Status Distribution (Pie Chart)
        // =====================================================================

        if(data.shipment_status && data.shipment_status.length > 0) {
            var statuses = data.shipment_status.map(function(item) {
                return item.Status;
            });
            var counts = data.shipment_status.map(function(item) {
                return item.ShipmentCount;
            });

            // Pie chart type
            var trace3 = {
                labels: statuses,
                values: counts,
                type: 'pie',
                marker: {
                    colors: ['#fbbf24', '#22c55e', '#ef4444']
                },
                // =====================================================================
                // textfont for pie chart labels - optimized to 7px
                // textposition: 'inside' ensures labels don't overflow
                // Source: https://plotly.com/javascript/pie-charts/
                // =====================================================================
                textfont: { size: 7 },
                textposition: 'inside'
            };

            var layout3 = {
                autosize: true,   // NEW
                margin: { t: 35, r: 15, b: 15, l: 15 },
                showlegend: true,
                legend: {
                    orientation: 'h',
                    y: -0.15,
                    x: 0.5,
                    xanchor: 'center',
                    font: { size: 7 }
                }
            };

Plotly.newPlot('plot-3', [trace3], layout3, {
    displayModeBar: false,
    responsive: true
});

        } else {
            Plotly.purge('plot-3');
        }

        // =====================================================================
        // PLOT 4: Top 10 Products by Volume (Bar Chart)
        // =====================================================================

        if(data.product_mix && data.product_mix.length > 0) {
            var productNames = data.product_mix.map(function(item) {
                return item.ProductName;
            });
            var productUnits = data.product_mix.map(function(item) {
                return item.TotalUnits;
            });

            var trace4 = {
                x: productNames.slice(0, 10),
                y: productUnits.slice(0, 10),
                type: 'bar',
                marker: { color: '#8b5cf6' }
            };

            // Smaller fonts and increased margin for product names
            // automargin helps with long product names
            var layout4 = {
                autosize: true,   // NEW
                xaxis: {
                    tickangle: -45,
                    tickfont: { size: 6 },
                    automargin: true
                },
                yaxis: {
                    title: 'Units',
                    titlefont: { size: 9 },
                    automargin: true
                },
                margin: { t: 35, r: 15, b: 90, l: 45 }
            };

Plotly.newPlot('plot-4', [trace4], layout4, {
    displayModeBar: false,
    responsive: true
});

        } else {
            Plotly.purge('plot-4');
        }

        console.log("✅ All data displayed!");
        console.log("📊 Data available for plots:", data);
    }

    // =====================================================================
    // APPLY FILTERS - Button handler
    // Simple wrapper function for loadTransactionData()
    // =====================================================================

    function applyFilters() {
        loadTransactionData();
    }

    // =====================================================================
    // CLEAR FILTERS - Reset all inputs and displays
    // =====================================================================

    function clearFilters() {
        // Clear date inputs
        document.getElementById("transaction-start-date").value = "";
        document.getElementById("transaction-end-date").value = "";

        // Clear search inputs
        regionSearchInput.value = "";
        sourceCompanyInput.value = "";
        destinationCompanyInput.value = "";

        // Reset stored values
        currentRegion = null;
        currentSourceCompanyId = null;
        currentDestinationCompanyId = null;

        // Hide dropdowns
        regionSearchResults.classList.remove("show");
        sourceCompanyResults.classList.remove("show");
        destinationCompanyResults.classList.remove("show");

        // Clear all data displays - innerHTML from JS lab
        document.getElementById('data-shipment-volume').innerHTML =
            '<p style="color: #999;">No data</p>';
        document.getElementById('data-delivery-rate').innerHTML =
            '<p style="color: #999;">No data</p>';
        document.getElementById('data-status').innerHTML =
            '<p style="color: #999;">No data</p>';
        document.getElementById('data-products').innerHTML =
            '<p style="color: #999;">No data</p>';
        document.getElementById('data-disruption-exposure').innerHTML =
            '<p style="color: #999; font-size: 10px;">Formula: Total + 2 x High Impact</p>' +
            '<p style="color: #999; font-size: 10px;">Select destination company and dates</p>';
        document.getElementById('data-all-transactions').innerHTML =
            '<p style="color: #999;">No data - Apply filters to see transactions</p>';

        // =====================================================================
        // CLEAR ALL PLOTS
        // Plotly.purge() - completely removes plot and frees memory
        // Source: https://plotly.com/javascript/plotlyjs-function-reference/#plotlypurge
        // =====================================================================

        Plotly.purge('plot-1');
        Plotly.purge('plot-2');
        Plotly.purge('plot-3');
        Plotly.purge('plot-4');

        console.log("✅ Filters cleared!");
    }

    // Ask server for the role
    // Toggle Senior Module tab visibility based on role
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


    function resizeAllPlots() {
    const graphs = document.querySelectorAll('.graph-container');
    graphs.forEach(function (graph) {
        try {
            Plotly.Plots.resize(graph);
        } catch (e) {
            console.warn('Plot resize failed for', graph.id, e);
        }
    });
}
// =====================================================================
// CARD ZOOM FOR TRANSACTIONS PLOTS
// Uses .card, .zoom-btn, #box-overlay, .card.expanded styles from CSS
// =====================================================================

document.addEventListener('DOMContentLoaded', function () {

    // ====== SET DEFAULT DATE FILTERS TO YTD ON LOAD ======
    (function setYTDDefaults() {
        var startInput = document.getElementById("transaction-start-date");
        var endInput = document.getElementById("transaction-end-date");
        if (!startInput || !endInput) return;

        var today = new Date();
        var yearStart = new Date(2022, 0, 1);

        function fmt(d) {
            return d.toISOString().slice(0, 10);
        }

        startInput.value = fmt(yearStart);
        endInput.value = fmt(today);

        window._ytdStart = fmt(yearStart);
        window._ytdEnd = fmt(today);
    })();

    // ✅ AUTO-LOAD ABBOTT-MUNOZ IN COMPANY (LEAVING) ON PAGE LOAD
    (function autoLoadAbbottMunoz() {
        var xhttp = new XMLHttpRequest();
        xhttp.onload = function() {
            if (this.readyState == 4 && this.status == 200) {
                try {
                    var data = JSON.parse(this.responseText);
                    if (data && data.length > 0) {
                        // Find Abbott-Munoz in results
                        var abbottMunoz = data.find(function(company) {
                            return company.CompanyName === 'Abbott-Munoz';
                        });
                        
                        if (abbottMunoz) {
                            // Set the source company search box
                            sourceCompanyInput.value = 'Abbott-Munoz';
                            // Store the company ID
                            currentSourceCompanyId = abbottMunoz.CompanyID;
                            
                            console.log('✅ Abbott-Munoz auto-loaded:', abbottMunoz.CompanyID);
                            
                            // Optional: Auto-load data since dates are already set
                            var startDate = document.getElementById("transaction-start-date").value;
                            var endDate = document.getElementById("transaction-end-date").value;
                            if (startDate && endDate) {
                                loadTransactionData();
                            }
                        }
                    }
                } catch(e) {
                    console.error('Error auto-loading Abbott-Munoz:', e);
                }
            }
        };
        xhttp.open("GET", "transactions.php?action=company_search&query=Abbott-Munoz", true);
        xhttp.send();
    })();

    const overlay = document.getElementById('box-overlay');
    if (!overlay) {
        console.error('box-overlay not found - zoom will not work');
        return;
    }

    const zoomButtons = document.querySelectorAll('.card .zoom-btn');

    zoomButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const card = this.closest('.card');
            if (!card) return;

            const isExpanded = card.classList.toggle('expanded');

            // Toggle + / - icon
            this.textContent = isExpanded ? '-' : '+';

            // ===== SPECIAL LOGIC ONLY for All Transactions =====
            if (card.id === "all-transactions-card") {
                const container = document.getElementById("data-all-transactions");

                if (isExpanded) {
                    // Let the content dynamically expand to fill the entire fullscreen modal
                    container.style.height = (window.innerHeight * 0.90 - 120) + "px";
                    container.style.overflowY = "auto";
                } else {
                    // Restore original (non-expanded) size
                    container.style.height = "280px";
                    container.style.overflowY = "auto";
                }
            }
            // ====================================================

            // Show / hide overlay
            if (isExpanded) {
                overlay.classList.add('show');
            } else {
                overlay.classList.remove('show');
            }

            // Resize ALL plots after animation
            setTimeout(resizeAllPlots, 250);
        });
    });

    // Clicking the overlay collapses any expanded card
    overlay.addEventListener('click', function () {
        const expandedCard = document.querySelector('.card.expanded');
        if (!expandedCard) return;

        expandedCard.classList.remove('expanded');
        const btn = expandedCard.querySelector('.zoom-btn');
        if (btn) btn.textContent = '+';
        overlay.classList.remove('show');

        // ✅ also resize ALL plots when closing via overlay
        setTimeout(resizeAllPlots, 250);
    });

}); // END OF DOMContentLoaded

</script>
</body>
</html>