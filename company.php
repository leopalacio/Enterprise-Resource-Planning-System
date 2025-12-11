<?php
// Start Session and check authentication
session_start();
if (!isset($_SESSION['username'])) {
    header("Location:index.php");
    exit();
}

// Only supply chain manager can edit 
$canEdit = ($_SESSION['role'] === 'SupplyChainManager');

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


// ONLY run API code if there's an action or company parameter
if (isset($_GET['action']) || isset($_POST['action']) || isset($_GET['company'])) {
    ob_start();

    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }

    if (isset($_GET['action']) && $_GET['action'] !== 'export_csv') {
        header('Content-Type: application/json');
    }


    // Database connection
    $servername = "mydb.itap.purdue.edu";
    $username = "g1151928";
    $password = "JuK3J593";
    $database = "g1151928";

    $conn = mysqli_connect($servername, $username, $password, $database);
    mysqli_set_charset($conn, "utf8mb4");

    // Timezone
    date_default_timezone_set('America/Indiana/Indianapolis');

    if (!$conn) {
        die(json_encode(["error" => "Connection failed"]));
    }

    // ============================================================================
    // SEARCH COMPANIES
    // ============================================================================
    // Returns top 10 companies matching search term
    // https://www.w3schools.com/php/php_mysql_select.asp

    if (isset($_GET['action']) && $_GET['action'] === 'search') {
        $term = mysqli_real_escape_string($conn, $_GET['query']);
        $sql = "SELECT CompanyID, CompanyName FROM Company WHERE CompanyName LIKE '$term%' ORDER BY CompanyName LIMIT 10";
        $res = mysqli_query($conn, $sql);
        $out = [];
        while ($r = mysqli_fetch_assoc($res))
            $out[] = $r;
        echo json_encode($out);
        mysqli_close($conn);
        exit;
    }


    // ============================================================================
    // SEARCH LOCATIONS
    // ============================================================================
    // Searches locations by country or continent name

    if (isset($_GET['action']) && $_GET['action'] === 'search_location') {
        $term = mysqli_real_escape_string($conn, $_GET['query']);
        $sql = "SELECT LocationID, City, CountryName, ContinentName
            FROM Location
            WHERE City LIKE '$term%'
                OR CountryName LIKE '$term%'
                OR ContinentName LIKE '$term%'
            ORDER BY City, CountryName
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
                'City' => $r['City'],
                'CountryName' => $r['CountryName'],
                'ContinentName' => $r['ContinentName']
            ];
        }
        echo json_encode($out);
        mysqli_close($conn);
        exit;


    }


    // ============================================================================
    // VALIDATE CITY VIA GEONAMES (Server-side proxy to avoid CORS)
    // ============================================================================
    // Server-side proxy to validate city names using GeoNames geographical database. I had to 
    // proxy bc direct browser calls to GeoNames API are blocked by CORS (Cross-Origin Resource Sharing)
    // Making the API call from PHP server-side avoids these browser security restrictions

    if (isset($_GET['action']) && $_GET['action'] === 'validate_city') {
        // Extract city and country code from request parameters
        $city = isset($_GET['city']) ? $_GET['city'] : '';
        $countryCode = isset($_GET['country_code']) ? $_GET['country_code'] : '';

        if (empty($city) || empty($countryCode)) {
            echo json_encode(["error" => "Missing parameters"]);
            exit;
        }

        // Build GeoNames API request URL
        // API Documentation: http://www.geonames.org/export/geonames-search.html
        // Parameters:
        //   - q: search query (city name)
        //   - country: ISO-3166 2-letter country code (e.g., "US", "FR", "GB")
        //   - maxRows: limit results to 5 cities
        //   - username: GeoNames account username (free account required)
        //   - featureClass: P = populated places (cities, towns, villages)
        $url = "http://api.geonames.org/searchJSON?" .
            "q=" . urlencode($city) .
            "&country=" . urlencode($countryCode) .
            "&maxRows=5" .
            "&username=332group" .
            "&featureClass=P";

        // Make HTTP request to GeoNames API from server
        // https://www.php.net/manual/en/function.file-get-contents.php

        $response = @file_get_contents($url);

        if ($response === false) {
            echo json_encode(["error" => "Could not connect to city validation service"]);
        } else {
            header('Content-Type: application/json');
            echo $response;
        }

        mysqli_close($conn);
        exit;
    }

    // ============================================================================
    // ADD NEW LOCATION
    // ============================================================================
    // Insert new location or retrieve existing location ID if duplicate
    // https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_location') {
        $city = mysqli_real_escape_string($conn, $_POST['city']);
        $country = mysqli_real_escape_string($conn, $_POST['country']);
        $continent = mysqli_real_escape_string($conn, $_POST['continent']);

        $valid_continents = ['Africa', 'Asia', 'Europe', 'North America', 'South America', 'Oceania', 'Antarctica'];
        if (!in_array($continent, $valid_continents)) {
            echo json_encode(["error" => "Invalid continent"]);
            mysqli_close($conn);
            exit;
        }

        // Attempts insert with new location data
        // if duplicate location, then on duplicate key runs instead
        // it sets the location id to the last insert ID
        // makes sql return the existing location's ID 

        $sql = "INSERT INTO Location (City, CountryName, ContinentName) 
            VALUES ('$city', '$country', '$continent')
            ON DUPLICATE KEY UPDATE LocationID = LAST_INSERT_ID(LocationID)";

        if (mysqli_query($conn, $sql)) {
            $location_id = mysqli_insert_id($conn);
            $affected = mysqli_affected_rows($conn);

            error_log("ADD LOCATION - City: $city, Affected: $affected, ID: $location_id");

            // Check affected_rows to determine if new row was inserted
            // 1 = new row inserted
            // 2 = existing row updated (ON DUPLICATE KEY triggered)
            // 0 = no change (shouldn't happen with our query)
            $is_new = ($affected === 1);

            echo json_encode([
                "success" => true,
                "location_id" => $location_id,
                "is_new" => $is_new
            ]);
        } else {
            $error = mysqli_error($conn);
            error_log("SQL ERROR: $error");
            echo json_encode(["error" => $error]);
        }
        mysqli_close($conn);
        exit;
    }

    // ============================================================================
    // UPDATE COMPANY
    // ============================================================================
    // Updates company tier, type, and location
    // https://www.w3schools.com/php/php_mysql_update.asp

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_company') {
        $company_id = mysqli_real_escape_string($conn, $_POST['company_id']);
        $tier = mysqli_real_escape_string($conn, $_POST['tier']);
        $type = mysqli_real_escape_string($conn, $_POST['type']);
        $location_id = mysqli_real_escape_string($conn, $_POST['location_id']);

        // Validate input values
        if (!in_array($tier, ['1', '2', '3']) || !in_array($type, ['Manufacturer', 'Distributor', 'Retailer'])) {
            echo json_encode(["error" => "Invalid input"]);
            mysqli_close($conn);
            exit;
        }


        $sql = "UPDATE Company c
            INNER JOIN Location l ON l.LocationID='$location_id'
            SET c.TierLevel='$tier', c.Type='$type', c.LocationID='$location_id' 
            WHERE c.CompanyID='$company_id'";



        if (mysqli_query($conn, $sql)) {
            $affected = mysqli_affected_rows($conn);
            if ($affected == 0) {
                echo json_encode(["error" => "Invalid location or company not found"]);
            } else {
                echo json_encode(["success" => true]);
            }
        } else {
            echo json_encode(["error" => mysqli_error($conn)]);
        }
        mysqli_close($conn);
        exit;
    }

    // ============================================================================
    // UPDATE SHIPMENT 
    // ============================================================================
    // Updates shipment actual date and quantity
    // Only allows editing if shipment hasn't been delivered yet

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_shipment') {
        $shipment_id = mysqli_real_escape_string($conn, $_POST['shipment_id']);
        $quantity = mysqli_real_escape_string($conn, $_POST['quantity']);

        // Update ONLY quantity, ONLY if shipment is not completed
        $sql = "UPDATE Shipping 
                SET Quantity='$quantity' 
                WHERE ShipmentID='$shipment_id' AND ActualDate IS NULL";

        if (mysqli_query($conn, $sql)) {
            $affected = mysqli_affected_rows($conn);

            if ($affected == 0) {
                $check = mysqli_query($conn, "SELECT ShipmentID, ActualDate FROM Shipping WHERE ShipmentID='$shipment_id'");
                if (mysqli_num_rows($check) == 0) {
                    echo json_encode(["error" => "Shipment not found"]);
                } else {
                    echo json_encode(["error" => "Cannot edit completed shipment"]);
                }
            } else {
                echo json_encode(["success" => true]);
            }
        } else {
            echo json_encode(["error" => mysqli_error($conn)]);
        }
        mysqli_close($conn);
        exit;
    }

    // ============================================================================
    // UPDATE ADJUSTMENT
    // ============================================================================
    // Updates inventory adjustment quantity and reason
    // Only allows editing if adjustment date is in the future

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_adjustment') {
        $adjustment_id = mysqli_real_escape_string($conn, $_POST['adjustment_id']);
        $quantity_change = mysqli_real_escape_string($conn, $_POST['quantity_change']);
        $reason = mysqli_real_escape_string($conn, $_POST['reason']);

        // ONE QUERY - Update only if adjustment date is in the future
        $sql = "UPDATE InventoryAdjustment 
                SET QuantityChange='$quantity_change', Reason='$reason' 
                WHERE AdjustmentID='$adjustment_id' 
                AND AdjustmentDate >= CURDATE()";

        if (mysqli_query($conn, $sql)) {
            $affected = mysqli_affected_rows($conn);

            if ($affected == 0) {
                // Check if adjustment exists or if it's in the past
                $check = mysqli_query($conn, "SELECT AdjustmentID, AdjustmentDate FROM InventoryAdjustment WHERE AdjustmentID='$adjustment_id'");
                if (mysqli_num_rows($check) == 0) {
                    echo json_encode(["error" => "Adjustment not found"]);
                } else {
                    echo json_encode(["error" => "Cannot edit past adjustments"]);
                }
            } else {
                echo json_encode(["success" => true]);
            }
        } else {
            echo json_encode(["error" => mysqli_error($conn)]);
        }
        mysqli_close($conn);
        exit;
    }

    // ============================================================================
    // TRANSACTIONS 
    // ============================================================================
    // Gets all shipping, receiving, and adjustment transactions for a company
    // https://www.w3schools.com/sql/sql_join.asp

    if (isset($_GET['action']) && $_GET['action'] === 'transactions') {
        $cid = mysqli_real_escape_string($conn, $_GET['company']);
        $s = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
        $e = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
        $filter = ($s && $e) ? "AND (s.PromisedDate BETWEEN '$s' AND '$e' OR r.ReceivedDate BETWEEN '$s' AND '$e' OR a.AdjustmentDate BETWEEN '$s' AND '$e')" : "";

        // Get product info through Shipping for Receiving records
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

        // Separate results into shipping, receiving, and adjustments
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

        echo json_encode(['shipping' => $ship, 'receiving' => $recv, 'adjustments' => $adj]);
        mysqli_close($conn);
        exit;
    }


    // ============================================================================
    // KPI
    // ============================================================================
    // Calculates on-time delivery rate, delay stats, and gets financial data
    // https://www.w3schools.com/sql/sql_count_avg_sum.asp

    if (isset($_GET['action']) && $_GET['action'] === 'kpi') {
        $cid = mysqli_real_escape_string($conn, $_GET['company']);
        $s = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
        $e = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';
        $df = ($s && $e) ? "AND s.PromisedDate BETWEEN '$s' AND '$e'" : '';

        // Calculate on-time delivery rate (counts both incoming and outgoing shipments)
        // A shipment is on-time if actual date is less than or equal to promised date
        $q1 = "SELECT SUM(CASE WHEN s.ActualDate<=s.PromisedDate THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0) AS Rate, COUNT(*) as TotalShipments FROM Shipping s WHERE (s.SourceCompanyID='$cid' OR s.DestinationCompanyID='$cid') AND s.ActualDate IS NOT NULL $df";
        $r1 = mysqli_query($conn, $q1);
        $rate = ($r1 && ($x = mysqli_fetch_assoc($r1)) && $x['Rate'] !== null) ? round($x['Rate'], 1) : null;

        // Calculate average delay and standard deviation
        // DATEDIFF calculates difference in days between two dates
        // https://www.w3schools.com/sql/func_mysql_datediff.asp
        $q2 = "SELECT AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS avgDelay,
          STDDEV(DATEDIFF(s.ActualDate, s.PromisedDate)) AS stdDelay
          FROM Shipping s
          WHERE s.DestinationCompanyID='$cid' AND s.ActualDate IS NOT NULL $df";
        $r2 = mysqli_query($conn, $q2);
        $row2 = $r2 ? mysqli_fetch_assoc($r2) : [];

        // Get most recent financial health score
        $q3 = "SELECT HealthScore FROM FinancialReport
          WHERE CompanyID='$cid'
          ORDER BY RepYear DESC, FIELD(Quarter,'Q4','Q3','Q2','Q1') LIMIT 1";
        $r3 = mysqli_query($conn, $q3);
        $fin = ($r3 && ($y = mysqli_fetch_assoc($r3))) ? $y['HealthScore'] : 'N/A';

        //  Get financial trend data for past 8 quarters
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
                    'score' => (int) $row['HealthScore']
                ];
            }
            // Reverse array so oldest quarter is first for graph x-axis
            $financialTrend = array_reverse($financialTrend);
        }

        // Get recent disruption events affecting this company
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
            'financialTrend' => $financialTrend,
            'events' => $events
        ]);
        mysqli_close($conn);
        exit;
    }

    // ============================================================================
    // EXPORT TO CSV
    // ============================================================================
    // Exports company data, transactions, or KPIs to CSV file
    // https://www.php.net/manual/en/function.fputcsv.php

    if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
        // Turn off ALL errors immediately
        error_reporting(0);
        ini_set('display_errors', 0);

        // Clear ALL output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Get parameters
        $type = mysqli_real_escape_string($conn, $_GET['type']);
        $cid = mysqli_real_escape_string($conn, $_GET['company']);
        $s = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
        $e = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';

        // Set headers and create output
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $type . '_export_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        if ($type === 'transactions') {
            $filter = ""; // Remove date filtering temporarily

            fputcsv($output, ['Type', 'ID', 'Product', 'Company/Route', 'Date', 'Quantity', 'Status/Reason']);


            // Shipping
            $sql = "SELECT 'Shipping' as Type, s.ShipmentID, p.ProductName, 
                CONCAT(cs.CompanyName, ' → ', cd.CompanyName) as Route,
                s.PromisedDate, s.ActualDate, s.Quantity
                FROM Shipping s
                JOIN Product p ON p.ProductID = s.ProductID
                JOIN Company cs ON cs.CompanyID = s.SourceCompanyID
                JOIN Company cd ON cd.CompanyID = s.DestinationCompanyID
                WHERE (s.SourceCompanyID='$cid' OR s.DestinationCompanyID='$cid') $filter";

            $res = @mysqli_query($conn, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    fputcsv($output, [
                        $row['Type'],
                        $row['ShipmentID'],
                        $row['ProductName'],
                        $row['Route'],
                        $row['PromisedDate'] . ' (Promised), ' . ($row['ActualDate'] ?: 'Pending') . ' (Actual)',
                        $row['Quantity'],
                        $row['ActualDate'] ? 'Completed' : 'Pending'
                    ]);
                }
            }

            // Receiving
            $sql = "SELECT 'Receiving' as Type, r.ReceivingID, p.ProductName, c.CompanyName,
                r.ReceivedDate, r.QuantityReceived
                FROM Receiving r
                JOIN Shipping s ON s.ShipmentID = r.ShipmentID
                JOIN Product p ON p.ProductID = s.ProductID
                JOIN Company c ON c.CompanyID = r.ReceiverCompanyID
                WHERE r.ReceiverCompanyID='$cid' $filter";

            $res = @mysqli_query($conn, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    fputcsv($output, [
                        $row['Type'],
                        $row['ReceivingID'],
                        $row['ProductName'],
                        $row['CompanyName'],
                        $row['ReceivedDate'],
                        $row['QuantityReceived'],
                        'Completed'
                    ]);
                }
            }

            // Adjustments
            $sql = "SELECT 'Adjustment' as Type, a.AdjustmentID, p.ProductName, c.CompanyName,
                a.AdjustmentDate, a.QuantityChange, a.Reason
                FROM InventoryAdjustment a
                JOIN Product p ON p.ProductID = a.ProductID
                JOIN Company c ON c.CompanyID = a.CompanyID
                WHERE a.CompanyID='$cid' $filter";

            $res = @mysqli_query($conn, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    fputcsv($output, [
                        $row['Type'],
                        $row['AdjustmentID'],
                        $row['ProductName'],
                        $row['CompanyName'],
                        $row['AdjustmentDate'],
                        $row['QuantityChange'],
                        $row['Reason']
                    ]);
                }
            }
        } elseif ($type === 'kpi') {
            $df = ($s && $e) ? "AND s.PromisedDate BETWEEN '$s' AND '$e'" : '';

            fputcsv($output, ['Metric', 'Value']);

            // Export on-time delivery rate
            $q1 = "SELECT SUM(CASE WHEN s.ActualDate<=s.PromisedDate THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0) AS Rate
               FROM Shipping s WHERE s.DestinationCompanyID='$cid' $df";
            $r1 = @mysqli_query($conn, $q1);
            $rate = ($r1 && ($x = mysqli_fetch_assoc($r1)) && $x['Rate'] !== null) ? round($x['Rate'], 1) . '%' : 'N/A';
            fputcsv($output, ['On-Time Delivery Rate', $rate]);

            // Export delay statistics
            $q2 = "SELECT AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS avgDelay,
                      STDDEV(DATEDIFF(s.ActualDate, s.PromisedDate)) AS stdDelay
               FROM Shipping s WHERE s.DestinationCompanyID='$cid' AND s.ActualDate IS NOT NULL $df";
            $r2 = @mysqli_query($conn, $q2);
            $row2 = $r2 ? mysqli_fetch_assoc($r2) : [];
            fputcsv($output, ['Average Delay (days)', isset($row2['avgDelay']) ? round($row2['avgDelay'], 1) : 'N/A']);
            fputcsv($output, ['Std Dev of Delay (days)', isset($row2['stdDelay']) ? round($row2['stdDelay'], 1) : 'N/A']);

            // Export financial status
            $q3 = "SELECT HealthScore, Quarter, RepYear FROM FinancialReport
               WHERE CompanyID='$cid' ORDER BY RepYear DESC, FIELD(Quarter,'Q4','Q3','Q2','Q1') LIMIT 1";
            $r3 = @mysqli_query($conn, $q3);
            $fin = ($r3 && ($y = mysqli_fetch_assoc($r3))) ? $y['HealthScore'] . ' (' . $y['Quarter'] . ' ' . $y['RepYear'] . ')' : 'N/A';
            fputcsv($output, ['Financial Health Status', $fin]);

            // Export financial trend
            fputcsv($output, ['', '']);
            fputcsv($output, ['Quarter', 'Health Score']);
            $q4 = "SELECT Quarter, RepYear, HealthScore FROM FinancialReport
               WHERE CompanyID='$cid' ORDER BY RepYear DESC, FIELD(Quarter,'Q4','Q3','Q2','Q1') LIMIT 8";
            $r4 = @mysqli_query($conn, $q4);
            if ($r4) {
                while ($row = mysqli_fetch_assoc($r4)) {
                    fputcsv($output, [$row['Quarter'] . ' ' . $row['RepYear'], $row['HealthScore']]);
                }
            }
        } elseif ($type === 'company_info') {
            $res = @mysqli_query($conn, "SELECT CompanyName, Type, TierLevel, LocationID FROM Company WHERE CompanyID='$cid'");
            $c = $res ? mysqli_fetch_assoc($res) : null;

            if (!$c) {
                fputcsv($output, ['Error', 'Company not found']);
                fclose($output);
                mysqli_close($conn);
                exit();
            }

            // Get related data
            $loc = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT CountryName, ContinentName FROM Location WHERE LocationID='{$c['LocationID']}'"));
            $up = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(c.CompanyName SEPARATOR ', ') AS Suppliers FROM DependsOn d JOIN Company c ON c.CompanyID=d.UpstreamCompanyID WHERE d.DownstreamCompanyID='$cid'"));
            $down = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(c.CompanyName SEPARATOR ', ') AS Dependencies FROM DependsOn d JOIN Company c ON c.CompanyID=d.DownstreamCompanyID WHERE d.UpstreamCompanyID='$cid'"));
            $fin = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT HealthScore, Quarter, RepYear FROM FinancialReport WHERE CompanyID='$cid' ORDER BY RepYear DESC, FIELD(Quarter,'Q4','Q3','Q2','Q1') LIMIT 1"));
            $cap = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT FactoryCapacity FROM Manufacturer WHERE CompanyID='$cid'"));
            $routes = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(CONCAT(f.CompanyName,' → ',t.CompanyName) SEPARATOR ', ') AS Routes FROM OperatesLogistics ol JOIN Company f ON f.CompanyID=ol.FromCompanyID JOIN Company t ON t.CompanyID=ol.ToCompanyID WHERE ol.DistributorID='$cid'"));
            $prod = @mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(DISTINCT p.ProductName SEPARATOR ', ') AS Products, GROUP_CONCAT(DISTINCT p.Category SEPARATOR ', ') AS Categories FROM SuppliesProduct sp JOIN Product p ON p.ProductID=sp.ProductID WHERE sp.SupplierID='$cid'"));

            fputcsv($output, ['Field', 'Value']);
            fputcsv($output, ['Company Name', $c['CompanyName']]);
            fputcsv($output, ['Address', ($loc ? $loc['CountryName'] . ', ' . $loc['ContinentName'] : 'N/A')]);
            fputcsv($output, ['Type', $c['Type']]);
            fputcsv($output, ['Tier Level', $c['TierLevel']]);
            fputcsv($output, ['Upstream Suppliers', isset($up['Suppliers']) && $up['Suppliers'] ? $up['Suppliers'] : 'N/A']);
            fputcsv($output, ['Downstream Dependencies', isset($down['Dependencies']) && $down['Dependencies'] ? $down['Dependencies'] : 'N/A']);
            fputcsv($output, ['Financial Status', $fin ? ($fin['HealthScore'] . " ({$fin['Quarter']} {$fin['RepYear']})") : "N/A"]);
            fputcsv($output, ['Capacity', $cap ? $cap['FactoryCapacity'] . " units/day" : "Not a Manufacturer"]);
            fputcsv($output, ['Routes Operated', isset($routes['Routes']) && $routes['Routes'] ? $routes['Routes'] : "Not a Distributor"]);
            fputcsv($output, ['Products', isset($prod['Products']) && $prod['Products'] ? $prod['Products'] : "N/A"]);
            fputcsv($output, ['Product Diversity', isset($prod['Categories']) && $prod['Categories'] ? $prod['Categories'] : "N/A"]);
        }

        fclose($output);
        mysqli_close($conn);
        exit();
    }

    // ============================================================================
    // SUPPLY CHAIN NETWORK - 2 LEVELS DEEP
    // ============================================================================
    if (isset($_GET['action']) && $_GET['action'] === 'supply_chain_network') {
        $cid = mysqli_real_escape_string($conn, $_GET['company']);

        // Get current company
        $currentSQL = "SELECT CompanyID, CompanyName, Type FROM Company WHERE CompanyID='$cid'";
        $currentResult = mysqli_query($conn, $currentSQL);
        $currentCompany = mysqli_fetch_assoc($currentResult);

        if (!$currentCompany) {
            echo json_encode(["error" => "Company not found"]);
            mysqli_close($conn);
            exit();
        }

        $nodes = [];
        $edges = [];
        $processedCompanies = [$cid];

        // Add current company as center node (green)
        $nodes[] = [
            'id' => (int) $currentCompany['CompanyID'],
            'label' => $currentCompany['CompanyName'],
            'color' => '#22c55e',
            'size' => 35,
            'font' => ['size' => 18, 'color' => '#fff', 'bold' => true],
            'borderWidth' => 4
        ];

        // LEVEL 1: Direct Suppliers (Blue)
        $upstreamSQL = "SELECT c.CompanyID, c.CompanyName, c.Type
                    FROM DependsOn d
                    JOIN Company c ON c.CompanyID = d.UpstreamCompanyID
                    WHERE d.DownstreamCompanyID = '$cid'";
        $upstreamResult = mysqli_query($conn, $upstreamSQL);
        $level1Suppliers = [];

        if ($upstreamResult) {
            while ($row = mysqli_fetch_assoc($upstreamResult)) {
                $supplierId = (int) $row['CompanyID'];
                $level1Suppliers[] = $supplierId;
                $processedCompanies[] = $supplierId;

                $nodes[] = [
                    'id' => $supplierId,
                    'label' => $row['CompanyName'],
                    'color' => '#3b82f6',
                    'size' => 25,
                    'shape' => 'box',
                    'font' => ['size' => 14, 'bold' => true]
                ];
                $edges[] = [
                    'from' => $supplierId,
                    'to' => (int) $cid,
                    'arrows' => 'to',
                    'color' => '#64748b',
                    'width' => 3
                ];
            }
        }

        // LEVEL 2: Suppliers' Suppliers (Light Blue)
        foreach ($level1Suppliers as $supplierId) {
            $level2SQL = "SELECT c.CompanyID, c.CompanyName, c.Type
                      FROM DependsOn d
                      JOIN Company c ON c.CompanyID = d.UpstreamCompanyID
                      WHERE d.DownstreamCompanyID = '$supplierId'";
            $level2Result = mysqli_query($conn, $level2SQL);

            if ($level2Result) {
                while ($row = mysqli_fetch_assoc($level2Result)) {
                    $level2Id = (int) $row['CompanyID'];

                    if (!in_array($level2Id, $processedCompanies)) {
                        $processedCompanies[] = $level2Id;

                        $nodes[] = [
                            'id' => $level2Id,
                            'label' => $row['CompanyName'],
                            'color' => '#93c5fd',
                            'size' => 18,
                            'shape' => 'box',
                            'font' => ['size' => 11]
                        ];
                    }

                    $edges[] = [
                        'from' => $level2Id,
                        'to' => $supplierId,
                        'arrows' => 'to',
                        'color' => '#cbd5e1',
                        'width' => 1.5,
                        'dashes' => true
                    ];
                }
            }
        }

        // LEVEL 1: Direct Customers (Orange)
        $downstreamSQL = "SELECT c.CompanyID, c.CompanyName, c.Type
                      FROM DependsOn d
                      JOIN Company c ON c.CompanyID = d.DownstreamCompanyID
                      WHERE d.UpstreamCompanyID = '$cid'";
        $downstreamResult = mysqli_query($conn, $downstreamSQL);
        $level1Customers = [];

        if ($downstreamResult) {
            while ($row = mysqli_fetch_assoc($downstreamResult)) {
                $customerId = (int) $row['CompanyID'];
                $level1Customers[] = $customerId;
                $processedCompanies[] = $customerId;

                $nodes[] = [
                    'id' => $customerId,
                    'label' => $row['CompanyName'],
                    'color' => '#f59e0b',
                    'size' => 25,
                    'shape' => 'box',
                    'font' => ['size' => 14, 'bold' => true]
                ];
                $edges[] = [
                    'from' => (int) $cid,
                    'to' => $customerId,
                    'arrows' => 'to',
                    'color' => '#64748b',
                    'width' => 3
                ];
            }
        }

        // LEVEL 2: Customers' Customers (Light Orange)
        foreach ($level1Customers as $customerId) {
            $level2SQL = "SELECT c.CompanyID, c.CompanyName, c.Type
                      FROM DependsOn d
                      JOIN Company c ON c.CompanyID = d.DownstreamCompanyID
                      WHERE d.UpstreamCompanyID = '$customerId'";
            $level2Result = mysqli_query($conn, $level2SQL);

            if ($level2Result) {
                while ($row = mysqli_fetch_assoc($level2Result)) {
                    $level2Id = (int) $row['CompanyID'];

                    if (!in_array($level2Id, $processedCompanies)) {
                        $processedCompanies[] = $level2Id;

                        $nodes[] = [
                            'id' => $level2Id,
                            'label' => $row['CompanyName'],
                            'color' => '#fcd34d',
                            'size' => 18,
                            'shape' => 'box',
                            'font' => ['size' => 11]
                        ];
                    }

                    $edges[] = [
                        'from' => $customerId,
                        'to' => $level2Id,
                        'arrows' => 'to',
                        'color' => '#cbd5e1',
                        'width' => 1.5,
                        'dashes' => true
                    ];
                }
            }
        }

        $response = [
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => [
                'suppliers' => count($level1Suppliers),
                'customers' => count($level1Customers)
            ]
        ];

        echo json_encode($response);
        mysqli_close($conn);
        exit();
    }

    // ============================================================================
    // COMPANY INFO
    // ============================================================================

    if (isset($_GET['company']) && !isset($_GET['action'])) {
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

        // Location - NOW INCLUDING CITY
        $loc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT City, CountryName, ContinentName FROM Location WHERE LocationID='{$c['LocationID']}'"));

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
        $routes = ($routes_query && mysqli_num_rows($routes_query) > 0) ? mysqli_fetch_assoc($routes_query) : ['Routes' => null];

        // Products
        $prod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT GROUP_CONCAT(DISTINCT p.ProductName SEPARATOR ', ') AS Products, GROUP_CONCAT(DISTINCT p.Category SEPARATOR ', ') AS Categories FROM SuppliesProduct sp JOIN Product p ON p.ProductID=sp.ProductID WHERE sp.SupplierID='$cid'"));

        // BUILD ADDRESS STRING WITH CITY
        $addressParts = [];
        if ($loc) {
            if (!empty($loc['City']))
                $addressParts[] = $loc['City'];
            if (!empty($loc['CountryName']))
                $addressParts[] = $loc['CountryName'];
            if (!empty($loc['ContinentName']))
                $addressParts[] = $loc['ContinentName'];
        }
        $addressString = count($addressParts) > 0 ? implode(', ', $addressParts) : 'N/A';

        echo json_encode([
            "CompanyName" => $c['CompanyName'],
            "Address" => $addressString,  // NOW INCLUDES CITY IF IT EXISTS
            "LocationID" => $c['LocationID'],
            "Type" => $c['Type'],
            "TierLevel" => $c['TierLevel'],
            "UpstreamSuppliers" => isset($up['Suppliers']) && $up['Suppliers'] ? $up['Suppliers'] : 'N/A',
            "DownstreamDependencies" => isset($down['Dependencies']) && $down['Dependencies'] ? $down['Dependencies'] : 'N/A',
            "FinancialStatus" => $fin ? ($fin['HealthScore'] . " ({$fin['Quarter']} {$fin['RepYear']})") : "N/A",
            "Capacity" => $cap ? $cap['FactoryCapacity'] . " units/day" : "Not a Manufacturer",
            "RoutesOperated" => isset($routes['Routes']) && $routes['Routes'] ? $routes['Routes'] : "Not a Distributor",
            "Products" => isset($prod['Products']) && $prod['Products'] ? $prod['Products'] : "N/A",
            "ProductDiversity" => isset($prod['Categories']) && $prod['Categories'] ? $prod['Categories'] : "N/A"
        ]);
        mysqli_close($conn);
        exit;
    }

    // THIS MUST BE LAST - It catches any unmatched API requests
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
    <script src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
    <link rel="stylesheet" href="css/dashboard.css?v=15">

    <style>

    </style>

