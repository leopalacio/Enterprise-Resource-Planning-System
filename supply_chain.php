<?php
// Enable error reporting to help debug issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection credentials
$servername = "mydb.itap.purdue.edu";
$username = "g1151928";  // YOUR USERNAME
$password = "JuK3J593";  // YOUR PASSWORD
$database = "g1151928";

// Create connection to MySQL database
$conn = new mysqli($servername, $username, $password, $database);

// Check if connection was successful
if($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

// ============================================================================
// HANDLE SEARCH REQUEST (when user types in search box)
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
// HANDLE TRANSACTION DATA REQUEST (when user selects a company or filters by date)
// ============================================================================
} elseif(isset($_GET['action']) && $_GET['action'] == 'transactions') {
    
    // Get the company ID from the URL
    $company_id = mysqli_real_escape_string($conn, $_GET['company']);
    
    // Get date range if provided (optional - user may or may not filter by date)
    $start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
    
    // ========================================================================
    // TODO: ADD YOUR TRANSACTION QUERY HERE
    // ========================================================================
    // This is where you'll add your SQL query for transactions
    // 
    // Your query should:
    // 1. Get all transactions for the company with ID = $company_id
    // 2. If $start_date and $end_date are provided, filter by those dates
    // 3. Return separate arrays for shipping, receiving, and adjustments
    //
    // Example structure of what you need:
    /*
    $sql = "SELECT it.TransactionID, it.Type, 
            [your columns here]
            FROM InventoryTransaction it 
            LEFT JOIN Shipping s ON s.TransactionID = it.TransactionID 
            [your joins here]
            WHERE (your conditions to match company_id)";
    
    // If dates are provided, add date filtering
    if($start_date && $end_date) {
        $sql .= " AND (your date conditions here)";
    }
    
    $sql .= " ORDER BY it.Type";
    
    $result = mysqli_query($conn, $sql);
    
    if(!$result) {
        die(json_encode(["error" => "Query failed: " . mysqli_error($conn)]));
    }
    */
    
    // For now, we'll return empty arrays (no transaction data)
    // Once you add your query above, you'll populate these arrays
    $shipping = [];
    $receiving = [];
    $adjustments = [];
    
    // ========================================================================
    // TODO: PROCESS YOUR QUERY RESULTS HERE
    // ========================================================================
    // After running your query, loop through results and separate them
    // into shipping, receiving, and adjustments arrays
    //
    // Example:
    /*
    while($row = mysqli_fetch_assoc($result)) {
        if($row['Type'] == 'Shipping') {
            $shipping[] = [
                'ShipmentID' => $row['Shipping_ShipmentID'],
                'Product' => $row['Shipping_Product'],
                'PromisedDate' => $row['Shipping_PromisedDate'],
                // add other fields you need
            ];
        } elseif($row['Type'] == 'Receiving') {
            $receiving[] = [
                'ReceivingID' => $row['Receiving_ReceivingID'],
                'Product' => $row['Product_Name'],
                'ReceivedDate' => $row['Receiving_ReceivedDate'],
                // add other fields you need
            ];
        } elseif($row['Type'] == 'Adjustment') {
            $adjustments[] = [
                'AdjustmentID' => $row['Adjustment_AdjustmentID'],
                'Product' => $row['Adjustment_Product'],
                'Date' => $row['Adjustment_Date'],
                // add other fields you need
            ];
        }
    }
    */
    
    // Send the transaction data back as JSON
    $response = [
        'shipping' => $shipping,
        'receiving' => $receiving,
        'adjustments' => $adjustments
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
// ============================================================================
// HANDLE KPI DATA REQUEST (Key Performance Indicators)
// ============================================================================
} elseif(isset($_GET['action']) && $_GET['action'] == 'kpi') {
    
    // Get the company ID from the URL
    $company_id = mysqli_real_escape_string($conn, $_GET['company']);
    
    // Get date range if provided (optional - user may or may not filter by date)
    $start_date = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
    
    // Build date filter for SQL queries
    $date_filter_shipping = "";
    $date_filter_events = "";
    if($start_date && $end_date) {
        $date_filter_shipping = " AND s.PromisedDate BETWEEN '$start_date' AND '$end_date'";
        $date_filter_events = " AND de.EventDate BETWEEN '$start_date' AND '$end_date'";
    }
    
    // ========================================================================
    // KPI QUERY 1: ON-TIME DELIVERY RATE
    // ========================================================================
    // Calculate percentage of shipments delivered on time
    // Only count shipments where ActualDate is not NULL (shipment was actually delivered)
    $sql_delivery = "SELECT 
                     COALESCE(
                         SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) * 100.0 / 
                         NULLIF(SUM(CASE WHEN s.ActualDate IS NOT NULL THEN 1 ELSE 0 END), 0),
                         0
                     ) AS DeliveryRate 
                     FROM Shipping s 
                     WHERE s.DistributorID = '$company_id'
                     $date_filter_shipping";
    
    $result_delivery = mysqli_query($conn, $sql_delivery);
    if(!$result_delivery) {
        die(json_encode(["error" => "Delivery query failed: " . mysqli_error($conn)]));
    }
    $delivery_data = mysqli_fetch_assoc($result_delivery);
    $delivery_rate = $delivery_data && $delivery_data['DeliveryRate'] !== null ? round($delivery_data['DeliveryRate'], 0) : null;
    
    // ========================================================================
    // KPI QUERY 2: AVERAGE & STANDARD DEVIATION OF DELAY
    // ========================================================================
    // Calculate average delay and standard deviation
    // Only include shipments where ActualDate is not NULL
    $sql_delay = "SELECT 
                  COALESCE(AVG(DATEDIFF(s.ActualDate, s.PromisedDate)), 0) AS avg_delays,
                  COALESCE(STDDEV(DATEDIFF(s.ActualDate, s.PromisedDate)), 0) AS stdev_delays
                  FROM Shipping s
                  WHERE s.DistributorID = '$company_id'
                  AND s.ActualDate IS NOT NULL
                  $date_filter_shipping";
    
    $result_delay = mysqli_query($conn, $sql_delay);
    if(!$result_delay) {
        die(json_encode(["error" => "Delay query failed: " . mysqli_error($conn)]));
    }
    $delay_data = mysqli_fetch_assoc($result_delay);
    $avg_delay = $delay_data && $delay_data['avg_delays'] !== null ? round($delay_data['avg_delays'], 1) : null;
    $std_delay = $delay_data && $delay_data['stdev_delays'] !== null ? round($delay_data['stdev_delays'], 1) : null;
    
    // ========================================================================
    // KPI QUERY 3: FINANCIAL HEALTH DATA
    // ========================================================================
    // Get most recent financial health status
    $sql_financial_current = "SELECT HealthScore, Quarter, RepYear
                              FROM FinancialReport
                              WHERE CompanyID = '$company_id'
                              ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1')
                              LIMIT 1";
    
    $result_financial_current = mysqli_query($conn, $sql_financial_current);
    if(!$result_financial_current) {
        die(json_encode(["error" => "Financial query failed: " . mysqli_error($conn)]));
    }
    $financial_current = mysqli_fetch_assoc($result_financial_current);
    $financial_status = $financial_current ? $financial_current['HealthScore'] : 'N/A';
    
    // Get financial trend data for graph (past 4 quarters)
    $sql_financial_trend = "SELECT Quarter, RepYear, HealthScore
                            FROM FinancialReport
                            WHERE CompanyID = '$company_id'
                            ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1')
                            LIMIT 4";
    
    $result_financial_trend = mysqli_query($conn, $sql_financial_trend);
    if(!$result_financial_trend) {
        die(json_encode(["error" => "Financial trend query failed: " . mysqli_error($conn)]));
    }
    $financial_trend = [];
    while($row = mysqli_fetch_assoc($result_financial_trend)) {
        $financial_trend[] = [
            'quarter' => $row['Quarter'] . ' ' . $row['RepYear'],
            'score' => (int)$row['HealthScore']
        ];
    }
    // Reverse so oldest is first (for graph)
    $financial_trend = array_reverse($financial_trend);
    
    // ========================================================================
    // KPI QUERY 4: DISRUPTION EVENTS
    // ========================================================================
    // Get recent disruption events (join with DisruptionCategory to get event name)
    $sql_events = "SELECT dc.CategoryName as EventName, de.EventDate
                   FROM DisruptionEvent de
                   INNER JOIN DisruptionCategory dc ON dc.CategoryID = de.CategoryID
                   INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
                   WHERE ic.AffectedCompanyID = '$company_id'
                   $date_filter_events
                   ORDER BY de.EventDate DESC
                   LIMIT 3";
    
    $result_events = mysqli_query($conn, $sql_events);
    if(!$result_events) {
        die(json_encode(["error" => "Events query failed: " . mysqli_error($conn)]));
    }
    $events = [];
    while($row = mysqli_fetch_assoc($result_events)) {
        $events[] = [
            'name' => $row['EventName'],
            'date' => $row['EventDate']
        ];
    }
    
    // Get disruption trend by month (for graph)
    // This counts events per month
    if($start_date && $end_date) {
        // If dates provided, use that range
        $sql_disruption_trend = "SELECT 
                                 DATE_FORMAT(de.EventDate, '%Y-%m') as month,
                                 COUNT(*) as count
                                 FROM DisruptionEvent de
                                 INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
                                 WHERE ic.AffectedCompanyID = '$company_id'
                                 AND de.EventDate BETWEEN '$start_date' AND '$end_date'
                                 GROUP BY DATE_FORMAT(de.EventDate, '%Y-%m')
                                 ORDER BY month ASC";
    } else {
        // Otherwise, use past year
        $sql_disruption_trend = "SELECT 
                                 DATE_FORMAT(de.EventDate, '%Y-%m') as month,
                                 COUNT(*) as count
                                 FROM DisruptionEvent de
                                 INNER JOIN ImpactsCompany ic ON ic.EventID = de.EventID
                                 WHERE ic.AffectedCompanyID = '$company_id'
                                 AND de.EventDate >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
                                 GROUP BY DATE_FORMAT(de.EventDate, '%Y-%m')
                                 ORDER BY month ASC";
    }
    
    $result_disruption_trend = mysqli_query($conn, $sql_disruption_trend);
    if(!$result_disruption_trend) {
        die(json_encode(["error" => "Disruption trend query failed: " . mysqli_error($conn)]));
    }
    $disruption_trend = [];
    while($row = mysqli_fetch_assoc($result_disruption_trend)) {
        $disruption_trend[] = [
            'month' => $row['month'],
            'count' => (int)$row['count']
        ];
    }
    
    // ========================================================================
    // SEND KPI DATA BACK TO JAVASCRIPT
    // ========================================================================
    $response = [
        'deliveryRate' => $delivery_rate,
        'avgDelay' => $avg_delay,
        'stdDelay' => $std_delay,
        'financialStatus' => $financial_status,
        'financialTrend' => $financial_trend,
        'disruptionTrend' => $disruption_trend,
        'events' => $events
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
// ============================================================================
// HANDLE COMPANY INFO REQUEST (when user clicks on a company in search results)
// ============================================================================
} elseif(isset($_GET['company'])) {
    
    // Get the company ID from the URL
    $company_id = mysqli_real_escape_string($conn, $_GET['company']);
    
    // Get basic company information
    $sql = "SELECT CompanyID, CompanyName, Type, TierLevel, LocationID 
            FROM Company 
            WHERE CompanyID = '" . $company_id . "'";
    
    $result = mysqli_query($conn, $sql);
    
    if(!$result) {
        die(json_encode(["error" => "Query failed: " . mysqli_error($conn)]));
    }
    
    $company = mysqli_fetch_assoc($result);
    
    if(!$company) {
        die(json_encode(["error" => "Company not found"]));
    }
    
    // Get location information (country and continent)
    $sql_location = "SELECT CountryName, ContinentName 
                     FROM Location 
                     WHERE LocationID = '" . $company['LocationID'] . "'";
    $result_location = mysqli_query($conn, $sql_location);
    $location = mysqli_fetch_assoc($result_location);
    
    // Get upstream suppliers (companies this company depends on)
    $sql_upstream = "SELECT GROUP_CONCAT(c.CompanyName SEPARATOR ', ') as Suppliers
                     FROM DependsOn d 
                     INNER JOIN Company c ON c.CompanyID = d.UpstreamCompanyID 
                     WHERE d.DownstreamCompanyID = '" . $company_id . "'";
    $result_upstream = mysqli_query($conn, $sql_upstream);
    $upstream = mysqli_fetch_assoc($result_upstream);
    
    // Get downstream dependencies (companies that depend on this company)
    $sql_downstream = "SELECT GROUP_CONCAT(c.CompanyName SEPARATOR ', ') as Dependencies
                       FROM DependsOn d 
                       INNER JOIN Company c ON c.CompanyID = d.DownstreamCompanyID 
                       WHERE d.UpstreamCompanyID = '" . $company_id . "'";
    $result_downstream = mysqli_query($conn, $sql_downstream);
    $downstream = mysqli_fetch_assoc($result_downstream);
    
    // Get most recent financial status
    $sql_financial = "SELECT HealthScore, Quarter, RepYear 
                      FROM FinancialReport 
                      WHERE CompanyID = '" . $company_id . "' 
                      ORDER BY RepYear DESC, 
                               FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') 
                      LIMIT 1";
    $result_financial = mysqli_query($conn, $sql_financial);
    $financial = mysqli_fetch_assoc($result_financial);
    
    // Get manufacturer capacity (only if company is a manufacturer)
    $sql_capacity = "SELECT FactoryCapacity 
                     FROM Manufacturer 
                     WHERE CompanyID = '" . $company_id . "'";
    $result_capacity = mysqli_query($conn, $sql_capacity);
    $capacity = mysqli_fetch_assoc($result_capacity);
    
    // Get products this company supplies
    $sql_products = "SELECT GROUP_CONCAT(DISTINCT p.ProductName SEPARATOR ', ') as Products,
                     GROUP_CONCAT(DISTINCT p.Category SEPARATOR ', ') as Categories
                     FROM SuppliesProduct s 
                     INNER JOIN Product p ON p.ProductID = s.ProductID 
                     WHERE s.SupplierID = '" . $company_id . "'";
    $result_products = mysqli_query($conn, $sql_products);
    $products = mysqli_fetch_assoc($result_products);
    
    // Build the response with all company information
    $response = [
        'CompanyName' => $company['CompanyName'],
        'Address' => ($location ? $location['CountryName'] . ', ' . $location['ContinentName'] : 'N/A'),
        'Type' => $company['Type'],
        'TierLevel' => $company['TierLevel'],
        'UpstreamSuppliers' => ($upstream && $upstream['Suppliers'] ? $upstream['Suppliers'] : 'N/A'),
        'DownstreamDependencies' => ($downstream && $downstream['Dependencies'] ? $downstream['Dependencies'] : 'N/A'),
        'FinancialStatus' => ($financial ? $financial['HealthScore'] . ' (' . $financial['Quarter'] . ' ' . $financial['RepYear'] . ')' : 'N/A'),
        'Capacity' => ($capacity ? $capacity['FactoryCapacity'] . ' units/day' : 'Not a Manufacturer'),
        'Products' => ($products && $products['Products'] ? $products['Products'] : 'N/A'),
        'ProductDiversity' => ($products && $products['Categories'] ? $products['Categories'] : 'N/A')
    ];
    
    // Send the company info back as JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    
} else {
    // Invalid request - no valid action specified
    die(json_encode(["error" => "Invalid request"]));
}

// Close database connection
$conn->close();
?>