</head>

<body class="company-page">
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
        <div
            style="display: flex; align-items: center; margin-bottom: 20px; max-width: 1400px; margin-left: auto; margin-right: auto;">
            <div style="flex: 1;"></div>
            <h1 class="page-title" style="margin: 0; white-space: nowrap; text-align: center;">Company Name</h1>
            <div style="flex: 1; display: flex; justify-content: flex-end;">
                <button class="btn" onclick="openNetworkModal()"
                    style="margin: 0 35px 0 0; padding: 8px 16px; font-size: 13px; white-space: nowrap;">
                    View Supply Chain Network
                </button>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- ===== LEFT COLUMN: COMPANY INFORMATION ===== -->
            <div class="card">
                <div class="card-title-wrapper">
                    <h2 class="card-title">Company Information</h2> <button class="export-btn"
                        onclick="exportData('company_info')" data-tooltip="Export as CSV"> 📥 </button>
                </div>


                <!-- ROW 1: Address (full width) with button on the right -->
                <div class="form-row">
                    <div class="form-group full-width">
                        <label>Address:</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <div style="flex: 1; position: relative;">
                                <input type="text" id="address" placeholder="Start typing location..."
                                    autocomplete="off" style="width: 100%;" <?php echo $canEdit ? '' : 'readonly style="background-color: #f5f5f5; cursor: not-allowed; width: 100%;"'; ?>>
                                <?php if ($canEdit): ?>
                                    <div class="search-results" id="location-results"
                                        style="position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;"></div>
                                <?php endif; ?>
                                <input type="hidden" id="location-id" value="">
                            </div>

                            <?php if ($canEdit): ?>
                                <button class="btn" onclick="showAddLocationForm()"
                                    style="margin: 0; padding: 6px 15px; cursor: pointer;"> Add New Location
                                </button>

                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ROW 2: Company Type and Tier Level -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Company Type:</label>
                        <select id="company-type" <?php echo $canEdit ? '' : 'disabled style="background-color: #f5f5f5; cursor: not-allowed;"'; ?>>
                            <option value="Manufacturer">Manufacturer</option>
                            <option value="Distributor">Distributor</option>
                            <option value="Retailer">Retailer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Tier Level:</label>
                        <select id="tier-level" <?php echo $canEdit ? '' : 'disabled style="background-color: #f5f5f5; cursor: not-allowed;"'; ?>>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                        </select>
                    </div>
                </div>

                <!-- ROW 3: Most Recent Financial Status (full width) -->
                <div class="form-row">
                    <div class="form-group full-width">
                        <label>Most Recent Financial Status:</label>
                        <input type="text" id="financial-status" readonly>
                    </div>
                </div>


                <div class="form-row">
                    <div class="form-group full-width">
                        <label>Depends On:</label>
                        <textarea id="depends-on" readonly rows="2"
                            style="resize: vertical; white-space: pre-line;"></textarea>
                    </div>
                </div>


                <div class="form-row">
                    <div class="form-group full-width">
                        <label>Dependencies:</label>
                        <textarea id="dependencies" readonly rows="2"
                            style="resize: vertical; white-space: pre-line;"></textarea>
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
                        <textarea id="products" readonly rows="2"
                            style="resize: vertical; white-space: pre-line;"></textarea>
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
                <div class="card-title-wrapper">
                    <h2 class="card-title">Transactions</h2>
                    <button class="export-btn" onclick="exportData('transactions')" data-tooltip="Export as CSV">
                        📥
                    </button>
                </div>

                <div class="date-inputs" style="margin-bottom: 15px;">
                    <input type="date" id="transaction-start-date" style="margin-right: 8px;">
                    <input type="date" id="transaction-end-date" style="margin-right: 8px;">
                    <button class="btn" onclick="filterTransactionsByDate()"
                        style="margin-top: 0; padding: 6px 15px;">Filter by Date</button>
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
                    <div class="card-title-wrapper">
                        <h2 class="card-title">Key Performance Indicators</h2> <button class="export-btn"
                            onclick="exportData('kpi')" data-tooltip="Export as CSV"> 📥 </button>
                    </div>


                    <div class="date-inputs" style="margin-bottom: 15px;">
                        <input type="date" id="kpi-start-date" style="margin-right: 8px;">
                        <input type="date" id="kpi-end-date" style="margin-right: 8px;">
                        <button class="btn" onclick="filterKPIsByDate()"
                            style="margin-top: 0; padding: 6px 15px;">Filter by Date</button>
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
                    <div class="card-title-wrapper">
                        <h2 class="card-title">Disruption Events</h2>
                        <button class="export-btn" onclick="exportDisruptionsPDF()" data-tooltip="Export as PDF">
                            📥
                        </button>
                    </div>

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
        <div id="add-location-modal"
            style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center;">
            <div style="background: white; padding: 30px; border-radius: 8px; width: 400px; max-width: 90%;">
                <h3 style="margin-top: 0;">Add New Location</h3>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">City:</label>
                    <input type="text" id="new-city" placeholder="e.g., London"
                        style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Country:</label>
                    <input type="text" id="new-country" placeholder="e.g., United Kingdom"
                        style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px;">Continent:</label>
                    <select id="new-continent"
                        style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
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
                    <button class="btn" onclick="closeAddLocationForm()"
                        style="flex: 1; background: #666;">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Supply Chain Network Modal -->
        <div id="network-modal"
            style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 3000; justify-content: center; align-items: center;">
            <div
                style="background: white; padding: 20px; border-radius: 12px; width: 90%; max-width: 1200px; height: 80vh; display: flex; flex-direction: column;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 style="margin: 0; color: #1f2937;">Supply Chain Network</h2>
                    <button onclick="closeNetworkModal()"
                        style="background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">Close</button>
                </div>

                <div id="network-stats"
                    style="margin-bottom: 10px; padding: 10px; background: #f3f4f6; border-radius: 6px; font-size: 13px;">
                    Loading network data...
                </div>

                <div id="supply-chain-network"
                    style="flex: 1; border: 2px solid #e5e7eb; border-radius: 8px; background: #fafbfc; min-height: 500px; width: 100%;">
                </div>

                <div style="margin-top: 10px; font-size: 12px; color: #666; text-align: center;">
                    🟢 Current Company | 🔵 Direct Suppliers | 🟦 Suppliers' Suppliers | 🟠 Direct Customers | 🟡
                    Customers' Customers
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
        searchInput.addEventListener("input", function () {
            var searchTerm = searchInput.value.trim();
            if (searchTerm.length < 1) {
                searchResults.classList.remove("show");
                return;
            }


            var xhttp = new XMLHttpRequest();
            xhttp.onload = function () {
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
                        item.onclick = function () {
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


        document.addEventListener("click", function (event) {
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
            addressInput.addEventListener("input", function () {
                var searchTerm = addressInput.value.trim();

                if (searchTerm.length < 1) {
                    locationResults.classList.remove("show");
                    locationIdInput.value = "";
                    return;
                }

                var xhttp = new XMLHttpRequest();
                xhttp.onload = function () {
                    if (this.readyState == 4 && this.status == 200) {
                        var data = JSON.parse(this.responseText);
                        locationResults.innerHTML = "";

                        if (data.length == 0) {
                            locationResults.innerHTML = '<div class="no-results">No locations found - click "+ Add New Location" to create one</div>';
                            locationResults.classList.add("show");
                            locationIdInput.value = "";
                            return;
                        }

                        data.forEach(function (loc) {
                            var item = document.createElement("div");
                            item.className = "search-result-item";

                            // Build display string with city if it exists
                            var displayParts = [];
                            if (loc.City) displayParts.push(loc.City);
                            displayParts.push(loc.CountryName);
                            displayParts.push("(" + loc.ContinentName + ")");

                            item.textContent = displayParts.join(", ");
                            item.setAttribute("data-id", loc.LocationID);

                            // Build value for input (for display when selected)
                            var valueParts = [];
                            if (loc.City) valueParts.push(loc.City);
                            valueParts.push(loc.CountryName);
                            valueParts.push(loc.ContinentName);
                            item.setAttribute("data-display", valueParts.join(", "));

                            item.onclick = function () {
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


            document.addEventListener("click", function (event) {
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

            // Clear all fields when opening the modal
            document.getElementById("new-city").value = "";
            document.getElementById("new-country").value = "";
            document.getElementById("new-continent").value = "";

            document.getElementById("add-location-modal").style.display = "flex";
        }

        function closeAddLocationForm() {
            document.getElementById("add-location-modal").style.display = "none";
        }

        // Helper function to capitalize each word
        function capitalizeWords(str) {
            return str.split(' ').map(function (word) {
                return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
            }).join(' ');
        }


        function saveNewLocation() {
            var city = document.getElementById("new-city").value.trim();
            var country = document.getElementById("new-country").value.trim();
            var continent = document.getElementById("new-continent").value;

            // Auto-capitalize city and country
            city = capitalizeWords(city);
            country = capitalizeWords(country);

            // Update the input fields to show capitalized version
            document.getElementById("new-city").value = city;
            document.getElementById("new-country").value = country;

            if (!city || !country || !continent) {
                alert("Please fill in all fields");
                return;
            }

            // Validate country first
            var countryCheckXhr = new XMLHttpRequest();
            countryCheckXhr.onload = function () {
                if (this.status === 200) {
                    try {
                        var countries = JSON.parse(this.responseText);

                        if (countries.length === 0 || countries.status === 404) {
                            alert("Country '" + country + "' not found. Please check spelling.");
                            return;
                        }

                        var countryData = countries[0];
                        var actualContinent = countryData.continents ? countryData.continents[0] : null;
                        var countryCode = countryData.cca2;

                        if (!actualContinent) {
                            alert("Could not verify continent for this country.");
                            return;
                        }

                        // Check if continent matches
                        if (actualContinent !== continent) {
                            alert("Error: '" + country + "' is in " + actualContinent + ", not " + continent + ".\n\nPlease select the correct continent.");
                            return;
                        }

                        // Country validated, now validate city
                        validateCity(city, country, continent, countryCode);

                    } catch (e) {
                        alert("Could not verify country. Please check spelling.");
                        console.error(e);
                    }
                } else {
                    alert("Could not verify country.");
                }
            };

            countryCheckXhr.open("GET", "https://restcountries.com/v3.1/name/" + encodeURIComponent(country) + "?fullText=true", true);
            countryCheckXhr.send();
        }

        function validateCity(city, country, continent, countryCode) {
            var cityCheckXhr = new XMLHttpRequest();
            cityCheckXhr.onload = function () {
                if (this.status === 200) {
                    try {
                        var cityData = JSON.parse(this.responseText);

                        // Check for errors
                        if (cityData.error) {
                            alert("Could not validate city. Saving anyway...");
                            saveLocationToDatabase(city, country, continent);
                            return;
                        }

                        if (cityData.status && cityData.status.message) {
                            alert("City validation error: " + cityData.status.message + "\n\nSaving anyway...");
                            saveLocationToDatabase(city, country, continent);
                            return;
                        }

                        // Check if city was found
                        if (!cityData.geonames || cityData.geonames.length === 0) {
                            if (!confirm("Warning: City '" + city + "' not found in " + country + ".\n\nContinue anyway?")) {
                                return;
                            }
                        } else {
                            // City found - offer to use official spelling
                            var foundCity = cityData.geonames[0];
                            if (foundCity.name.toLowerCase() !== city.toLowerCase()) {
                                if (confirm("Found city '" + foundCity.name + "' in " + foundCity.countryName + ".\n\nUse official spelling '" + foundCity.name + "'?")) {
                                    city = foundCity.name;
                                    document.getElementById("new-city").value = foundCity.name;
                                }
                            }
                        }

                        saveLocationToDatabase(city, country, continent);

                    } catch (e) {
                        console.error("Parse error:", e);
                        alert("Error validating city. Saving anyway...");
                        saveLocationToDatabase(city, country, continent);
                    }
                } else {
                    alert("Could not validate city. Saving anyway...");
                    saveLocationToDatabase(city, country, continent);
                }
            };

            // Use PHP proxy
            cityCheckXhr.open("GET", "company.php?action=validate_city&city=" + encodeURIComponent(city) + "&country_code=" + countryCode, true);
            cityCheckXhr.send();
        }

        function saveLocationToDatabase(city, country, continent) {
            var formData = new FormData();
            formData.append("action", "add_location");
            formData.append("city", city);
            formData.append("country", country);
            formData.append("continent", continent);

            var xhttp = new XMLHttpRequest();
            xhttp.onload = function () {
                if (this.status === 200) {
                    try {
                        var response = JSON.parse(this.responseText);

                        if (response.success) {
                            // Show different message based on whether it's new or existing
                            if (response.is_new === true) {
                                alert("✓ Location added successfully!");
                            } else {
                                alert("This location already exists. Try searching for it instead.");
                            }
                            locationIdInput.value = response.location_id;
                            addressInput.value = city + ", " + country + ", " + continent;
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
        }


        // LOAD COMPANY INFORMATION
        function loadCompanyInfo(companyId) {
            currentCompanyId = companyId;
            var xhttp = new XMLHttpRequest();
            xhttp.onload = function () {
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
                    //  Auto-resize textareas to fit content
                    setTimeout(function () {
                        document.querySelectorAll('textarea').forEach(function (textarea) {
                            textarea.style.height = 'auto';
                            textarea.style.height = (textarea.scrollHeight + 4) + 'px';
                        });
                    }, 200);
                    //  AUTO-SET YEAR-TO-DATE (YTD) dates
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
            xhttp.onload = function () {
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


        // UPDATED: LOAD TRANSACTION DATA WITH EDIT CAPABILITY
        function loadTransactionData(companyId, startDate, endDate) {
            if (!companyId) {
                return;
            }


            var url = "company.php?action=transactions&company=" + companyId;
            if (startDate && endDate) {
                url += "&start_date=" + startDate + "&end_date=" + endDate;
            }


            var xhttp = new XMLHttpRequest();
            xhttp.onload = function () {
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
                            data.shipping.forEach(function (item) {
                                var div = document.createElement("div");
                                div.className = "transaction-item";

                                // Check if editable (ActualDate is NULL)
                                var isEditable = !item.ActualDate && canEdit;

                                var html = "<strong>Shipment #" + item.ShipmentID + "</strong><br>" +
                                    "Product: " + item.Product + "<br>" +
                                    "From: " + item.SourceCompany + " &rarr; " + item.DestinationCompany + "<br>" +
                                    "Promised: " + item.PromisedDate + "<br>";

                                if (isEditable) {
                                    // Editable fields - REMOVED date input, only quantity
                                    html += "Quantity: <input type='number' id='qty-" + item.ShipmentID + "' " +
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
                            data.receiving.forEach(function (item) {
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
                            data.adjustments.forEach(function (item) {
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
            xhttp.onload = function () {
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
                                x: data.financialTrend.map(function (item) {
                                    return item.quarter;
                                }),
                                y: data.financialTrend.map(function (item) {
                                    return item.score;
                                }),
                                type: 'scatter',
                                mode: 'lines+markers',
                                line: {
                                    color: '#22c55e',
                                    width: 2
                                },
                                marker: {
                                    size: 6
                                }
                            };

                            var financialLayout = {
                                margin: {
                                    t: 10,
                                    r: 10,
                                    b: 60,
                                    l: 40
                                }, 
                                xaxis: {
                                    title: 'Quarter',
                                    tickangle: -45,
                                    tickfont: {
                                        size: 9
                                    }, 
                                    automargin: true 
                                },
                                yaxis: {
                                    title: 'Health Score',
                                    range: [0, 100]
                                },
                                height: 220 
                            };

                            Plotly.newPlot('financial-graph', [financialTrace], financialLayout, {
                                displayModeBar: false
                            });
                        } else {
                            document.getElementById("financial-graph").innerHTML = '<p style="color: #999; font-size: 12px; text-align: center;">No financial data available</p>';
                        }

                        var eventList = document.getElementById("event-list");
                        if (data.events && data.events.length > 0) {
                            eventList.innerHTML = "";
                            data.events.forEach(function (event) {
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


        function updateShipment(shipmentId) {
            if (!canEdit) {
                alert("You don't have permission to update shipments.");
                return;
            }

            var quantity = document.getElementById("qty-" + shipmentId).value;

            if (!quantity || quantity <= 0) {
                alert("Please enter a valid quantity.");
                return;
            }

            var formData = new FormData();
            formData.append("action", "update_shipment");
            formData.append("shipment_id", shipmentId);
            formData.append("quantity", quantity);

            var xhttp = new XMLHttpRequest();
            xhttp.onload = function () {
                console.log("Update shipment response:", this.responseText); 
                if (this.status === 200) {
                    try {
                        var response = JSON.parse(this.responseText);
                        if (response.success) {
                            alert("Shipment quantity updated successfully!");
                            var startDate = document.getElementById("transaction-start-date").value;
                            var endDate = document.getElementById("transaction-end-date").value;
                            loadTransactionData(currentCompanyId, startDate, endDate);
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
            xhttp.onload = function () {
                console.log("Status:", this.status); 
                console.log("Response:", this.responseText); 

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
                        console.log("Parse error:", e); 
                        console.log(this.responseText);
                    }
                }
            };
            xhttp.open("POST", "company.php", true);
            xhttp.send(formData);
        }

        (function () {
            // Replace current history entry
            if (window.history && window.history.pushState) {
                // Push current state multiple times to fill history
                for (let i = 0; i < 10; i++) {
                    window.history.pushState(null, null, window.location.href);
                }

                // When user presses back, push forward again
                window.onpopstate = function () {
                    window.history.pushState(null, null, window.location.href);
                };
            }
        })();


        window.addEventListener('DOMContentLoaded', function () {
            // Search for Abbott-Munoz
            var xhttp = new XMLHttpRequest();
            xhttp.onload = function () {
                if (this.readyState == 4 && this.status == 200) {
                    var data = JSON.parse(this.responseText);
                    if (data && data.length > 0) {
                        // Since the search returns companies ordered by CompanyName,
                        // the first result is already alphabetically first
                        var firstCompany = data[0];

                        // Set search box text
                        searchInput.value = firstCompany.CompanyName;
                        // Load company data
                        loadCompanyInfo(firstCompany.CompanyID);
                    }
                }
            };
            // Search with empty string or 'A' to get companies starting with A
            xhttp.open("GET", "company.php?action=search&query=A", true);
            xhttp.send();
        });


        // Export data to CSV
        function exportData(type) {
            if (!currentCompanyId) {
                alert("Please select a company first.");
                return;
            }

            var url = "company.php?action=export_csv&type=" + type + "&company=" + currentCompanyId;

            // Add date filters for transactions and KPI
            if (type === 'transactions') {
                var startDate = document.getElementById("transaction-start-date").value;
                var endDate = document.getElementById("transaction-end-date").value;
                if (startDate && endDate) {
                    url += "&start_date=" + startDate + "&end_date=" + endDate;
                }
            } else if (type === 'kpi') {
                var startDate = document.getElementById("kpi-start-date").value;
                var endDate = document.getElementById("kpi-end-date").value;
                if (startDate && endDate) {
                    url += "&start_date=" + startDate + "&end_date=" + endDate;
                }
            }

            // Trigger download
            window.location.href = url;
        }

        // Export Disruptions to PDF (via Print)
        function exportDisruptionsPDF() {
            if (!currentCompanyId) {
                alert("Please select a company first.");
                return;
            }

            // Get company name
            var companyName = document.querySelector(".page-title").textContent;

            // Get all events
            var eventList = document.getElementById("event-list");
            var events = eventList.querySelectorAll('.event-list-item');

            if (events.length === 0) {
                alert("No disruption events to export.");
                return;
            }

            // Build print-friendly HTML
            var printContent = '<!DOCTYPE html><html><head><meta charset="utf-8">';
            printContent += '<title>Disruption Events - ' + companyName + '</title>';
            printContent += '<style>';
            printContent += 'body { font-family: Arial, sans-serif; padding: 40px; }';
            printContent += 'h1 { color: #1f2937; border-bottom: 3px solid #22c55e; padding-bottom: 15px; }';
            printContent += '.meta { color: #666; margin-bottom: 30px; }';
            printContent += '.event { margin: 20px 0; padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; page-break-inside: avoid; }';
            printContent += '.event strong { display: block; margin-bottom: 8px; font-size: 16px; }';
            printContent += '</style></head><body>';
            printContent += '<h1>Disruption Events - ' + companyName + '</h1>';
            printContent += '<div class="meta">Generated: ' + new Date().toLocaleString() + '</div>';

            events.forEach(function (event) {
                printContent += '<div class="event">' + event.innerHTML + '</div>';
            });

            printContent += '</body></html>';

            // Open print window
            var printWindow = window.open('', '_blank', 'width=800,height=600');
            printWindow.document.open();
            printWindow.document.write(printContent);
            printWindow.document.close();

            // Trigger print after content loads
            printWindow.onload = function () {
                printWindow.focus();
                printWindow.print();
            };
        }

        // ============================================================================
        // SUPPLY CHAIN NETWORK MODAL
        // ============================================================================

        var networkInstance = null;

        function openNetworkModal() {
            if (!currentCompanyId) {
                alert("Please select a company first.");
                return;
            }

            document.getElementById("network-modal").style.display = "flex";
            loadSupplyChainNetwork(currentCompanyId);
        }

        function closeNetworkModal() {
            document.getElementById("network-modal").style.display = "none";
            if (networkInstance) {
                networkInstance.destroy();
                networkInstance = null;
            }
        }

        function loadSupplyChainNetwork(companyId) {
            fetch(`company.php?action=supply_chain_network&company=${companyId}`)
                .then(response => response.text()) 
                .then(text => {
                    console.log('RAW RESPONSE:', text); // ← Shows what server sent
                    const data = JSON.parse(text);
                    displaySupplyChainNetwork(data);
                    document.getElementById('network-stats').innerHTML =
                        `<strong>${data.stats.suppliers}</strong> Suppliers (Upstream) • ` +
                        `<strong>${data.stats.customers}</strong> Customers (Downstream) • ` +
                        `<strong>${data.nodes.length}</strong> Total Companies`;
                })
                .catch(error => {
                    console.error('Error loading network:', error);
                    document.getElementById('network-stats').innerHTML = 'Error loading network data';
                });
        }

        function displaySupplyChainNetwork(data) {
            const container = document.getElementById('supply-chain-network');

            const networkData = {
                nodes: new vis.DataSet(data.nodes),
                edges: new vis.DataSet(data.edges)
            };

            const options = {
                nodes: {
                    font: {
                        size: 14,
                        color: '#333'
                    },
                    borderWidth: 2,
                    shadow: true
                },
                edges: {
                    smooth: {
                        enabled: true,
                        type: 'continuous'
                    },
                    arrows: {
                        to: {
                            enabled: true,
                            scaleFactor: 0.8
                        }
                    }
                },
                physics: {
                    enabled: true,
                    stabilization: {
                        iterations: 100
                    },
                    barnesHut: {
                        gravitationalConstant: -3000,
                        centralGravity: 0.3,
                        springLength: 150
                    }
                },
                interaction: {
                    hover: true,
                    zoomView: true,
                    dragView: true,
                    navigationButtons: true
                }
            };

            if (networkInstance) {
                networkInstance.destroy();
            }

            networkInstance = new vis.Network(container, networkData, options);

            networkInstance.on('click', function (params) {
                if (params.nodes.length > 0) {
                    const nodeId = params.nodes[0];
                    const node = data.nodes.find(n => n.id === nodeId);
                    if (node && nodeId != currentCompanyId) {
                        if (confirm(`Load ${node.label}?`)) {
                            closeNetworkModal();
                            loadCompanyInfo(nodeId);
                            searchInput.value = node.label;
                        }
                    }
                }
            });

            networkInstance.on('stabilizationIterationsDone', function () {
                networkInstance.setOptions({
                    physics: false
                });
            });
        }

        // Close modal when clicking outside
        document.addEventListener('click', function (e) {
            const modal = document.getElementById('network-modal');
            if (e.target === modal) {
                closeNetworkModal();
            }
        });
    </script>
</body>

</html>