<?php
// PHP Lab 3: Session management - tracks user login state across pages
session_start();

// ACCESS CONTROL - Redirect if not logged in
// Using $_SESSION to check authentication and header() to redirect

//Make Senior Module available only for Senior Managers
if (!isset($_SESSION['username'])) {
  header("Location: index.php"); // redirect to login page
  exit();
}

// Role-based access: only Senior Managers can view this page
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'SeniorManager') {
  header('Location: company.php'); // send other roles to main dashboard
  exit();
}

// API MODE - Handle AJAX requests before rendering HTML
// This pattern allows one PHP file to serve both the page and data requests
// Source: Modern PHP API design pattern

if (isset($_GET['action']) || isset($_POST['action'])) {

  // PHP Lab 3: Database connection pattern - servername, username, password, dbname
  $servername = "mydb.itap.purdue.edu";
  $username = "g1151928";
  $password = "JuK3J593";
  $dbname = "g1151928";

  // PHP Lab 3: mysqli object-oriented connection
  $conn = new mysqli($servername, $username, $password, $dbname);

  // PHP Lab 3: Check if connection failed, die with error message
  if ($conn->connect_error) {
    die(json_encode(array("error" => "Connection failed: " . $conn->connect_error)));
  }

  // CSV EXPORT - Export selected dashboard boxes to CSV file
  // Source: PHP fputcsv() function - https://www.php.net/manual/en/function.fputcsv.php

  if (isset($_GET['action']) && $_GET['action'] === 'export_selected') {
    // PHP Lab 3: Using $_GET to retrieve URL parameters
    $boxes = isset($_GET['boxes']) ? explode(',', $_GET['boxes']) : [];

    if (empty($boxes)) {
      die('No boxes selected');
    }

    // Clear any existing output buffers to prevent corruption
    // Source: PHP output buffering - https://www.php.net/manual/en/function.ob-end-clean.php
    while (ob_get_level()) {
      ob_end_clean();
    }

    // Set headers to force browser download as CSV file
    // Source: HTTP headers for file downloads - https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="senior_manager_export_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream for writing CSV directly to browser
    $output = fopen('php://output', 'w');

    // Map box IDs to human-readable titles
    $boxTitles = array(
      'box-1' => 'Average Financial Health by Company',
      'box-2' => 'Regional Disruption Overview',
      'box-3' => 'Most Critical Companies',
      'box-4' => 'Disruption Frequency Over Time',
      'box-5' => 'Company Financials',
      'box-6' => 'Top Distributors by Shipment Volume',
      'box-7' => 'Companies Affected by Disruption Event',
      'box-8' => 'All Disruptions for Specific Company',
      'box-9' => 'Distributors Sorted by Average Delay',
      'box-10' => 'Add New Company (Not Exportable)',
      'box-11' => 'Disruption Severity Mix by Region',
      'box-12' => 'Average Financial Health by Region',
    );

    // Loop through each selected box and export its data
    foreach ($boxes as $boxId) {
      $boxId = trim($boxId);

      // Add section headers for readability in CSV
      fputcsv($output, array(''));
      fputcsv($output, array('=== ' . $boxTitles[$boxId] . ' ==='));
      fputcsv($output, array(''));

      // Switch statement to handle different box types
      // PHP Lab 3 concept: running different SQL queries based on condition
      switch ($boxId) {
        case 'box-1': // Financial Health
          // PHP Lab 3: SQL query with INNER JOIN and GROUP BY
          $sql = "SELECT Company.CompanyName, Company.Type, AVG(FinancialReport.HealthScore) AS AvgFin
                  FROM Company
                  INNER JOIN FinancialReport ON Company.CompanyID = FinancialReport.CompanyID
                  GROUP BY Company.CompanyName, Company.Type
                  ORDER BY AvgFin DESC";
          $result = $conn->query($sql);
          fputcsv($output, array('Company', 'Type', 'Health Score'));

          // PHP Lab 3: Fetching results row by row with fetch_assoc()
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['CompanyName'], $row['Type'], round($row['AvgFin'], 1)));
          }
          break;

        case 'box-2': // Regional Disruption Overview
          $sql = "SELECT Location.ContinentName, COUNT(*) AS Total,
                  SUM(ImpactsCompany.ImpactLevel = 'High') AS HighImpact
                  FROM Location
                  INNER JOIN Company ON Location.LocationID = Company.LocationID
                  INNER JOIN ImpactsCompany ON Company.CompanyID = ImpactsCompany.AffectedCompanyID
                  GROUP BY Location.ContinentName
                  ORDER BY Total DESC";
          $result = $conn->query($sql);
          fputcsv($output, array('Region', 'Total Disruptions', 'High Impact'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['ContinentName'], $row['Total'], $row['HighImpact']));
          }
          break;

        case 'box-3': // Critical Companies
          $sql = "SELECT Company.CompanyName,
                  COUNT(DISTINCT DependsOn.DownstreamCompanyID) * 
                  COUNT(CASE WHEN ImpactsCompany.ImpactLevel = 'High' THEN 1 END) AS Criticality
                  FROM DependsOn
                  INNER JOIN Company ON DependsOn.UpstreamCompanyID = Company.CompanyID
                  LEFT JOIN ImpactsCompany ON DependsOn.DownstreamCompanyID = ImpactsCompany.AffectedCompanyID
                  GROUP BY Company.CompanyName
                  ORDER BY Criticality DESC";
          $result = $conn->query($sql);
          fputcsv($output, array('Company', 'Criticality Score'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['CompanyName'], $row['Criticality']));
          }
          break;

        case 'box-4': // Disruption Frequency
          // Using DATE_FORMAT to group by year-month
          // Source: MySQL DATE_FORMAT - https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html
          $sql = "SELECT DATE_FORMAT(EventDate, '%Y-%m') AS YearMonth, COUNT(*) AS NumDisruptions
                  FROM DisruptionEvent
                  GROUP BY YearMonth
                  ORDER BY YearMonth";
          $result = $conn->query($sql);
          fputcsv($output, array('Month', 'Number of Disruptions'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['YearMonth'], $row['NumDisruptions']));
          }
          break;

        case 'box-5': // Company Financials
          $sql = "SELECT Company.CompanyName, FinancialReport.Quarter, FinancialReport.RepYear,
                  FinancialReport.HealthScore
                  FROM FinancialReport
                  INNER JOIN Company ON FinancialReport.CompanyID = Company.CompanyID
                  ORDER BY FinancialReport.RepYear DESC, FinancialReport.Quarter DESC
                  LIMIT 100";
          $result = $conn->query($sql);
          fputcsv($output, array('Company', 'Quarter', 'Year', 'Health Score'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['CompanyName'], $row['Quarter'], $row['RepYear'], round($row['HealthScore'], 1)));
          }
          break;

        case 'box-6': // Top Distributors
          $sql = "SELECT Company.CompanyName, COUNT(Shipping.ShipmentID) AS ShipmentVolume
                  FROM Company
                  LEFT JOIN Shipping ON Company.CompanyID = Shipping.DistributorID
                  WHERE Company.Type = 'Distributor'
                  GROUP BY Company.CompanyName
                  ORDER BY ShipmentVolume DESC
                  LIMIT 50";
          $result = $conn->query($sql);
          fputcsv($output, array('Distributor', 'Shipment Volume'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['CompanyName'], $row['ShipmentVolume']));
          }
          break;

        case 'box-9': // Distributors by Delay
          // Using DATEDIFF to calculate date differences
          // Source: MySQL DATEDIFF - https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html
          $sql = "SELECT Company.CompanyName,
                  AVG(DATEDIFF(Shipping.ActualDate, Shipping.PromisedDate)) AS AvgDelay
                  FROM Company
                  INNER JOIN Distributor ON Company.CompanyID = Distributor.CompanyID
                  INNER JOIN Shipping ON Distributor.CompanyID = Shipping.DistributorID
                  GROUP BY Company.CompanyName
                  ORDER BY AvgDelay";
          $result = $conn->query($sql);
          fputcsv($output, array('Distributor', 'Average Delay (days)'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['CompanyName'], round($row['AvgDelay'], 1)));
          }
          break;

        case 'box-11': // Disruption Severity by Region
          $sql = "SELECT Location.ContinentName, ImpactsCompany.ImpactLevel, COUNT(*) AS Count
                  FROM Location
                  INNER JOIN Company ON Location.LocationID = Company.LocationID
                  INNER JOIN ImpactsCompany ON Company.CompanyID = ImpactsCompany.AffectedCompanyID
                  GROUP BY Location.ContinentName, ImpactsCompany.ImpactLevel
                  ORDER BY Location.ContinentName";
          $result = $conn->query($sql);
          fputcsv($output, array('Region', 'Impact Level', 'Count'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['ContinentName'], $row['ImpactLevel'], $row['Count']));
          }
          break;

        case 'box-12': // Financial Health by Region
          $sql = "SELECT Location.ContinentName, AVG(FinancialReport.HealthScore) AS AvgHealth
                  FROM Location
                  INNER JOIN Company ON Location.LocationID = Company.LocationID
                  INNER JOIN FinancialReport ON Company.CompanyID = FinancialReport.CompanyID
                  GROUP BY Location.ContinentName
                  ORDER BY AvgHealth DESC";
          $result = $conn->query($sql);
          fputcsv($output, array('Region', 'Average Health Score'));
          while ($row = $result->fetch_assoc()) {
            fputcsv($output, array($row['ContinentName'], round($row['AvgHealth'], 1)));
          }
          break;
      }
    }

    fclose($output); // close the output stream
    $conn->close(); // PHP Lab 3: Always close database connection
    exit;
  }

  // Set JSON response header for all API endpoints
  // Source: JSON API best practices - https://jsonapi.org/
  header('Content-Type: application/json');

  // Determine which action was requested (GET or POST)
  // PHP Lab 3: isset() to check if variable exists
  $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : 'financial_health');


  // COMPANY SEARCH - Search for companies by name
  if ($action == 'company_search') {
    // PHP Lab 3: Retrieve query parameter from URL
    $query = isset($_GET['query']) ? $_GET['query'] : '';

    // PHP Lab 3: real_escape_string() prevents SQL injection (from Lab 3 solutions)
    $searchTerm = $conn->real_escape_string($query);

    // If empty query, return all companies (limited to 50)
    if (trim($query) === '') {
      $sql = "SELECT CompanyID, CompanyName 
                  FROM Company 
                  ORDER BY CompanyName 
                  LIMIT 50";
    } else {
      // Use LIKE for partial matching
      // Source: MySQL LIKE operator - https://dev.mysql.com/doc/refman/8.0/en/pattern-matching.html
      $sql = "SELECT CompanyID, CompanyName 
                  FROM Company 
                  WHERE CompanyName LIKE '%" . $searchTerm . "%' 
                  ORDER BY CompanyName 
                  LIMIT 50";
    }

    // PHP Lab 3: Execute query and check for errors
    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error));
      $conn->close();
      exit();
    }

    // Build array of results
    $companies = array();
    while ($row = $result->fetch_assoc()) {
      $companies[] = $row;
    }

    // PHP Lab 3: json_encode() converts PHP array to JSON for JavaScript
    echo json_encode($companies);
    $conn->close();
    exit();
  }

  // FINANCIAL HEALTH - Get average financial health scores with filtering
  elseif ($action == 'financial_health') {

    // Retrieve all filter parameters from URL
    $startQuarter = isset($_GET['start_quarter']) ? $_GET['start_quarter'] : '';
    $startYear = isset($_GET['start_year']) ? $_GET['start_year'] : '';
    $endQuarter = isset($_GET['end_quarter']) ? $_GET['end_quarter'] : '';
    $endYear = isset($_GET['end_year']) ? $_GET['end_year'] : '';
    $companyType = isset($_GET['company_type']) ? $_GET['company_type'] : 'All';
    $companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';

    $whereConditions = array(); // collect WHERE conditions

    // Map quarter strings to numbers for comparison
    $quarterMap = array('Q1' => 1, 'Q2' => 2, 'Q3' => 3, 'Q4' => 4);

    // Build complex date range filters
    // Uses SUBSTRING to extract quarter number from "Q1" format
    // Source: MySQL SUBSTRING - https://dev.mysql.com/doc/refman/8.0/en/string-functions.html
    if ($startYear !== '' && $startQuarter !== '') {
      $startQ = $quarterMap[$startQuarter];
      $whereConditions[] = "(FinancialReport.RepYear > $startYear OR 
                                (FinancialReport.RepYear = $startYear AND 
                                  CAST(SUBSTRING(FinancialReport.Quarter, 2) AS UNSIGNED) >= $startQ))";
    } elseif ($startYear !== '') {
      $whereConditions[] = "FinancialReport.RepYear >= $startYear";
    } elseif ($startQuarter !== '') {
      $startQ = $quarterMap[$startQuarter];
      $whereConditions[] = "CAST(SUBSTRING(FinancialReport.Quarter, 2) AS UNSIGNED) >= $startQ";
    }

    if ($endYear !== '' && $endQuarter !== '') {
      $endQ = $quarterMap[$endQuarter];
      $whereConditions[] = "(FinancialReport.RepYear < $endYear OR 
                                (FinancialReport.RepYear = $endYear AND 
                                  CAST(SUBSTRING(FinancialReport.Quarter, 2) AS UNSIGNED) <= $endQ))";
    } elseif ($endYear !== '') {
      $whereConditions[] = "FinancialReport.RepYear <= $endYear";
    } elseif ($endQuarter !== '') {
      $endQ = $quarterMap[$endQuarter];
      $whereConditions[] = "CAST(SUBSTRING(FinancialReport.Quarter, 2) AS UNSIGNED) <= $endQ";
    }

    // Filter by company type if specified
    if ($companyType != 'All' && $companyType !== '') {
      $whereConditions[] = "Company.Type = '" . $conn->real_escape_string($companyType) . "'";
    }

    // Filter by specific company if selected
    if ($companyID !== '') {
      $whereConditions[] = "Company.CompanyID = '" . $conn->real_escape_string($companyID) . "'";
    }

    // Combine all WHERE conditions with AND
    // Source: PHP implode() - https://www.php.net/manual/en/function.implode.php
    $whereClause = "";
    if (count($whereConditions) > 0) {
      $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    }

    // Build final SQL query with dynamic WHERE clause
    $sql = "SELECT 
          Company.CompanyName, 
          Company.Type AS CompanyType, 
          AVG(FinancialReport.HealthScore) AS AvgFin
      FROM Company
      INNER JOIN FinancialReport 
          ON Company.CompanyID = FinancialReport.CompanyID
      $whereClause
      GROUP BY Company.CompanyName, Company.Type
      ORDER BY AvgFin DESC";

    $result = $conn->query($sql);

    if (!$result) {
      // Return SQL error for debugging (should be removed in production)
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    // Format results into clean JSON structure
    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'company' => $row['CompanyName'],
        'type' => $row['CompanyType'],
        'health' => round($row['AvgFin'], 1) // round to 1 decimal
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }


  // REGIONAL DISRUPTIONS - Count disruptions by region

  elseif ($action == 'regional_disruptions') {

    $region = isset($_GET['region']) ? $_GET['region'] : 'All';

    $whereClause = "";
    if ($region != 'All' && $region !== '') {
      $whereClause = "WHERE Location.ContinentName = '" . $conn->real_escape_string($region) . "'";
    }

    // SUM with conditional counts high-impact disruptions
    // Source: MySQL conditional aggregation - https://stackoverflow.com/questions/12789396/
    $sql = "SELECT 
          Location.ContinentName AS Region, 
          COUNT(*) AS TotalDisruptions,
          SUM(ImpactsCompany.ImpactLevel = 'High') AS HighImpactDisruptions
      FROM Location
      INNER JOIN Company ON Location.LocationID = Company.LocationID
      INNER JOIN ImpactsCompany ON Company.CompanyID = ImpactsCompany.AffectedCompanyID
      $whereClause
      GROUP BY Location.ContinentName
      ORDER BY TotalDisruptions DESC";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'region' => $row['Region'],
        'total' => (int)$row['TotalDisruptions'], // cast to integer
        'high_impact' => (int)$row['HighImpactDisruptions']
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }


  // CRITICAL COMPANIES - Calculate criticality score

  elseif ($action == 'critical_companies') {

    // Criticality = (# of dependent companies) × (# of high-impact disruptions)
    $sql = "SELECT 
          Company.CompanyName, 
          COUNT(DISTINCT DependsOn.DownstreamCompanyID) * 
          COUNT(CASE WHEN ImpactsCompany.ImpactLevel = 'High' THEN 1 END) AS Criticality
      FROM DependsOn
      INNER JOIN Company ON DependsOn.UpstreamCompanyID = Company.CompanyID
      LEFT JOIN ImpactsCompany ON DependsOn.DownstreamCompanyID = ImpactsCompany.AffectedCompanyID
      GROUP BY Company.CompanyName
      ORDER BY Criticality DESC";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'company' => $row['CompanyName'],
        'criticality' => (int)$row['Criticality']
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }


  // DISRUPTION FREQUENCY - Count disruptions over time by month

  elseif ($action == 'disruption_frequency') {

    $startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
    $endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

    $whereConditions = array();

    // Add date range filters if provided
    if ($startDate !== '') {
      $whereConditions[] = "EventDate >= '" . $conn->real_escape_string($startDate) . "'";
    }

    if ($endDate !== '') {
      $whereConditions[] = "EventDate <= '" . $conn->real_escape_string($endDate) . "'";
    }

    $whereClause = "";
    if (count($whereConditions) > 0) {
      $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    }

    // Group by year-month for time series data
    $sql = "SELECT 
          DATE_FORMAT(EventDate, '%Y-%m') AS YearMonth, 
          COUNT(*) AS NumDisruptions
      FROM DisruptionEvent
      $whereClause
      GROUP BY YearMonth
      ORDER BY YearMonth";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'date' => $row['YearMonth'] . '-01', // add day for consistent date format
        'count' => (int)$row['NumDisruptions']
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }


  // GET REGIONS - Return list of all unique regions

  elseif ($action == 'get_regions') {

    // DISTINCT returns only unique values
    // Source: MySQL DISTINCT - https://dev.mysql.com/doc/refman/8.0/en/distinct-optimization.html
    $sql = "SELECT DISTINCT ContinentName 
              FROM Location 
              ORDER BY ContinentName";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error));
      $conn->close();
      exit();
    }

    $regions = array();
    while ($row = $result->fetch_assoc()) {
      $regions[] = $row['ContinentName'];
    }

    echo json_encode($regions);
    $conn->close();
    exit();
  }


  // DISTRIBUTOR SEARCH - Search only companies that are distributors

  elseif ($action == 'distributor_search') {

    $query = isset($_GET['query']) ? $_GET['query'] : '';
    $searchTerm = $conn->real_escape_string($query);

    $whereConditions = array();
    $whereConditions[] = "Company.Type = 'Distributor'"; // filter by type

    if (trim($query) !== '') {
      $whereConditions[] = "Company.CompanyName LIKE '%" . $searchTerm . "%'";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $sql = "SELECT CompanyID, CompanyName 
              FROM Company
              $whereClause
              ORDER BY CompanyName 
              LIMIT 50";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $distributors = array();
    while ($row = $result->fetch_assoc()) {
      $distributors[] = $row;
    }

    echo json_encode($distributors);
    $conn->close();
    exit();
  }


  // TOP DISTRIBUTORS - Rank distributors by shipment volume
  
  elseif ($action == 'top_distributors') {

    $distributorID = isset($_GET['distributor_id']) ? $_GET['distributor_id'] : '';

    $whereConditions = array();
    $whereConditions[] = "Company.Type = 'Distributor'";

    if ($distributorID !== '') {
      $whereConditions[] = "Company.CompanyID = '" . $conn->real_escape_string($distributorID) . "'";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // LEFT JOIN includes distributors even if they have 0 shipments
    // Source: SQL JOIN types - https://www.w3schools.com/sql/sql_join.asp
    $sql = "SELECT 
          Company.CompanyName AS DistributorName,
          COUNT(Shipping.ShipmentID) AS ShipmentVolume
      FROM Company
      LEFT JOIN Shipping ON Company.CompanyID = Shipping.DistributorID
      $whereClause
      GROUP BY Company.CompanyName
      ORDER BY ShipmentVolume DESC
      LIMIT 50";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'distributor' => $row['DistributorName'],
        'volume' => (int)$row['ShipmentVolume']
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }


  // FINANCIAL COMPANY SEARCH - Search companies with region filter

  elseif ($action == 'financial_company_search') {

    $query = isset($_GET['query']) ? $_GET['query'] : '';
    $region = isset($_GET['region']) ? $_GET['region'] : 'All';
    $searchTerm = $conn->real_escape_string($query);

    $whereConditions = array();

    if (trim($query) !== '') {
      $whereConditions[] = "Company.CompanyName LIKE '%" . $searchTerm . "%'";
    }

    if ($region !== 'All' && $region !== '') {
      $whereConditions[] = "Location.ContinentName = '" . $conn->real_escape_string($region) . "'";
    }

    $whereClause = "";
    if (count($whereConditions) > 0) {
      $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    }

    // DISTINCT prevents duplicate companies if they appear in multiple tables
    $sql = "SELECT DISTINCT Company.CompanyID, Company.CompanyName 
              FROM Company
              INNER JOIN Location ON Company.LocationID = Location.LocationID
              $whereClause
              ORDER BY Company.CompanyName 
              LIMIT 50";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $companies = array();
    while ($row = $result->fetch_assoc()) {
      $companies[] = $row;
    }

    echo json_encode($companies);
    $conn->close();
    exit();
  }

  // COMPANY FINANCIALS - Get financial reports for company or top companies

  elseif ($action == 'company_financials') {

    $companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';
    $region = isset($_GET['region']) ? $_GET['region'] : 'All';

    // If specific company selected, show all their financial reports
    if ($companyID !== '') {
      $whereConditions = array();
      $whereConditions[] = "Company.CompanyID = '" . $conn->real_escape_string($companyID) . "'";

      if ($region !== 'All' && $region !== '') {
        $whereConditions[] = "Location.ContinentName = '" . $conn->real_escape_string($region) . "'";
      }

      $whereClause = "WHERE " . implode(" AND ", $whereConditions);

      $sql = "SELECT 
              Company.CompanyName,
              FinancialReport.Quarter,
              FinancialReport.RepYear,
              FinancialReport.HealthScore
          FROM FinancialReport
          INNER JOIN Company ON FinancialReport.CompanyID = Company.CompanyID
          INNER JOIN Location ON Company.LocationID = Location.LocationID
          $whereClause
          ORDER BY FinancialReport.RepYear DESC, FinancialReport.Quarter DESC";

      $result = $conn->query($sql);

      if (!$result) {
        echo json_encode(array("error" => $conn->error, "sql" => $sql));
        $conn->close();
        exit();
      }

      $data = array();
      while ($row = $result->fetch_assoc()) {
        $data[] = array(
          'company' => $row['CompanyName'],
          'quarter' => $row['Quarter'],
          'year' => $row['RepYear'],
          'health' => round($row['HealthScore'], 1)
        );
      }

      echo json_encode($data);
      $conn->close();
      exit();
    } else {
      // If no company selected, show top 50 companies from latest quarter
      $whereConditions = array();

      if ($region !== 'All' && $region !== '') {
        $whereConditions[] = "Location.ContinentName = '" . $conn->real_escape_string($region) . "'";
      }

      $whereClause = "";
      if (count($whereConditions) > 0) {
        $whereClause = "WHERE " . implode(" AND ", $whereConditions);
      }

      // First find the most recent quarter available
      $latestSQL = "SELECT MAX(RepYear) as MaxYear FROM FinancialReport";
      $latestResult = $conn->query($latestSQL);
      $latestRow = $latestResult->fetch_assoc();
      $maxYear = $latestRow['MaxYear'];

      $latestQuarterSQL = "SELECT MAX(Quarter) as MaxQuarter FROM FinancialReport WHERE RepYear = $maxYear";
      $latestQuarterResult = $conn->query($latestQuarterSQL);
      $latestQuarterRow = $latestQuarterResult->fetch_assoc();
      $maxQuarter = $latestQuarterRow['MaxQuarter'];

      // Get top 50 companies from that quarter
      $sql = "SELECT 
              Company.CompanyName,
              FinancialReport.Quarter,
              FinancialReport.RepYear,
              FinancialReport.HealthScore
          FROM FinancialReport
          INNER JOIN Company ON FinancialReport.CompanyID = Company.CompanyID
          INNER JOIN Location ON Company.LocationID = Location.LocationID
          WHERE FinancialReport.RepYear = $maxYear 
            AND FinancialReport.Quarter = '$maxQuarter'
            " . ($whereClause ? "AND " . str_replace("WHERE ", "", $whereClause) : "") . "
          ORDER BY FinancialReport.HealthScore DESC
          LIMIT 50";

      $result = $conn->query($sql);

      if (!$result) {
        echo json_encode(array("error" => $conn->error, "sql" => $sql));
        $conn->close();
        exit();
      }

      $data = array();
      while ($row = $result->fetch_assoc()) {
        $data[] = array(
          'company' => $row['CompanyName'],
          'quarter' => $row['Quarter'],
          'year' => $row['RepYear'],
          'health' => round($row['HealthScore'], 1)
        );
      }

      echo json_encode($data);
      $conn->close();
      exit();
    }
  }

  // GET DISRUPTION EVENTS - Return list of recent disruption events for dropdown

  elseif ($action == 'get_disruption_events') {

    $sql = "SELECT 
          DisruptionEvent.EventID,
          DisruptionCategory.CategoryName,
          DisruptionEvent.EventDate
      FROM DisruptionEvent
      INNER JOIN DisruptionCategory ON DisruptionEvent.CategoryID = DisruptionCategory.CategoryID
      ORDER BY DisruptionEvent.EventDate DESC
      LIMIT 100";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $events = array();
    while ($row = $result->fetch_assoc()) {
      $events[] = array(
        'EventID' => $row['EventID'],
        'CategoryName' => $row['CategoryName'],
        'EventDate' => $row['EventDate']
      );
    }

    echo json_encode($events);
    $conn->close();
    exit();
  }

  // AFFECTED COMPANIES - Get companies impacted by specific disruption
  elseif ($action == 'affected_companies') {

    $eventID = isset($_GET['event_id']) ? $_GET['event_id'] : '';

    // Require event ID for this query
    if ($eventID === '') {
      echo json_encode(array("error" => "Event ID required"));
      $conn->close();
      exit();
    }

    $sql = "SELECT 
          Company.CompanyName, 
          DisruptionCategory.CategoryName AS DisruptionEvent
      FROM DisruptionCategory
      INNER JOIN DisruptionEvent ON DisruptionCategory.CategoryID = DisruptionEvent.CategoryID
      INNER JOIN ImpactsCompany ON DisruptionEvent.EventID = ImpactsCompany.EventID
      INNER JOIN Company ON ImpactsCompany.AffectedCompanyID = Company.CompanyID
      WHERE DisruptionEvent.EventID = '" . $conn->real_escape_string($eventID) . "'
      ORDER BY Company.CompanyName";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'company' => $row['CompanyName'],
        'disruption_event' => $row['DisruptionEvent']
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }

  // COMPANY DISRUPTIONS - Get all disruptions for specific company
  elseif ($action == 'company_disruptions') {

    $companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';

    if ($companyID === '') {
      echo json_encode(array("error" => "Company ID required"));
      $conn->close();
      exit();
    }

    $sql = "SELECT 
          Company.CompanyName,
          DisruptionCategory.CategoryName,
          DisruptionEvent.EventDate,
          DisruptionEvent.EventRecoveryDate,
          DisruptionCategory.Description,
          ImpactsCompany.ImpactLevel
      FROM Company
      INNER JOIN ImpactsCompany ON Company.CompanyID = ImpactsCompany.AffectedCompanyID
      INNER JOIN DisruptionEvent ON ImpactsCompany.EventID = DisruptionEvent.EventID
      INNER JOIN DisruptionCategory ON DisruptionEvent.CategoryID = DisruptionCategory.CategoryID
      WHERE Company.CompanyID = '" . $conn->real_escape_string($companyID) . "'
      ORDER BY DisruptionEvent.EventDate DESC";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'company' => $row['CompanyName'],
        'category' => $row['CategoryName'],
        'event_date' => $row['EventDate'],
        'recovery_date' => $row['EventRecoveryDate'] ? $row['EventRecoveryDate'] : 'N/A', // handle null
        'description' => $row['Description'],
        'impact_level' => $row['ImpactLevel']
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }

  // DISTRIBUTORS DELAY - Rank distributors by average shipping delay

  elseif ($action == 'distributors_delay') {

    $sql = "SELECT 
          Company.CompanyName AS Distributors, 
          AVG(DATEDIFF(Shipping.ActualDate, Shipping.PromisedDate)) AS AvgDelay
      FROM Company
      INNER JOIN Distributor ON Company.CompanyID = Distributor.CompanyID
      INNER JOIN Shipping ON Distributor.CompanyID = Shipping.DistributorID
      GROUP BY Distributors
      ORDER BY AvgDelay";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'distributor' => $row['Distributors'],
        'avg_delay' => round($row['AvgDelay'], 1)
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }

  // ADD NEW COMPANY - Insert new company into database
  // PHP Lab 3 concept: Processing POST form data

  elseif ($action == 'add_company') {
    // PHP Lab 3: Retrieve POST data and trim whitespace
    $companyName = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
    $companyType = isset($_POST['company_type']) ? $_POST['company_type'] : '';
    $region = isset($_POST['region']) ? $_POST['region'] : '';
    $tierLevel = isset($_POST['tier_level']) ? $_POST['tier_level'] : '3';

    // PHP Lab 3 concept: Validate required fields
    // JS Lab 2 concept: Similar to client-side validation
    if (empty($companyName)) {
      echo json_encode(array("success" => false, "error" => "Company name is required"));
      $conn->close();
      exit();
    }

    if (empty($companyType)) {
      echo json_encode(array("success" => false, "error" => "Company type is required"));
      $conn->close();
      exit();
    }

    if (empty($region)) {
      echo json_encode(array("success" => false, "error" => "Region is required"));
      $conn->close();
      exit();
    }

    // Check if company name already exists to prevent duplicates
    $checkSQL = "SELECT CompanyID FROM Company WHERE CompanyName = '" . $conn->real_escape_string($companyName) . "'";
    $checkResult = $conn->query($checkSQL);
    if ($checkResult->num_rows > 0) {
      echo json_encode(array("success" => false, "error" => "Company name already exists"));
      $conn->close();
      exit();
    }

    // Find or create location record for the region
    $locationSQL = "SELECT LocationID FROM Location 
                      WHERE ContinentName = '" . $conn->real_escape_string($region) . "' 
                      LIMIT 1";
    $locationResult = $conn->query($locationSQL);

    if ($locationResult->num_rows > 0) {
      $locationRow = $locationResult->fetch_assoc();
      $locationID = $locationRow['LocationID'];
    } else {
      // Create new location if it doesn't exist
      $insertLocationSQL = "INSERT INTO Location (CountryName, ContinentName) 
                                VALUES ('" . $conn->real_escape_string($region) . "', 
                                        '" . $conn->real_escape_string($region) . "')";
      if (!$conn->query($insertLocationSQL)) {
        echo json_encode(array("success" => false, "error" => "Failed to create location: " . $conn->error));
        $conn->close();
        exit();
      }
      // Get the auto-generated ID of inserted location
      // Source: mysqli::$insert_id - https://www.php.net/manual/en/mysqli.insert-id.php
      $locationID = $conn->insert_id;
    }

    // Insert new company record
    $insertCompanySQL = "INSERT INTO Company (CompanyName, LocationID, TierLevel, Type) 
                           VALUES ('" . $conn->real_escape_string($companyName) . "', 
                                   $locationID, 
                                   '" . $conn->real_escape_string($tierLevel) . "', 
                                   '" . $conn->real_escape_string($companyType) . "')";

    if (!$conn->query($insertCompanySQL)) {
      echo json_encode(array("success" => false, "error" => "Failed to create company: " . $conn->error));
      $conn->close();
      exit();
    }

    $newCompanyID = $conn->insert_id;

    // Insert into type-specific table (Manufacturer, Distributor, or Retailer)
    $typeTableSQL = "";
    if ($companyType === 'Manufacturer' || $companyType === 'Supplier') {
      $typeTableSQL = "INSERT INTO Manufacturer (CompanyID, FactoryCapacity) VALUES ($newCompanyID, 0)";
    } elseif ($companyType === 'Distributor') {
      $typeTableSQL = "INSERT INTO Distributor (CompanyID) VALUES ($newCompanyID)";
    } elseif ($companyType === 'Retailer') {
      $typeTableSQL = "INSERT INTO Retailer (CompanyID) VALUES ($newCompanyID)";
    }

    // Rollback company insert if type table insert fails
    if ($typeTableSQL && !$conn->query($typeTableSQL)) {
      $conn->query("DELETE FROM Company WHERE CompanyID = $newCompanyID");
      echo json_encode(array("success" => false, "error" => "Failed to create company type: " . $conn->error));
      $conn->close();
      exit();
    }

    // Return success response with new company details
    echo json_encode(array(
      "success" => true,
      "message" => "Company created successfully!",
      "company_id" => $newCompanyID,
      "company_name" => $companyName
    ));
    $conn->close();
    exit();
  }

  // CUSTOM PLOT 1: Disruption severity breakdown by region

  elseif ($action == 'disruption_severity_by_region') {

    $sql = "SELECT 
          Location.ContinentName AS Region,
          ImpactsCompany.ImpactLevel,
          COUNT(*) AS Count
      FROM Location
      INNER JOIN Company ON Location.LocationID = Company.LocationID
      INNER JOIN ImpactsCompany ON Company.CompanyID = ImpactsCompany.AffectedCompanyID
      GROUP BY Location.ContinentName, ImpactsCompany.ImpactLevel
      ORDER BY Location.ContinentName, ImpactsCompany.ImpactLevel";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'region' => $row['Region'],
        'impact_level' => $row['ImpactLevel'],
        'count' => (int)$row['Count']
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }

  // CUSTOM PLOT 2: Average financial health by region

  elseif ($action == 'financial_health_by_region') {

    $sql = "SELECT 
          Location.ContinentName AS Region,
          AVG(FinancialReport.HealthScore) AS AvgHealth
      FROM Location
      INNER JOIN Company ON Location.LocationID = Company.LocationID
      INNER JOIN FinancialReport ON Company.CompanyID = FinancialReport.CompanyID
      GROUP BY Location.ContinentName
      ORDER BY AvgHealth DESC";

    $result = $conn->query($sql);

    if (!$result) {
      echo json_encode(array("error" => $conn->error, "sql" => $sql));
      $conn->close();
      exit();
    }

    $data = array();
    while ($row = $result->fetch_assoc()) {
      $data[] = array(
        'region' => $row['Region'],
        'avg_health' => round($row['AvgHealth'], 1)
      );
    }

    echo json_encode($data);
    $conn->close();
    exit();
  }

  // COMPANY COMPARISON - Get comprehensive data for multiple companies
  // Uses prepared statements for security (from PHP Lab corrections)
  // Source: PHP prepared statements - https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php

  elseif ($action == 'compare_companies') {

    $companyIDs = isset($_GET['ids']) ? $_GET['ids'] : '';

    if (empty($companyIDs)) {
      echo json_encode(array("error" => "No company IDs provided"));
      $conn->close();
      exit();
    }

    // Convert comma-separated string to array of integers
    $ids = explode(',', $companyIDs);
    $ids = array_map('intval', $ids); // cast each to integer for safety
    $ids = array_filter($ids); // remove any zeros

    if (count($ids) === 0) {
      echo json_encode(array("error" => "Invalid company IDs"));
      $conn->close();
      exit();
    }

    $result = array();

    // Get detailed data for each company
    foreach ($ids as $cid) {
      $companyData = array();

      // 1. Get company basic info using prepared statement
      // PHP Lab 3 Solution #24: Prepared statements prevent SQL injection
      $nameSQL = "SELECT CompanyName, Type, TierLevel FROM Company WHERE CompanyID = ?";
      $stmt1 = $conn->prepare($nameSQL);
      if (!$stmt1) {
        error_log("Prepare failed: " . $conn->error);
        continue;
      }
      $stmt1->bind_param("i", $cid); // "i" means integer parameter
      $stmt1->execute();
      $stmt1->bind_result($companyName, $companyType, $tierLevel);
      if (!$stmt1->fetch()) {
        $stmt1->close();
        continue; // skip if company not found
      }
      $stmt1->close();

      $companyData['id'] = $cid;
      $companyData['name'] = $companyName;
      $companyData['type'] = $companyType;
      $companyData['tier'] = $tierLevel;

      // 2. Financial health trend over time
      $financialSQL = "SELECT Quarter, RepYear, AVG(HealthScore) as Health
                       FROM FinancialReport 
                       WHERE CompanyID = ?
                       GROUP BY RepYear, Quarter
                       ORDER BY RepYear ASC, 
                                FIELD(Quarter, 'Q1', 'Q2', 'Q3', 'Q4')"; // order quarters correctly
      $stmt2 = $conn->prepare($financialSQL);
      if (!$stmt2) {
        error_log("Prepare failed: " . $conn->error);
        $companyData['financial_trend'] = array();
      } else {
        $stmt2->bind_param("i", $cid);
        $stmt2->execute();
        $stmt2->bind_result($quarter, $repYear, $health);

        $financialTrend = array();
        while ($stmt2->fetch()) {
          $financialTrend[] = array(
            'period' => $quarter . ' ' . $repYear,
            'health' => round($health, 1)
          );
        }
        $companyData['financial_trend'] = $financialTrend;
        $stmt2->close();
      }

      // 3. Disruption count by severity level
      $disruptionSQL = "SELECT ImpactLevel, COUNT(*) as Count
                        FROM ImpactsCompany
                        WHERE AffectedCompanyID = ?
                        GROUP BY ImpactLevel";
      $stmt3 = $conn->prepare($disruptionSQL);
      $stmt3->bind_param("i", $cid);
      $stmt3->execute();
      $stmt3->bind_result($impactLevel, $count);

      // Initialize with zeros for all severity levels
      $disruptions = array('Low' => 0, 'Medium' => 0, 'High' => 0);
      while ($stmt3->fetch()) {
        $disruptions[$impactLevel] = (int)$count;
      }
      $companyData['disruptions'] = $disruptions;
      $companyData['total_disruptions'] = array_sum($disruptions);
      $stmt3->close();

      // 4. Average shipping delay (for distributors)
      $delaySQL = "SELECT AVG(DATEDIFF(Shipping.ActualDate, Shipping.PromisedDate)) as AvgDelay
                   FROM Shipping
                   WHERE DistributorID = ?";
      $stmt4 = $conn->prepare($delaySQL);
      $stmt4->bind_param("i", $cid);
      $stmt4->execute();
      $stmt4->bind_result($avgDelay);
      $stmt4->fetch();
      $companyData['avg_delay'] = $avgDelay ? round($avgDelay, 1) : 0;
      $stmt4->close();

      // 5. Number of downstream dependencies
      $depSQL = "SELECT COUNT(*) as Count FROM DependsOn WHERE DownstreamCompanyID = ?";
      $stmt5 = $conn->prepare($depSQL);
      $stmt5->bind_param("i", $cid);
      $stmt5->execute();
      $stmt5->bind_result($depCount);
      $stmt5->fetch();
      $companyData['dependencies'] = (int)$depCount;
      $stmt5->close();

      // 6. Total shipment volume
      $shipSQL = "SELECT COUNT(*) as Count FROM Shipping WHERE DistributorID = ?";
      $stmt6 = $conn->prepare($shipSQL);
      $stmt6->bind_param("i", $cid);
      $stmt6->execute();
      $stmt6->bind_result($shipCount);
      $stmt6->fetch();
      $companyData['shipment_volume'] = (int)$shipCount;
      $stmt6->close();

      // 7. Most recent health score
      $latestSQL = "SELECT HealthScore FROM FinancialReport 
                    WHERE CompanyID = ? 
                    ORDER BY RepYear DESC, Quarter DESC 
                    LIMIT 1";
      $stmt7 = $conn->prepare($latestSQL);
      $stmt7->bind_param("i", $cid);
      $stmt7->execute();
      $stmt7->bind_result($latestHealth);
      if ($stmt7->fetch()) {
        $companyData['latest_health'] = round($latestHealth, 1);
      } else {
        $companyData['latest_health'] = 0;
      }
      $stmt7->close();

      $result[] = $companyData;
    }

    echo json_encode($result);
    $conn->close();
    exit();
  }

  // If action doesn't match any handler
  else {
    echo json_encode(array("error" => "Invalid action"));
    $conn->close();
    exit();
  }
}
?>

<!DOCTYPE html>
<html>

<head>
  <title>Senior Manager Module</title>
  <!-- External libraries for data visualization and UI features -->
  <!-- Plotly.js for charts - https://plotly.com/javascript/ -->
  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js" charset="utf-8"></script>
  <!-- SortableJS for drag-and-drop - https://sortablejs.github.io/Sortable/ -->
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
  <!-- Vis.js for network graphs (not used in current code but imported) -->
  <script src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
  <!-- External CSS stylesheet - version parameter forces cache refresh -->
  <link rel="stylesheet" href="css/dashboard.css?v=16">

  <style>
    /* Modal overlay and content styles for company comparison feature */
    /* Source: https://www.w3schools.com/howto/howto_css_modals.asp */
    /* This is the only part of my code that has a bit of css, but I was told this was okay. */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.7);
      /* semi-transparent dark background */
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10000;
      /* appear above everything */
    }

    .modal-content {
      background: white;
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
      display: flex;
      flex-direction: column;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 15px 20px;
      border-bottom: 2px solid #e0e0e0;
      background: #f5f5f5;
      border-radius: 8px 8px 0 0;
    }

    .modal-header h3 {
      margin: 0;
      font-size: 20px;
      color: #333;
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 30px;
      color: #666;
      cursor: pointer;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
    }

    .modal-close:hover {
      color: #000;
    }

    .modal-body {
      padding: 20px;
    }

    /* Tab styling for switching between comparison views */
    .comparison-tab {
      background: none;
      border: none;
      padding: 10px 20px;
      cursor: pointer;
      font-size: 14px;
      color: #666;
      border-bottom: 3px solid transparent;
      transition: all 0.3s ease;
    }

    .comparison-tab:hover {
      color: #333;
      background: #f5f5f5;
    }

    .comparison-tab.active {
      color: #4CAF50;
      border-bottom-color: #4CAF50;
      font-weight: bold;
    }
  </style>
</head>

<body>

  <!-- HTML Lab 1: Navigation bar with links -->
  <nav>
    <div class="nav-links">
      <a href="company.php">Company Information</a>
      <a href="disruptions.php">Disruption Events</a>
      <a href="transactions.php">Transactions</a>
      <!-- JS Lab 2: onclick event handler -->
      <button class="logout-btn" onclick="logout()">
        <img src="logout.png" alt="Log Out">
      </button>
    </div>

    <script>
      // JS Lab 2: Function to handle logout with confirmation
      function logout() {
        // JS Lab 2: confirm() shows browser dialog
        let answer = confirm("Are you sure you want to log out?");
        if (answer) {
          // JS Lab 2: window.location.href redirects to another page
          window.location.href = "logout.php";
        }
      }
    </script>
    <div style="flex-grow: 1;"></div>

    <!-- HTML Lab 1: Button elements with onclick handlers -->
    <button class="layout-btn customize" id="customize-layout-btn" style="height: 30px; padding: 0 2px;" onclick="toggleCustomizeMode()">
      Customize Layout
    </button>
    <button class="layout-btn reset" id="reset-layout-btn" style="height: 30px; padding: 0 2px;" onclick="resetLayout()">
      Reset Layout
    </button>

    <button class="layout-btn customize" id="select-boxes-btn" style="height: 30px; padding: 0 2px;" onclick="toggleSelectionMode()">
      Select Boxes
    </button>
    <button class="export-selected-btn" id="export-selected-btn" style="height: 30px; padding: 0 2px;" onclick="exportSelectedBoxes()" disabled>
      Export Selected (<span id="selected-count">0</span>)
    </button>
    <button class="layout-btn customize" id="compare-companies-btn" style="height: 30px; padding: 0 2px;" onclick="openComparisonModal()">
      Compare Companies
    </button>
  </nav>

  <!-- HTML Lab 1: div element for overlay effect when box is expanded -->
  <div id="box-overlay"></div>

  <!-- HTML Lab 1: Container div for page content -->
  <div class="container">
    <h1 class="page-title">Senior Manager Dashboard</h1>
  </div>

  <!-- HTML Lab 1: Grid layout using div containers -->
  <!-- Each info-box is a separate dashboard widget -->
  <div class="info-grid">

    <!-- Dashboard Box 1: Financial Health -->
    <!-- HTML Lab 1: div with class and data attribute -->
    <!-- JS Lab 2: onclick handler and data-box-id for identification -->
    <div class="info-box" data-box-id="box-1" onclick="handleBoxClick(event, 'box-1')">
      <div class="selection-checkbox"></div>
      <h3>Average Financial Health by Company</h3>

      <!-- HTML Lab 1: Form elements with labels and dropdowns -->
      <div class="filter-row">
        <label class="filter-label">Start Period:</label>
        <!-- HTML Lab 1: select dropdown with options -->
        <select id="start-quarter" class="filter-select small">
          <option value="">All</option>
          <option value="Q1">Q1</option>
          <option value="Q2">Q2</option>
          <option value="Q3">Q3</option>
          <option value="Q4">Q4</option>
        </select>
        <select id="start-year" class="filter-select small">
          <option value="">All</option>
          <option value="2022">2022</option>
          <option value="2023">2023</option>
          <option value="2024">2024</option>
        </select>
      </div>

      <div class="filter-row">
        <label class="filter-label">End Period:</label>
        <select id="end-quarter" class="filter-select small">
          <option value="">All</option>
          <option value="Q1">Q1</option>
          <option value="Q2">Q2</option>
          <option value="Q3">Q3</option>
          <option value="Q4">Q4</option>
        </select>
        <select id="end-year" class="filter-select small">
          <option value="">All</option>
          <option value="2022">2022</option>
          <option value="2023">2023</option>
          <option value="2024">2024</option>
        </select>
      </div>

      <div class="filter-row">
        <label class="filter-label">Company Type:</label>
        <select id="company-type" class="filter-select">
          <option value="All">All</option>
          <option value="Supplier">Supplier</option>
          <option value="Distributor">Distributor</option>
          <option value="Retailer">Retailer</option>
          <option value="Manufacturer">Manufacturer</option>
        </select>
      </div>

      <!-- Search input with autocomplete dropdown -->
      <div class="filter-row">
        <label class="filter-label">Company:</label>
        <div class="search-wrapper">
          <!-- HTML Lab 1: text input with placeholder -->
          <!-- JS Lab 2: autocomplete="off" disables browser autocomplete -->
          <input
            type="text"
            id="company-search"
            class="search-input"
            placeholder="Type to search companies..."
            autocomplete="off">
          <!-- JS Lab 2: div to display search results dynamically -->
          <div id="company-search-results" class="search-results"></div>
        </div>
      </div>

      <div class="filter-row">
        <div id="selected-company-display" style="flex: 1;"></div>
        <button class="clear-all-btn" onclick="clearAllFilters()">Clear All Filters</button>
      </div>

      <!-- Container where company list will be populated by JavaScript -->
      <div id="company-list" class="company-list"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 2: Regional Disruptions -->
    <div class="info-box" data-box-id="box-2" onclick="handleBoxClick(event, 'box-2')">
      <h3>Regional Disruption Overview</h3>
      <div class="selection-checkbox"></div>

      <div class="filter-row">
        <label class="filter-label">Region:</label>
        <!-- JS Lab 2: onchange event triggers function when selection changes -->
        <select id="region-filter" class="filter-select" onchange="loadRegionalDisruptions()">
          <option value="All">All Regions</option>
        </select>
      </div>

      <!-- Container for Plotly chart -->
      <div id="regional-chart" class="chart-container"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 3: Critical Companies -->
    <div class="info-box" data-box-id="box-3" onclick="handleBoxClick(event, 'box-3')">
      <h3>Most Critical Companies</h3>
      <div class="selection-checkbox"></div>

      <div id="critical-companies-list" class="company-list"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 4: Disruption Frequency Over Time -->
    <div class="info-box" data-box-id="box-4" onclick="handleBoxClick(event, 'box-4')">
      <h3>Disruption Frequency Over Time</h3>
      <div class="selection-checkbox"></div>

      <div class="filter-row">
        <label class="filter-label">Start Date:</label>
        <!-- HTML Lab 1: date input type -->
        <input type="date" id="disruption-start-date" class="filter-select">
      </div>

      <div class="filter-row">
        <label class="filter-label">End Date:</label>
        <input type="date" id="disruption-end-date" class="filter-select">
      </div>

      <div class="filter-row">
        <button class="clear-all-btn" onclick="loadDisruptionFrequency()" style="margin-left: 0;">Apply Filters</button>
      </div>

      <div id="disruption-frequency-chart" class="chart-container"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 5: Company Financials -->
    <div class="info-box" data-box-id="box-5" onclick="handleBoxClick(event, 'box-5')">
      <h3>Company Financials</h3>
      <div class="selection-checkbox"></div>

      <div class="filter-row">
        <label class="filter-label">Region:</label>
        <select id="financial-region" class="filter-select" onchange="loadCompanyFinancials()">
          <option value="All">All Regions</option>
        </select>
      </div>

      <div class="filter-row">
        <label class="filter-label">Company:</label>
        <div class="search-wrapper">
          <input
            type="text"
            id="financial-company-search"
            class="search-input"
            placeholder="Optional: search for specific company..."
            autocomplete="off">
          <div id="financial-company-results" class="search-results"></div>
        </div>
      </div>

      <!-- Hidden row that shows when company is selected -->
      <div class="filter-row" style="display: none;" id="financial-selected-row">
        <div id="financial-selected-company" style="flex: 1;"></div>
        <button class="clear-all-btn" onclick="clearFinancialCompany()" style="font-size: 11px; padding: 4px 8px;">Clear Company Filter</button>
      </div>

      <div id="company-financials-list" class="company-list"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 6: Top Distributors -->
    <div class="info-box" data-box-id="box-6" onclick="handleBoxClick(event, 'box-6')">
      <h3>Top Distributors by Shipment Volume</h3>
      <div class="selection-checkbox"></div>

      <div class="filter-row">
        <label class="filter-label">Distributor:</label>
        <div class="search-wrapper">
          <input
            type="text"
            id="distributor-search"
            class="search-input"
            placeholder="Optional: search for specific distributor..."
            autocomplete="off">
          <div id="distributor-search-results" class="search-results"></div>
        </div>
      </div>

      <div class="filter-row" style="display: none;" id="distributor-selected-row">
        <div id="distributor-selected-display" style="flex: 1;"></div>
        <button class="clear-all-btn" onclick="clearDistributorFilter()" style="font-size: 11px; padding: 4px 8px;">Clear Filter</button>
      </div>

      <div id="distributors-list" class="company-list"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 7: Companies Affected by Disruption -->
    <div class="info-box" data-box-id="box-7" onclick="handleBoxClick(event, 'box-7')">
      <h3>Companies Affected by Disruption Event</h3>
      <div class="selection-checkbox"></div>

      <div class="filter-row">
        <label class="filter-label">Disruption Event:</label>
        <select id="disruption-event-filter" class="filter-select" onchange="loadAffectedCompanies()">
          <option value="">Select an event...</option>
        </select>
      </div>

      <div id="affected-companies-list" class="company-list"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 8: All Disruptions for Company -->
    <div class="info-box" data-box-id="box-8" onclick="handleBoxClick(event, 'box-8')">
      <h3>All Disruptions for Specific Company</h3>
      <div class="selection-checkbox"></div>

      <div class="filter-row">
        <label class="filter-label">Company:</label>
        <div class="search-wrapper">
          <input
            type="text"
            id="disruptions-company-search"
            class="search-input"
            placeholder="Type to search companies..."
            autocomplete="off">
          <div id="disruptions-company-results" class="search-results"></div>
        </div>
      </div>

      <div class="filter-row" style="display: none;" id="disruptions-company-selected-row">
        <div id="disruptions-company-selected" style="flex: 1;"></div>
        <button class="clear-all-btn" onclick="clearDisruptionsCompany()" style="font-size: 11px; padding: 4px 8px;">Clear Filter</button>
      </div>

      <div id="company-disruptions-list" class="company-list"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 9: Distributors by Delay -->
    <div class="info-box" data-box-id="box-9" onclick="handleBoxClick(event, 'box-9')">
      <h3>Distributors Sorted by Average Delay</h3>
      <div class="selection-checkbox"></div>

      <div id="distributors-delay-list" class="company-list"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 10: Add New Company Form -->
    <div class="info-box" data-box-id="box-10" onclick="handleBoxClick(event, 'box-10')">
      <h3>Add New Company</h3>
      <div class="selection-checkbox"></div>

      <!-- HTML Lab 1 & JS Lab 2: Form inputs for data entry -->
      <div class="filter-row">
        <label class="filter-label">Company Name:</label>
        <input type="text" id="new-company-name" class="filter-select" placeholder="Enter company name...">
      </div>

      <div class="filter-row">
        <label class="filter-label">Company Type:</label>
        <select id="new-company-type" class="filter-select">
          <option value="">Select type...</option>
          <option value="Supplier">Supplier</option>
          <option value="Distributor">Distributor</option>
          <option value="Retailer">Retailer</option>
          <option value="Manufacturer">Manufacturer</option>
        </select>
      </div>

      <div class="filter-row">
        <label class="filter-label">Region:</label>
        <select id="new-company-region" class="filter-select">
          <option value="">Select region...</option>
          <option value="North America">North America</option>
          <option value="Europe">Europe</option>
          <option value="Asia">Asia</option>
          <option value="South America">South America</option>
          <option value="Africa">Africa</option>
          <option value="Oceania">Oceania</option>
        </select>
      </div>

      <div class="filter-row">
        <button class="clear-all-btn" style="background-color: #4CAF50; width: 100%; margin-left: 0;" onclick="addNewCompany()">
          Add Company
        </button>
      </div>

      <!-- Status message area - hidden by default -->
      <!-- JS Lab 2: innerHTML will be set dynamically by JavaScript -->
      <div id="add-company-status" style="margin-top: 10px; padding: 10px; border-radius: 4px; display: none;"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 11: Disruption Severity by Region -->
    <div class="info-box" data-box-id="box-11" onclick="handleBoxClick(event, 'box-11')">
      <h3>Disruption Severity Mix by Region</h3>
      <div class="selection-checkbox"></div>

      <div id="custom-plot-1" class="chart-container"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>

    <!-- Dashboard Box 12: Financial Health by Region -->
    <div class="info-box" data-box-id="box-12" onclick="handleBoxClick(event, 'box-12')">
      <h3>Average Financial Health by Region</h3>
      <div class="selection-checkbox"></div>

      <div id="custom-plot-2" class="chart-container"></div>

      <button class="zoom-btn" title="Expand box">+</button>
    </div>
  </div>

  <script>
    // Global variables to track selected company for filtering
    var selectedCompanyID = null;
    var selectedCompanyName = null;

    // JS Lab 2: DOMContentLoaded event fires when HTML is loaded
    // Source: MDN DOMContentLoaded - https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize all search boxes and load initial data
      setupCompanySearch();
      setupFilterListeners();
      setupFinancialCompanySearch();
      setupDistributorSearch();
      setupDisruptionsCompanySearch();
      loadFinancialHealth();
      loadRegionalDisruptions();
      loadCriticalCompanies();
      loadDisruptionFrequency();
      loadFinancialRegions();
      loadCompanyFinancials();
      loadTopDistributors();
      loadDisruptionEvents();
      loadAffectedCompanies();
      loadCompanyDisruptions();
      loadDistributorsDelay();
      loadDisruptionSeverityByRegion();
      loadFinancialHealthByRegion();
      setupBoxZoom();
      setupDragAndDrop();
      loadSavedLayout();
    });

    // Set up zoom functionality for dashboard boxes
    function setupBoxZoom() {
      // JS Lab 2: querySelector and querySelectorAll to select DOM elements
      const boxes = document.querySelectorAll('.info-box');
      const overlay = document.getElementById('box-overlay');

      boxes.forEach(box => {
        const zoomBtn = box.querySelector('.zoom-btn');

        // JS Lab 2: addEventListener for click events
        zoomBtn.addEventListener('click', function(e) {
          e.stopPropagation(); // prevent box click handler from firing

          const isExpanded = box.classList.contains('expanded');

          // Close any other expanded boxes
          document.querySelectorAll('.info-box.expanded').forEach(b => {
            if (b !== box) {
              b.classList.remove('expanded');
              const otherBtn = b.querySelector('.zoom-btn');
              otherBtn.textContent = '+';
              otherBtn.title = 'Expand box';
            }
          });

          if (isExpanded) {
            // Close this box
            box.classList.remove('expanded');
            overlay.classList.remove('show');
            zoomBtn.textContent = '+';
            zoomBtn.title = 'Expand box';

            resizeChartsInBox(box, false);
          } else {
            // Open this box
            box.classList.add('expanded');
            overlay.classList.add('show');
            zoomBtn.textContent = '-';
            zoomBtn.title = 'Close';

            // Delay chart resize to allow CSS transition
            // Source: setTimeout - https://developer.mozilla.org/en-US/docs/Web/API/setTimeout
            setTimeout(() => resizeChartsInBox(box, true), 100);
          }
        });

        // Prevent zoom when clicking interactive elements
        const interactiveElements = box.querySelectorAll('input, select, button:not(.zoom-btn), .search-result-item, .clear-company');
        interactiveElements.forEach(el => {
          el.addEventListener('click', function(e) {
            e.stopPropagation();
          });
        });
      });

      // Click overlay to close expanded box
      overlay.addEventListener('click', function() {
        document.querySelectorAll('.info-box.expanded').forEach(box => {
          box.classList.remove('expanded');
          const zoomBtn = box.querySelector('.zoom-btn');
          zoomBtn.textContent = '+';
          zoomBtn.title = 'Expand box';

          resizeChartsInBox(box, false);
        });
        overlay.classList.remove('show');
      });
    }

    // Resize Plotly charts when box is expanded/collapsed
    // Source: Plotly.Plots.resize - https://plotly.com/javascript/plotlyjs-function-reference/
    function resizeChartsInBox(box, isExpanded) {
      const boxId = box.getAttribute('data-box-id');

      const chartDivs = box.querySelectorAll('.chart-container[id]');

      chartDivs.forEach(chartDiv => {
        // Check if Plotly chart exists in this div
        if (chartDiv.id && window.Plotly && document.getElementById(chartDiv.id)._fullLayout) {
          Plotly.Plots.resize(chartDiv.id);
        }
      });
    }

    // JS Lab 2: Set up event listeners for filter dropdowns
    function setupFilterListeners() {
      document.getElementById('start-quarter').addEventListener('change', loadFinancialHealth);
      document.getElementById('start-year').addEventListener('change', loadFinancialHealth);
      document.getElementById('end-quarter').addEventListener('change', loadFinancialHealth);
      document.getElementById('end-year').addEventListener('change', loadFinancialHealth);
      document.getElementById('company-type').addEventListener('change', loadFinancialHealth);
    }

    // Set up company search autocomplete functionality
    function setupCompanySearch() {
      // JS Lab 2: getElementById to get DOM element
      var searchInput = document.getElementById('company-search');
      var resultsDiv = document.getElementById('company-search-results');

      // JS Lab 2: input event fires as user types
      searchInput.addEventListener('input', function() {
        var query = this.value.trim();

        if (query.length < 1) {
          resultsDiv.classList.remove('show');
          return;
        }

        // Fetch API for AJAX requests
        // Source: Fetch API - https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API
        fetch('senior_manager.php?action=company_search&query=' + encodeURIComponent(query))
          .then(response => response.json()) // parse JSON response
          .then(companies => {
            if (!companies || companies.length === 0) {
              resultsDiv.innerHTML = '<div class="search-result-item" style="color: #999;">No companies found</div>';
            } else {
              var html = '';
              // Loop through results and build HTML
              companies.forEach(function(company) {
                html += '<div class="search-result-item" data-id="' + company.CompanyID + '" data-name="' + company.CompanyName + '">';
                html += company.CompanyName;
                html += '</div>';
              });
              resultsDiv.innerHTML = html;
            }
            resultsDiv.classList.add('show');
          })
          .catch(error => console.error('Company search error:', error));
      });

      // Auto-load all companies when focused with empty input
      searchInput.addEventListener('focus', function() {
        if (this.value.trim().length === 0) {
          this.value = ' '; // trigger input event
          this.dispatchEvent(new Event('input'));
          this.value = '';
        }
      });

      // Handle click on search result
      resultsDiv.addEventListener('click', function(e) {
        if (e.target.classList.contains('search-result-item') && e.target.dataset.id) {
          selectCompany(e.target.dataset.id, e.target.dataset.name);
        }
      });

      // Close dropdown when clicking outside
      // Source: Event delegation - https://javascript.info/event-delegation
      document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-wrapper')) {
          resultsDiv.classList.remove('show');
        }
      });
    }

    // Handle company selection from search results
    function selectCompany(companyID, companyName) {
      selectedCompanyID = companyID;
      selectedCompanyName = companyName;

      // Clear search box
      document.getElementById('company-search').value = '';
      document.getElementById('company-search-results').classList.remove('show');

      // Show selected company with × to clear
      var displayDiv = document.getElementById('selected-company-display');
      displayDiv.innerHTML = '<span class="selected-company">' + companyName +
        ' <span class="clear-company" onclick="clearCompanySelection()"></span></span>';

      // Reload data with new filter
      loadFinancialHealth();
    }

    function clearCompanySelection() {
      selectedCompanyID = null;
      selectedCompanyName = null;
      document.getElementById('selected-company-display').innerHTML = '';
      loadFinancialHealth();
    }

    // Clear all filters and reload data
    function clearAllFilters() {
      document.getElementById('start-quarter').value = '';
      document.getElementById('start-year').value = '';
      document.getElementById('end-quarter').value = '';
      document.getElementById('end-year').value = '';
      document.getElementById('company-type').value = 'All';

      selectedCompanyID = null;
      selectedCompanyName = null;
      document.getElementById('selected-company-display').innerHTML = '';

      loadFinancialHealth();
    }

    // Load financial health data with filters
    // PHP Lab 3 concept: Sending GET parameters to PHP
    function loadFinancialHealth() {
      var startQuarter = document.getElementById('start-quarter').value;
      var startYear = document.getElementById('start-year').value;
      var endQuarter = document.getElementById('end-quarter').value;
      var endYear = document.getElementById('end-year').value;
      var companyType = document.getElementById('company-type').value;

      // Build URL with query parameters
      var url = 'senior_manager.php?action=financial_health';

      if (startQuarter) url += '&start_quarter=' + encodeURIComponent(startQuarter);
      if (startYear) url += '&start_year=' + encodeURIComponent(startYear);
      if (endQuarter) url += '&end_quarter=' + encodeURIComponent(endQuarter);
      if (endYear) url += '&end_year=' + encodeURIComponent(endYear);
      if (companyType !== 'All') url += '&company_type=' + encodeURIComponent(companyType);
      if (selectedCompanyID) url += '&company_id=' + selectedCompanyID;

      // Make AJAX request
      fetch(url)
        .then(response => response.json())
        .then(data => displayFinancialHealthList(data))
        .catch(error => console.error('Error loading financial health:', error));
    }

    // Display financial health data in list format
    function displayFinancialHealthList(data) {
      var listContainer = document.getElementById('company-list');

      if (!data || data.length === 0) {
        listContainer.innerHTML = '<div class="no-data">No data available</div>';
        return;
      }

      // JS Lab 2: Building HTML string and using innerHTML to display
      var listHtml = '';
      data.forEach(function(item) {
        listHtml += '<div class="company-list-item">';
        listHtml += '  <div class="company-name">' + item.company + '</div>';
        listHtml += '  <div class="company-type">' + item.type + '</div>';
        listHtml += '  <div class="company-score">' + item.health + '%</div>';
        listHtml += '</div>';
      });

      listContainer.innerHTML = listHtml;
    }

    // Populate region dropdown dynamically
    function updateRegionDropdown() {
      fetch('senior_manager.php?action=get_regions')
        .then(response => response.json())
        .then(regions => {
          const dropdown = document.getElementById('region-filter');
          const currentValue = dropdown.value; // remember current selection

          dropdown.innerHTML = '<option value="All">All Regions</option>';

          // Add each region as an option
          regions.forEach(region => {
            const option = document.createElement('option');
            option.value = region;
            option.textContent = region;
            dropdown.appendChild(option);
          });

          dropdown.value = currentValue; // restore selection
        })
        .catch(err => console.error("Error loading regions:", err));
    }

    // Load regional disruption data
    function loadRegionalDisruptions() {
      const region = document.getElementById('region-filter').value;

      let url = 'senior_manager.php?action=regional_disruptions';
      if (region !== 'All') {
        url += '&region=' + encodeURIComponent(region);
      }

      fetch(url)
        .then(response => response.json())
        .then(data => {
          displayRegionalChart(data);
        })
        .catch(error => console.error('Error loading regional disruptions:', error));
    }

    // Initialize region dropdown and load data on page load
    window.addEventListener('DOMContentLoaded', () => {
      updateRegionDropdown();
      loadRegionalDisruptions();
    });

    document.getElementById('region-filter')
      .addEventListener('change', loadRegionalDisruptions);

    // Display regional disruption data as grouped bar chart
    // Source: Plotly bar charts - https://plotly.com/javascript/bar-charts/
    function displayRegionalChart(data) {
      var chartContainer = document.getElementById('regional-chart');

      if (!data || data.length === 0) {
        chartContainer.innerHTML = '<div class="no-data">No data available</div>';
        return;
      }

      // Extract data for chart
      var regions = data.map(function(item) {
        return item.region;
      });
      var totalDisruptions = data.map(function(item) {
        return item.total;
      });
      var highImpactDisruptions = data.map(function(item) {
        return item.high_impact;
      });

      // Define chart traces (data series)
      var trace1 = {
        x: regions,
        y: totalDisruptions,
        name: 'Total Disruptions',
        type: 'bar',
        marker: {
          color: '#2196F3'
        }
      };

      var trace2 = {
        x: regions,
        y: highImpactDisruptions,
        name: 'High Impact',
        type: 'bar',
        marker: {
          color: '#f44336'
        }
      };

      // Chart layout configuration
      var layout = {
        barmode: 'group', // bars side-by-side
        margin: {
          t: 20,
          r: 10,
          b: 60,
          l: 40
        },
        xaxis: {
          tickangle: -45, // angle labels for readability
          tickfont: {
            size: 10
          }
        },
        yaxis: {
          title: 'Count',
          titlefont: {
            size: 11
          },
          tickfont: {
            size: 10
          }
        },
        autosize: true,
        legend: {
          x: 0,
          y: 1.1,
          orientation: 'h', // horizontal legend
          font: {
            size: 10
          }
        },
        showlegend: true
      };

      var config = {
        displayModeBar: false, // hide Plotly toolbar
        responsive: true // auto-resize
      };

      // Create the chart
      Plotly.newPlot('regional-chart', [trace1, trace2], layout, config);
    }

    function loadCriticalCompanies() {
      fetch('senior_manager.php?action=critical_companies')
        .then(response => response.json())
        .then(data => displayCriticalCompaniesList(data))
        .catch(error => console.error('Error loading critical companies:', error));
    }

    function displayCriticalCompaniesList(data) {
      var listContainer = document.getElementById('critical-companies-list');

      if (!data || data.length === 0) {
        listContainer.innerHTML = '<div class="no-data">No data available</div>';
        return;
      }

      var listHtml = '';
      data.forEach(function(item) {
        listHtml += '<div class="company-list-item">';
        listHtml += '  <div class="company-name">' + item.company + '</div>';
        listHtml += '  <div class="company-score" style="color: #ff5722;">' + item.criticality + '</div>';
        listHtml += '</div>';
      });

      listContainer.innerHTML = listHtml;
    }

    function loadDisruptionFrequency() {
      var startDate = document.getElementById('disruption-start-date').value;
      var endDate = document.getElementById('disruption-end-date').value;

      var url = 'senior_manager.php?action=disruption_frequency';

      if (startDate) {
        url += '&start_date=' + encodeURIComponent(startDate);
      }
      if (endDate) {
        url += '&end_date=' + encodeURIComponent(endDate);
      }

      fetch(url)
        .then(response => response.json())
        .then(data => displayDisruptionFrequencyChart(data))
        .catch(error => console.error('Error loading disruption frequency:', error));
    }

    // Display time series line chart
    // Source: Plotly line charts - https://plotly.com/javascript/line-charts/
    function displayDisruptionFrequencyChart(data) {
      var chartContainer = document.getElementById('disruption-frequency-chart');

      if (!data || data.length === 0) {
        chartContainer.innerHTML = '<div class="no-data">No data available for selected date range</div>';
        return;
      }

      var dates = data.map(function(item) {
        return item.date;
      });
      var counts = data.map(function(item) {
        return item.count;
      });

      var trace = {
        x: dates,
        y: counts,
        type: 'scatter',
        mode: 'lines+markers', // show both line and points
        line: {
          color: '#2196F3',
          width: 2
        },
        marker: {
          size: 6,
          color: '#2196F3'
        },
        name: 'Disruptions'
      };

      var layout = {
        margin: {
          t: 20,
          r: 10,
          b: 60,
          l: 50
        },
        xaxis: {
          title: 'Date',
          titlefont: {
            size: 11
          },
          tickfont: {
            size: 9
          },
          tickangle: -45
        },
        yaxis: {
          title: 'Number of Disruptions',
          titlefont: {
            size: 11
          },
          tickfont: {
            size: 10
          }
        },
        autosize: true,
        showlegend: false
      };

      var config = {
        displayModeBar: false,
        responsive: true
      };

      Plotly.newPlot('disruption-frequency-chart', [trace], layout, config);
    }

    // Variables for financial company search
    var selectedFinancialCompanyID = null;
    var selectedFinancialCompanyName = null;

    // Set up search box for financial company filter
    function setupFinancialCompanySearch() {
      var searchInput = document.getElementById('financial-company-search');
      var resultsDiv = document.getElementById('financial-company-results');

      searchInput.addEventListener('input', function() {
        var query = this.value.trim();

        if (query.length < 1) {
          resultsDiv.classList.remove('show');
          return;
        }

        // Include region filter in search
        var region = document.getElementById('financial-region').value;
        var url = 'senior_manager.php?action=financial_company_search&query=' + encodeURIComponent(query);
        if (region !== 'All') {
          url += '&region=' + encodeURIComponent(region);
        }

        fetch(url)
          .then(response => response.json())
          .then(companies => {
            if (!companies || companies.length === 0) {
              resultsDiv.innerHTML = '<div class="search-result-item" style="color: #999;">No companies found</div>';
            } else {
              var html = '';
              companies.forEach(function(company) {
                html += '<div class="search-result-item" data-id="' + company.CompanyID + '" data-name="' + company.CompanyName + '">';
                html += company.CompanyName;
                html += '</div>';
              });
              resultsDiv.innerHTML = html;
            }
            resultsDiv.classList.add('show');
          })
          .catch(error => console.error('Financial company search error:', error));
      });

      resultsDiv.addEventListener('click', function(e) {
        if (e.target.classList.contains('search-result-item') && e.target.dataset.id) {
          selectFinancialCompany(e.target.dataset.id, e.target.dataset.name);
        }
      });

      document.addEventListener('click', function(e) {
        if (!e.target.closest('#financial-company-search') && !e.target.closest('#financial-company-results')) {
          resultsDiv.classList.remove('show');
        }
      });
    }

    function selectFinancialCompany(companyID, companyName) {
      selectedFinancialCompanyID = companyID;
      selectedFinancialCompanyName = companyName;

      document.getElementById('financial-company-search').value = '';
      document.getElementById('financial-company-results').classList.remove('show');

      var displayDiv = document.getElementById('financial-selected-company');
      displayDiv.innerHTML = '<span class="selected-company">' + companyName + '</span>';

      // Show the row containing selected company
      document.getElementById('financial-selected-row').style.display = 'flex';

      loadCompanyFinancials();
    }

    function clearFinancialCompany() {
      selectedFinancialCompanyID = null;
      selectedFinancialCompanyName = null;
      document.getElementById('financial-selected-company').innerHTML = '';
      document.getElementById('financial-selected-row').style.display = 'none';
      document.getElementById('financial-company-search').value = '';
      loadCompanyFinancials();
    }

    function loadCompanyFinancials() {
      var region = document.getElementById('financial-region').value;

      var url = 'senior_manager.php?action=company_financials';

      if (selectedFinancialCompanyID) {
        url += '&company_id=' + selectedFinancialCompanyID;
      }

      if (region !== 'All') {
        url += '&region=' + encodeURIComponent(region);
      }

      fetch(url)
        .then(response => response.json())
        .then(data => displayCompanyFinancials(data))
        .catch(error => console.error('Error loading company financials:', error));
    }

    function displayCompanyFinancials(data) {
      var listContainer = document.getElementById('company-financials-list');

      if (!data || data.length === 0) {
        listContainer.innerHTML = '<div class="no-data">No financial data available</div>';
        return;
      }

      var listHtml = '';
      data.forEach(function(item) {
        listHtml += '<div class="company-list-item">';
        listHtml += '  <div class="company-name">' + item.company + '</div>';
        listHtml += '  <div class="company-type">' + item.quarter + ' ' + item.year + '</div>';
        listHtml += '  <div class="company-score">' + item.health + '%</div>';
        listHtml += '</div>';
      });

      listContainer.innerHTML = listHtml;
    }

    // Load regions for financial filter dropdown
    function loadFinancialRegions() {
      fetch('senior_manager.php?action=get_regions')
        .then(response => response.json())
        .then(regions => {
          var dropdown = document.getElementById('financial-region');
          dropdown.innerHTML = '<option value="All">All Regions</option>';
          regions.forEach(function(region) {
            var option = document.createElement('option');
            option.value = region;
            option.textContent = region;
            dropdown.appendChild(option);
          });
        })
        .catch(error => console.error('Error loading regions:', error));
    }

    var selectedDistributorID = null;
    var selectedDistributorName = null;

    function setupDistributorSearch() {
      var searchInput = document.getElementById('distributor-search');
      var resultsDiv = document.getElementById('distributor-search-results');

      searchInput.addEventListener('input', function() {
        var query = this.value.trim();

        if (query.length < 1) {
          resultsDiv.classList.remove('show');
          return;
        }

        fetch('senior_manager.php?action=distributor_search&query=' + encodeURIComponent(query))
          .then(response => response.json())
          .then(distributors => {
            if (!distributors || distributors.length === 0) {
              resultsDiv.innerHTML = '<div class="search-result-item" style="color: #999;">No distributors found</div>';
            } else {
              var html = '';
              distributors.forEach(function(dist) {
                html += '<div class="search-result-item" data-id="' + dist.CompanyID + '" data-name="' + dist.CompanyName + '">';
                html += dist.CompanyName;
                html += '</div>';
              });
              resultsDiv.innerHTML = html;
            }
            resultsDiv.classList.add('show');
          })
          .catch(error => console.error('Distributor search error:', error));
      });

      resultsDiv.addEventListener('click', function(e) {
        if (e.target.classList.contains('search-result-item') && e.target.dataset.id) {
          selectDistributor(e.target.dataset.id, e.target.dataset.name);
        }
      });

      document.addEventListener('click', function(e) {
        if (!e.target.closest('#distributor-search') && !e.target.closest('#distributor-search-results')) {
          resultsDiv.classList.remove('show');
        }
      });
    }

    function selectDistributor(distributorID, distributorName) {
      selectedDistributorID = distributorID;
      selectedDistributorName = distributorName;

      document.getElementById('distributor-search').value = '';
      document.getElementById('distributor-search-results').classList.remove('show');

      var displayDiv = document.getElementById('distributor-selected-display');
      displayDiv.innerHTML = '<span class="selected-company">' + distributorName + '</span>';

      document.getElementById('distributor-selected-row').style.display = 'flex';

      loadTopDistributors();
    }

    function clearDistributorFilter() {
      selectedDistributorID = null;
      selectedDistributorName = null;
      document.getElementById('distributor-selected-display').innerHTML = '';
      document.getElementById('distributor-selected-row').style.display = 'none';
      document.getElementById('distributor-search').value = '';
      loadTopDistributors();
    }

    function loadTopDistributors() {
      var url = 'senior_manager.php?action=top_distributors';

      if (selectedDistributorID) {
        url += '&distributor_id=' + selectedDistributorID;
      }

      fetch(url)
        .then(response => response.json())
        .then(data => displayTopDistributors(data))
        .catch(error => console.error('Error loading top distributors:', error));
    }

    function displayTopDistributors(data) {
      var listContainer = document.getElementById('distributors-list');

      if (!data || data.length === 0) {
        listContainer.innerHTML = '<div class="no-data">No distributor data available</div>';
        return;
      }

      var listHtml = '';
      data.forEach(function(item) {
        listHtml += '<div class="company-list-item">';
        listHtml += '  <div class="company-name">' + item.distributor + '</div>';
        listHtml += '  <div class="company-score" style="color: #2196F3;">' + item.volume + ' shipments</div>';
        listHtml += '</div>';
      });

      listContainer.innerHTML = listHtml;
    }

    // Load disruption events for dropdown
    function loadDisruptionEvents() {
      fetch('senior_manager.php?action=get_disruption_events')
        .then(response => response.json())
        .then(events => {
          var dropdown = document.getElementById('disruption-event-filter');
          dropdown.innerHTML = '<option value="">Select an event...</option>';
          events.forEach(function(event) {
            var option = document.createElement('option');
            option.value = event.EventID;
            option.textContent = event.CategoryName + ' - ' + event.EventDate;
            dropdown.appendChild(option);
          });
        })
        .catch(error => console.error('Error loading disruption events:', error));
    }

    function loadAffectedCompanies() {
      var eventID = document.getElementById('disruption-event-filter').value;

      if (!eventID || eventID === '') {
        document.getElementById('affected-companies-list').innerHTML = '<div class="no-data">Select a disruption event to view affected companies</div>';
        return;
      }

      fetch('senior_manager.php?action=affected_companies&event_id=' + eventID)
        .then(response => response.json())
        .then(data => displayAffectedCompanies(data))
        .catch(error => console.error('Error loading affected companies:', error));
    }

    function displayAffectedCompanies(data) {
      var listContainer = document.getElementById('affected-companies-list');

      if (!data || data.length === 0) {
        listContainer.innerHTML = '<div class="no-data">No companies affected by this event</div>';
        return;
      }

      var listHtml = '';
      data.forEach(function(item) {
        listHtml += '<div class="company-list-item">';
        listHtml += '  <div class="company-name">' + item.company + '</div>';
        listHtml += '  <div class="company-type">' + item.disruption_event + '</div>';
        listHtml += '</div>';
      });

      listContainer.innerHTML = listHtml;
    }

    var selectedDisruptionsCompanyID = null;
    var selectedDisruptionsCompanyName = null;

    function setupDisruptionsCompanySearch() {
      var searchInput = document.getElementById('disruptions-company-search');
      var resultsDiv = document.getElementById('disruptions-company-results');

      searchInput.addEventListener('input', function() {
        var query = this.value.trim();

        if (query.length < 1) {
          resultsDiv.classList.remove('show');
          return;
        }

        fetch('senior_manager.php?action=company_search&query=' + encodeURIComponent(query))
          .then(response => response.json())
          .then(companies => {
            if (!companies || companies.length === 0) {
              resultsDiv.innerHTML = '<div class="search-result-item" style="color: #999;">No companies found</div>';
            } else {
              var html = '';
              companies.forEach(function(company) {
                html += '<div class="search-result-item" data-id="' + company.CompanyID + '" data-name="' + company.CompanyName + '">';
                html += company.CompanyName;
                html += '</div>';
              });
              resultsDiv.innerHTML = html;
            }
            resultsDiv.classList.add('show');
          })
          .catch(error => console.error('Company search error:', error));
      });

      resultsDiv.addEventListener('click', function(e) {
        if (e.target.classList.contains('search-result-item') && e.target.dataset.id) {
          selectDisruptionsCompany(e.target.dataset.id, e.target.dataset.name);
        }
      });

      document.addEventListener('click', function(e) {
        if (!e.target.closest('#disruptions-company-search') && !e.target.closest('#disruptions-company-results')) {
          resultsDiv.classList.remove('show');
        }
      });
    }

    function selectDisruptionsCompany(companyID, companyName) {
      selectedDisruptionsCompanyID = companyID;
      selectedDisruptionsCompanyName = companyName;

      document.getElementById('disruptions-company-search').value = '';
      document.getElementById('disruptions-company-results').classList.remove('show');

      var displayDiv = document.getElementById('disruptions-company-selected');
      displayDiv.innerHTML = '<span class="selected-company">' + companyName + '</span>';

      document.getElementById('disruptions-company-selected-row').style.display = 'flex';

      loadCompanyDisruptions();
    }

    function clearDisruptionsCompany() {
      selectedDisruptionsCompanyID = null;
      selectedDisruptionsCompanyName = null;
      document.getElementById('disruptions-company-selected').innerHTML = '';
      document.getElementById('disruptions-company-selected-row').style.display = 'none';
      document.getElementById('disruptions-company-search').value = '';
      document.getElementById('company-disruptions-list').innerHTML = '<div class="no-data">Select a company to view disruptions</div>';
    }

    function loadCompanyDisruptions() {
      if (!selectedDisruptionsCompanyID) {
        document.getElementById('company-disruptions-list').innerHTML = '<div class="no-data">Select a company to view disruptions</div>';
        return;
      }

      fetch('senior_manager.php?action=company_disruptions&company_id=' + selectedDisruptionsCompanyID)
        .then(response => response.json())
        .then(data => displayCompanyDisruptions(data))
        .catch(error => console.error('Error loading company disruptions:', error));
    }

    function displayCompanyDisruptions(data) {
      var listContainer = document.getElementById('company-disruptions-list');

      if (!data || data.length === 0) {
        listContainer.innerHTML = '<div class="no-data">No disruptions found for this company</div>';
        return;
      }

      var listHtml = '';
      data.forEach(function(item) {
        listHtml += '<div class="company-list-item" style="flex-direction: column; align-items: flex-start; padding: 8px;">';
        listHtml += '  <div style="display: flex; width: 100%; justify-content: space-between; margin-bottom: 3px;">';
        listHtml += '    <div style="font-size: 11px;"><strong>' + item.category + '</strong></div>';
        listHtml += '    <div class="company-type" style="background-color: ' + getImpactColor(item.impact_level) + '; color: white; font-size: 9px; padding: 2px 6px;">' + item.impact_level + '</div>';
        listHtml += '  </div>';
        listHtml += '  <div style="font-size: 10px; color: #666; margin-bottom: 2px;">';
        listHtml += '    Event: ' + item.event_date + ' | Recovery: ' + item.recovery_date;
        listHtml += '  </div>';
        // Truncate long descriptions
        listHtml += '  <div style="font-size: 10px; color: #555;">' + (item.description.length > 60 ? item.description.substring(0, 60) + '...' : item.description) + '</div>';
        listHtml += '</div>';
      });

      listContainer.innerHTML = listHtml;
    }

    // Helper function to get color based on impact level
    function getImpactColor(impactLevel) {
      switch (impactLevel) {
        case 'High':
          return '#f44336'; // red
        case 'Medium':
          return '#ff9800'; // orange
        case 'Low':
          return '#4CAF50'; // green
        default:
          return '#999'; // gray
      }
    }

    function loadDistributorsDelay() {
      fetch('senior_manager.php?action=distributors_delay')
        .then(response => response.json())
        .then(data => displayDistributorsDelay(data))
        .catch(error => console.error('Error loading distributors delay:', error));
    }

    function displayDistributorsDelay(data) {
      var listContainer = document.getElementById('distributors-delay-list');

      if (!data || data.length === 0) {
        listContainer.innerHTML = '<div class="no-data">No distributor delay data available</div>';
        return;
      }

      var listHtml = '';
      data.forEach(function(item) {
        // Color-code delays: green (<2), orange (2-5), red (>5)
        var delayColor = item.avg_delay > 5 ? '#f44336' : (item.avg_delay > 2 ? '#ff9800' : '#4CAF50');
        listHtml += '<div class="company-list-item">';
        listHtml += '  <div class="company-name">' + item.distributor + '</div>';
        listHtml += '  <div class="company-score" style="color: ' + delayColor + ';">' + item.avg_delay + ' days</div>';
        listHtml += '</div>';
      });

      listContainer.innerHTML = listHtml;
    }

    // Handle company form submission
    // PHP Lab 3: Sending POST data with FormData
    // Source: FormData API - https://developer.mozilla.org/en-US/docs/Web/API/FormData
    function addNewCompany() {
      // JS Lab 2: Get form values
      var companyName = document.getElementById('new-company-name').value.trim();
      var companyType = document.getElementById('new-company-type').value;
      var region = document.getElementById('new-company-region').value;
      var statusDiv = document.getElementById('add-company-status');

      // JS Lab 2 concept: Client-side validation
      if (!companyName) {
        showAddStatus('error', 'Please enter a company name');
        return;
      }

      if (!companyType) {
        showAddStatus('error', 'Please select a company type');
        return;
      }

      if (!region) {
        showAddStatus('error', 'Please select a region');
        return;
      }

      showAddStatus('loading', 'Creating company...');

      // Build FormData object for POST request
      var formData = new FormData();
      formData.append('action', 'add_company');
      formData.append('company_name', companyName);
      formData.append('company_type', companyType);
      formData.append('region', region);
      formData.append('tier_level', '3');

      // Send POST request
      fetch('senior_manager.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            showAddStatus('success', data.message + ' (ID: ' + data.company_id + ')');

            // Clear form fields
            document.getElementById('new-company-name').value = '';
            document.getElementById('new-company-type').value = '';
            document.getElementById('new-company-region').value = '';

            // Refresh other boxes that show companies
            loadFinancialHealth();
            loadCriticalCompanies();
          } else {
            showAddStatus('error', data.error || 'Failed to create company');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          showAddStatus('error', 'Network error: ' + error.message);
        });
    }

    // Display status message after form submission
    function showAddStatus(type, message) {
      var statusDiv = document.getElementById('add-company-status');
      statusDiv.style.display = 'block';
      statusDiv.style.padding = '8px';
      statusDiv.style.borderRadius = '4px';
      statusDiv.style.fontSize = '11px';
      statusDiv.style.fontWeight = '500';
      statusDiv.style.marginTop = '8px';

      if (type === 'success') {
        statusDiv.style.backgroundColor = '#d1e7dd';
        statusDiv.style.color = '#0f5132';
        statusDiv.style.border = '1px solid #badbcc';
        statusDiv.innerHTML = 'SUCCESS: ' + message;
        // Auto-hide after 5 seconds
        setTimeout(function() {
          statusDiv.style.display = 'none';
        }, 5000);
      } else if (type === 'error') {
        statusDiv.style.backgroundColor = '#f8d7da';
        statusDiv.style.color = '#842029';
        statusDiv.style.border = '1px solid #f5c2c7';
        statusDiv.innerHTML = 'ERROR: ' + message;
      } else if (type === 'loading') {
        statusDiv.style.backgroundColor = '#cff4fc';
        statusDiv.style.color = '#055160';
        statusDiv.style.border = '1px solid #b6effb';
        statusDiv.innerHTML = 'Loading... ' + message;
      }
    }

    function loadDisruptionSeverityByRegion() {
      fetch('senior_manager.php?action=disruption_severity_by_region')
        .then(response => response.json())
        .then(data => displayDisruptionSeverityChart(data))
        .catch(error => console.error('Error loading disruption severity by region:', error));
    }

    // Display stacked bar chart for disruption severity
    // Source: Plotly stacked bars - https://plotly.com/javascript/bar-charts/#stacked-bar-chart
    function displayDisruptionSeverityChart(data) {
      var chartContainer = document.getElementById('custom-plot-1');

      if (!data || data.length === 0) {
        chartContainer.innerHTML = '<div class="no-data">No disruption data available</div>';
        return;
      }

      // Get unique regions
      // Source: Set for unique values - https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Set
      var regions = [...new Set(data.map(item => item.region))];
      var impactLevels = ['Low', 'Medium', 'High'];

      var traces = [];

      // Create a trace (bar series) for each impact level
      impactLevels.forEach(function(level) {
        var counts = regions.map(function(region) {
          // Find matching data point
          var item = data.find(d => d.region === region && d.impact_level === level);
          return item ? item.count : 0;
        });

        var color = level === 'High' ? '#f44336' : (level === 'Medium' ? '#ff9800' : '#4CAF50');

        traces.push({
          x: regions,
          y: counts,
          name: level + ' Impact',
          type: 'bar',
          marker: {
            color: color
          }
        });
      });

      var layout = {
        barmode: 'stack', // stack bars on top of each other
        margin: {
          t: 40,
          r: 10,
          b: 60,
          l: 50
        },
        xaxis: {
          tickangle: -45,
          tickfont: {
            size: 10
          }
        },
        yaxis: {
          title: 'Count',
          titlefont: {
            size: 11
          },
          tickfont: {
            size: 10
          }
        },
        autosize: true,
        legend: {
          x: 0,
          y: 1.15,
          orientation: 'h',
          font: {
            size: 10
          }
        },
        showlegend: true
      };

      var config = {
        displayModeBar: false,
        responsive: true
      };

      Plotly.newPlot('custom-plot-1', traces, layout, config);
    }

    function loadFinancialHealthByRegion() {
      fetch('senior_manager.php?action=financial_health_by_region')
        .then(response => response.json())
        .then(data => displayFinancialHealthByRegionChart(data))
        .catch(error => console.error('Error loading financial health by region:', error));
    }

    function displayFinancialHealthByRegionChart(data) {
      var chartContainer = document.getElementById('custom-plot-2');

      if (!data || data.length === 0) {
        chartContainer.innerHTML = '<div class="no-data">No financial data available</div>';
        return;
      }

      var regions = data.map(item => item.region);
      var avgHealth = data.map(item => item.avg_health);

      // Color-code bars based on health score
      var colors = avgHealth.map(function(health) {
        if (health >= 80) return '#4CAF50'; // green for healthy
        if (health >= 60) return '#ff9800'; // orange for moderate
        return '#f44336'; // red for poor
      });

      var trace = {
        x: regions,
        y: avgHealth,
        type: 'bar',
        marker: {
          color: colors,
          line: {
            color: '#333',
            width: 1
          }
        },
        text: avgHealth.map(h => h.toFixed(1) + '%'), // show values on bars
        textposition: 'outside',
        textfont: {
          size: 11
        }
      };

      var layout = {
        margin: {
          t: 40,
          r: 10,
          b: 60,
          l: 50
        },
        xaxis: {
          tickangle: -45,
          tickfont: {
            size: 10
          }
        },
        yaxis: {
          title: 'Health Score (%)',
          titlefont: {
            size: 11
          },
          tickfont: {
            size: 10
          },
          range: [0, 100] // fixed scale 0-100%
        },
        autosize: true,
        showlegend: false
      };

      var config = {
        displayModeBar: false,
        responsive: true
      };

      Plotly.newPlot('custom-plot-2', [trace], layout, config);
    }

    // Drag-and-drop for dashboard customization
    // Source: SortableJS library - https://sortablejs.github.io/Sortable/

    var sortableInstance = null;
    var customizeModeActive = false;

    function setupDragAndDrop() {
      const grid = document.querySelector('.info-grid');

      // Initialize SortableJS
      sortableInstance = new Sortable(grid, {
        animation: 200, // animation duration in ms
        disabled: true, // start disabled
        ghostClass: 'sortable-ghost', // class applied to placeholder
        dragClass: 'sortable-drag', // class applied while dragging
        handle: '.info-box', // what element to drag by
        onEnd: function() {
          saveLayout(); // save order when drag ends
        }
      });
    }

    function toggleCustomizeMode() {
      const btn = document.getElementById('customize-layout-btn');
      const grid = document.querySelector('.info-grid');

      customizeModeActive = !customizeModeActive;

      if (customizeModeActive) {
        sortableInstance.option('disabled', false); // enable dragging
        btn.classList.add('active');
        btn.innerHTML = 'Save Layout';
        grid.classList.add('customize-mode-active');
        showNotification('Drag and drop boxes to rearrange!', 'info');
      } else {
        sortableInstance.option('disabled', true); // disable dragging
        btn.classList.remove('active');
        btn.innerHTML = 'Customize Layout';
        grid.classList.remove('customize-mode-active');
        saveLayout();
        showNotification('Layout saved!', 'success');
      }
    }

    // Save layout to localStorage
    // Source: localStorage API - https://developer.mozilla.org/en-US/docs/Web/API/Window/localStorage
    function saveLayout() {
      const grid = document.querySelector('.info-grid');
      const boxes = grid.querySelectorAll('.info-box');
      const layout = [];

      // Store order of box IDs
      boxes.forEach(box => {
        layout.push(box.getAttribute('data-box-id'));
      });

      localStorage.setItem('dashboard-layout', JSON.stringify(layout));
    }

    // Load saved layout from localStorage
    function loadSavedLayout() {
      const savedLayout = localStorage.getItem('dashboard-layout');
      if (!savedLayout) return;

      try {
        const layout = JSON.parse(savedLayout);
        const grid = document.querySelector('.info-grid');

        // Reorder boxes according to saved layout
        layout.forEach(boxId => {
          const box = grid.querySelector(`[data-box-id="${boxId}"]`);
          if (box) grid.appendChild(box); // move to end
        });
      } catch (e) {
        console.error('Error loading layout:', e);
      }
    }

    // Reset layout to default
    function resetLayout() {
      if (!confirm('Reset dashboard layout to default?')) return;
      localStorage.removeItem('dashboard-layout');
      showNotification('Layout reset! Refreshing...', 'info');
      setTimeout(() => location.reload(), 1000);
    }

    // Show temporary notification to user
    function showNotification(message, type) {
      const notification = document.createElement('div');
      notification.style.cssText = `
    position: fixed;
    top: 80px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    z-index: 10000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  `;

      if (type === 'success') {
        notification.style.backgroundColor = '#4CAF50';
        notification.style.color = 'white';
      } else {
        notification.style.backgroundColor = '#2196F3';
        notification.style.color = 'white';
      }

      notification.textContent = message;
      document.body.appendChild(notification);

      // Fade out and remove after 3 seconds
      setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transition = 'opacity 0.3s';
        setTimeout(() => notification.remove(), 300);
      }, 3000);
    }

    // Box selection and export functionality
    var selectionModeActive = false;
    var selectedBoxes = new Set(); // use Set to avoid duplicates

    function toggleSelectionMode() {
      const btn = document.getElementById('select-boxes-btn');
      const grid = document.querySelector('.info-grid');

      selectionModeActive = !selectionModeActive;

      if (selectionModeActive) {
        grid.classList.add('selection-mode-active');
        document.body.classList.add('selection-mode-active');
        btn.classList.add('active');
        btn.innerHTML = 'Done Selecting';
        showNotification('Click boxes to select them for export', 'info');
      } else {
        grid.classList.remove('selection-mode-active');
        document.body.classList.remove('selection-mode-active');
        btn.classList.remove('active');
        btn.innerHTML = 'Select Boxes';

        // Clear all selections
        selectedBoxes.clear();
        document.querySelectorAll('.info-box').forEach(box => {
          box.classList.remove('selected');
          box.querySelector('.selection-checkbox').classList.remove('checked');
        });
        updateExportButton();
      }
    }

    // Handle clicks on boxes when in selection mode
    function handleBoxClick(event, boxId) {
      // Don't select if clicking interactive elements
      if (event.target.tagName === 'INPUT' ||
        event.target.tagName === 'SELECT' ||
        event.target.tagName === 'BUTTON' ||
        event.target.tagName === 'TEXTAREA' ||
        event.target.classList.contains('zoom-btn') ||
        event.target.closest('button') ||
        event.target.closest('input') ||
        event.target.closest('select') ||
        event.target.closest('textarea')) {
        return;
      }

      if (!selectionModeActive) return;

      toggleBoxSelection(boxId);
    }

    // Toggle selection state of a box
    function toggleBoxSelection(boxId) {
      if (!selectionModeActive) return;

      const box = document.querySelector(`[data-box-id="${boxId}"]`);
      const checkbox = box.querySelector('.selection-checkbox');

      if (selectedBoxes.has(boxId)) {
        // Deselect
        selectedBoxes.delete(boxId);
        box.classList.remove('selected');
        checkbox.classList.remove('checked');
      } else {
        // Select
        selectedBoxes.add(boxId);
        box.classList.add('selected');
        checkbox.classList.add('checked');
      }

      updateExportButton();
    }

    // Update export button text and state
    function updateExportButton() {
      const exportBtn = document.getElementById('export-selected-btn');
      const countSpan = document.getElementById('selected-count');

      countSpan.textContent = selectedBoxes.size;
      exportBtn.disabled = selectedBoxes.size === 0;
    }

    // Export selected boxes as CSV
    function exportSelectedBoxes() {
      if (selectedBoxes.size === 0) {
        alert('Please select at least one box to export');
        return;
      }

      // Convert Set to comma-separated string
      const boxIds = Array.from(selectedBoxes).join(',');

      // Get current filter values to apply to export
      const startQuarter = document.getElementById('start-quarter').value;
      const startYear = document.getElementById('start-year').value;
      const endQuarter = document.getElementById('end-quarter').value;
      const endYear = document.getElementById('end-year').value;
      const startDate = document.getElementById('disruption-start-date').value;
      const endDate = document.getElementById('disruption-end-date').value;

      // Build export URL with filters
      let url = `senior_manager.php?action=export_selected&boxes=${boxIds}`;

      if (startQuarter) url += `&start_quarter=${startQuarter}`;
      if (startYear) url += `&start_year=${startYear}`;
      if (endQuarter) url += `&end_quarter=${endQuarter}`;
      if (endYear) url += `&end_year=${endYear}`;
      if (startDate) url += `&start_date=${startDate}`;
      if (endDate) url += `&end_date=${endDate}`;

      // Trigger download by navigating to URL
      window.location.href = url;

      showNotification(`Exporting ${selectedBoxes.size} boxes...`, 'success');
    }

    // Map of box IDs to titles for export
    const boxTitles = {
      'box-1': 'Average Financial Health by Company',
      'box-2': 'Regional Disruption Overview',
      'box-3': 'Most Critical Companies',
      'box-4': 'Disruption Frequency Over Time',
      'box-5': 'Company Financials',
      'box-6': 'Top Distributors by Shipment Volume',
      'box-7': 'Companies Affected by Disruption Event',
      'box-8': 'All Disruptions for Specific Company',
      'box-9': 'Distributors Sorted by Average Delay',
      'box-10': 'Add New Company',
      'box-11': 'Disruption Severity Mix by Region',
      'box-12': 'Average Financial Health by Region',
    };


    // Company comparison modal functionality
    var comparisonCompanies = new Map(); // stores selected companies
    var comparisonData = []; // stores fetched comparison data
    var currentComparisonView = 'financial'; // current tab
    const COMPANY_COLORS = ['#2196F3', '#4CAF50', '#FF9800', '#E91E63', '#9C27B0'];

    function openComparisonModal() {
      document.getElementById('comparison-modal').style.display = 'flex';
      setupComparisonSearch();
      comparisonCompanies.clear();
      comparisonData = [];
      currentComparisonView = 'financial';
      updateComparisonDisplay();
      document.getElementById('comparison-chart').innerHTML = '<div style="text-align: center; padding: 50px; color: #666;">Select at least 2 companies to compare</div>';
      document.getElementById('comparison-summary').innerHTML = '';
    }

    function closeComparisonModal() {
      document.getElementById('comparison-modal').style.display = 'none';
      comparisonCompanies.clear();
      comparisonData = [];
    }

    // Set up search for adding companies to comparison
    function setupComparisonSearch() {
      const searchInput = document.getElementById('comparison-company-search');
      let timeout = null;

      searchInput.addEventListener('input', function() {
        clearTimeout(timeout);
        // Debounce search to avoid too many requests
        timeout = setTimeout(() => {
          const query = searchInput.value.trim();

          fetch(`senior_manager.php?action=company_search&query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(companies => {
              showComparisonSearchResults(companies);
            })
            .catch(err => {
              console.error('Search error:', err);
            });
        }, 300); // wait 300ms after user stops typing
      });
    }

    // Show search results dropdown
    function showComparisonSearchResults(companies) {
      // Remove existing dropdown if present
      let existingDropdown = document.getElementById('comparison-search-dropdown');
      if (existingDropdown) {
        existingDropdown.remove();
      }

      if (companies.length === 0) return;

      const dropdown = document.createElement('div');
      dropdown.id = 'comparison-search-dropdown';
      dropdown.style.cssText = 'position: absolute; background: white; border: 1px solid #ddd; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; width: calc(100% - 30px); box-shadow: 0 2px 5px rgba(0,0,0,0.2);';

      companies.forEach(company => {
        const item = document.createElement('div');
        item.textContent = company.CompanyName;
        item.style.cssText = 'padding: 10px; cursor: pointer; border-bottom: 1px solid #eee;';
        item.onmouseover = () => item.style.background = '#f0f0f0';
        item.onmouseout = () => item.style.background = 'white';
        item.onclick = () => {
          addCompanyToComparison(company.CompanyID, company.CompanyName);
          dropdown.remove();
          document.getElementById('comparison-company-search').value = '';
        };
        dropdown.appendChild(item);
      });

      const searchInput = document.getElementById('comparison-company-search');
      searchInput.parentElement.style.position = 'relative';
      searchInput.parentElement.appendChild(dropdown);
    }

    // Add company to comparison
    function addCompanyToComparison(id, name) {
      if (comparisonCompanies.has(id)) {
        showNotification('Company already added', 'warning');
        return;
      }

      if (comparisonCompanies.size >= 5) {
        showNotification('Maximum 5 companies allowed', 'warning');
        return;
      }

      comparisonCompanies.set(id, {
        id,
        name
      });
      updateComparisonDisplay();

      // Load data if we have at least 2 companies
      if (comparisonCompanies.size >= 2) {
        loadComparisonData();
      }
    }

    // Remove company from comparison
    function removeCompanyFromComparison(id) {
      comparisonCompanies.delete(id);
      updateComparisonDisplay();

      if (comparisonCompanies.size >= 2) {
        loadComparisonData();
      } else {
        document.getElementById('comparison-chart').innerHTML = '<div style="text-align: center; padding: 50px; color: #666;">Select at least 2 companies to compare</div>';
        document.getElementById('comparison-summary').innerHTML = '';
      }
    }

    // Update display of selected companies
    function updateComparisonDisplay() {
      const container = document.getElementById('comparison-selected-companies');
      const hint = document.getElementById('comparison-hint');

      container.innerHTML = '';

      // Create colored chip for each company
      let colorIndex = 0;
      comparisonCompanies.forEach((company, id) => {
        const chip = document.createElement('div');
        chip.style.cssText = `display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; background: ${COMPANY_COLORS[colorIndex]}; color: white; border-radius: 20px; font-size: 14px;`;
        chip.innerHTML = `
      <span>${company.name}</span>
      <span onclick="removeCompanyFromComparison(${id})" style="cursor: pointer; font-weight: bold; font-size: 18px;">&times;</span>
    `;
        container.appendChild(chip);
        colorIndex++;
      });

      // Update hint text
      const count = comparisonCompanies.size;
      if (count === 0) {
        hint.textContent = 'Select 2-5 companies to compare';
        hint.style.color = '#666';
      } else if (count === 1) {
        hint.textContent = 'Select at least 1 more company';
        hint.style.color = '#ff9800';
      } else {
        hint.textContent = `Comparing ${count} companies`;
        hint.style.color = '#4CAF50';
      }
    }

    // Fetch comparison data from server
    function loadComparisonData() {
      const ids = Array.from(comparisonCompanies.keys()).join(',');

      console.log('🔍 Fetching comparison data for IDs:', ids);

      fetch(`senior_manager.php?action=compare_companies&ids=${ids}`)
        .then(response => {
          console.log('📊 Response status:', response.status);
          return response.text(); // get as text for debugging
        })
        .then(text => {
          console.log('📄 Raw response:', text.substring(0, 500)); // show first 500 chars
          const data = JSON.parse(text);

          if (data.error) {
            throw new Error(data.error);
          }

          comparisonData = data;
          console.log('✅ Comparison data loaded:', comparisonData);
          displayComparisonView(currentComparisonView);
        })
        .catch(err => {
          console.error('❌ Comparison fetch error:', err);
          document.getElementById('comparison-chart').innerHTML = `<div style="text-align: center; padding: 50px; color: #f44336;">Network error loading data: ${err.message}</div>`;
        });
    }

    // Switch between comparison tabs
    function switchComparisonView(view) {
      currentComparisonView = view;

      // Update tab styling
      document.querySelectorAll('.comparison-tab').forEach(tab => {
        tab.classList.remove('active');
        if (tab.dataset.view === view) {
          tab.classList.add('active');
        }
      });

      displayComparisonView(view);
    }

    // Display selected comparison view
    function displayComparisonView(view) {
      if (comparisonData.length < 2) {
        document.getElementById('comparison-chart').innerHTML = '<div style="text-align: center; padding: 50px; color: #666;">Need at least 2 companies to display comparison</div>';
        return;
      }

      createComparisonSummaryTable();

      // Show appropriate chart based on selected tab
      switch (view) {
        case 'financial':
          displayFinancialTrends();
          break;
        case 'disruptions':
          displayDisruptionComparison();
          break;
        case 'metrics':
          displayPerformanceMetrics();
          break;
      }
    }

    // Create summary table at top of modal
    function createComparisonSummaryTable() {
      let html = '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
      html += '<thead><tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">';
      html += '<th style="padding: 10px; text-align: left;">Company</th>';
      html += '<th style="padding: 10px; text-align: left;">Type</th>';
      html += '<th style="padding: 10px; text-align: right;">Health</th>';
      html += '<th style="padding: 10px; text-align: right;">Disruptions</th>';
      html += '<th style="padding: 10px; text-align: right;">Avg Delay</th>';
      html += '<th style="padding: 10px; text-align: right;">Dependencies</th>';
      html += '</tr></thead><tbody>';

      comparisonData.forEach((company, index) => {
        const color = COMPANY_COLORS[index];
        html += `<tr style="border-bottom: 1px solid #eee;">`;
        html += `<td style="padding: 10px;"><span style="display: inline-block; width: 12px; height: 12px; background: ${color}; border-radius: 50%; margin-right: 8px;"></span>${company.name}</td>`;
        html += `<td style="padding: 10px;">${company.type}</td>`;
        html += `<td style="padding: 10px; text-align: right; color: #4CAF50; font-weight: bold;">${company.latest_health}%</td>`;
        html += `<td style="padding: 10px; text-align: right;">${company.total_disruptions}</td>`;
        html += `<td style="padding: 10px; text-align: right;">${company.avg_delay} days</td>`;
        html += `<td style="padding: 10px; text-align: right;">${company.dependencies}</td>`;
        html += `</tr>`;
      });

      html += '</tbody></table>';
      document.getElementById('comparison-summary').innerHTML = html;
    }

    // Display financial health trends over time
    function displayFinancialTrends() {
      const traces = [];

      comparisonData.forEach((company, index) => {
        const periods = company.financial_trend.map(t => t.period);
        const healths = company.financial_trend.map(t => t.health);

        traces.push({
          x: periods,
          y: healths,
          name: company.name,
          type: 'scatter',
          mode: 'lines+markers',
          line: {
            color: COMPANY_COLORS[index],
            width: 2
          },
          marker: {
            size: 6
          }
        });
      });

      const layout = {
        title: 'Financial Health Trends Over Time',
        xaxis: {
          title: 'Time Period'
        },
        yaxis: {
          title: 'Health Score (%)',
          range: [0, 100]
        },
        hovermode: 'closest',
        legend: {
          orientation: 'h',
          y: -0.2
        }
      };

      Plotly.newPlot('comparison-chart', traces, layout, {
        responsive: true
      });
    }

    // Display disruption severity comparison
    function displayDisruptionComparison() {
      const companies = comparisonData.map(c => c.name);

      // Create stacked bar chart with three severity levels
      const highTrace = {
        x: companies,
        y: comparisonData.map(c => c.disruptions.High || 0),
        name: 'High Impact',
        type: 'bar',
        marker: {
          color: '#f44336'
        }
      };

      const mediumTrace = {
        x: companies,
        y: comparisonData.map(c => c.disruptions.Medium || 0),
        name: 'Medium Impact',
        type: 'bar',
        marker: {
          color: '#ff9800'
        }
      };

      const lowTrace = {
        x: companies,
        y: comparisonData.map(c => c.disruptions.Low || 0),
        name: 'Low Impact',
        type: 'bar',
        marker: {
          color: '#4CAF50'
        }
      };

      const layout = {
        title: 'Disruption Severity Comparison',
        xaxis: {
          title: 'Company'
        },
        yaxis: {
          title: 'Number of Disruptions'
        },
        barmode: 'stack',
        legend: {
          orientation: 'h',
          y: -0.2
        }
      };

      Plotly.newPlot('comparison-chart', [lowTrace, mediumTrace, highTrace], layout, {
        responsive: true
      });
    }

    // Display grouped performance metrics
    function displayPerformanceMetrics() {
      const companies = comparisonData.map(c => c.name);

      // Create grouped bar chart with multiple metrics
      const healthTrace = {
        x: companies,
        y: comparisonData.map(c => c.latest_health),
        name: 'Health Score',
        type: 'bar',
        marker: {
          color: '#4CAF50'
        }
      };

      const disruptionTrace = {
        x: companies,
        y: comparisonData.map(c => c.total_disruptions),
        name: 'Total Disruptions',
        type: 'bar',
        marker: {
          color: '#f44336'
        }
      };

      const delayTrace = {
        x: companies,
        y: comparisonData.map(c => c.avg_delay),
        name: 'Avg Delay (days)',
        type: 'bar',
        marker: {
          color: '#ff9800'
        }
      };

      const depTrace = {
        x: companies,
        y: comparisonData.map(c => c.dependencies),
        name: 'Dependencies',
        type: 'bar',
        marker: {
          color: '#2196F3'
        }
      };

      const shipmentTrace = {
        x: companies,
        y: comparisonData.map(c => c.shipment_volume),
        name: 'Shipment Volume',
        type: 'bar',
        marker: {
          color: '#9C27B0'
        }
      };

      const layout = {
        title: 'Performance Metrics Comparison',
        xaxis: {
          title: 'Company'
        },
        yaxis: {
          title: 'Value (normalized)'
        },
        barmode: 'group',
        legend: {
          orientation: 'h',
          y: -0.2
        }
      };

      Plotly.newPlot('comparison-chart', [healthTrace, disruptionTrace, delayTrace, depTrace, shipmentTrace], layout, {
        responsive: true
      });
    }

    // Export comparison data to CSV
    function exportComparisonData() {
      if (comparisonData.length < 2) {
        showNotification('Need at least 2 companies to export', 'warning');
        return;
      }

      let csv = 'Company Comparison Report\n';
      csv += `Generated: ${new Date().toLocaleString()}\n\n`;

      // Summary section
      csv += 'SUMMARY\n';
      csv += 'Company,Type,Health Score,Total Disruptions,Avg Delay (days),Dependencies,Shipment Volume\n';
      comparisonData.forEach(c => {
        csv += `"${c.name}",${c.type},${c.latest_health},${c.total_disruptions},${c.avg_delay},${c.dependencies},${c.shipment_volume}\n`;
      });

      // Financial trends section
      csv += '\n\nFINANCIAL HEALTH TRENDS\n';

      // Get all unique periods across all companies
      const allPeriods = new Set();
      comparisonData.forEach(c => {
        c.financial_trend.forEach(t => allPeriods.add(t.period));
      });
      const periods = Array.from(allPeriods).sort();

      // Header row with company names
      csv += 'Period,' + comparisonData.map(c => `"${c.name}"`).join(',') + '\n';

      // Data rows - one row per period
      periods.forEach(period => {
        csv += period;
        comparisonData.forEach(company => {
          const dataPoint = company.financial_trend.find(t => t.period === period);
          csv += ',' + (dataPoint ? dataPoint.health : '');
        });
        csv += '\n';
      });

      // Create and download CSV file
      const blob = new Blob([csv], {
        type: 'text/csv'
      });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `company_comparison_${new Date().toISOString().slice(0,10)}.csv`;
      a.click();
      window.URL.revokeObjectURL(url);

      showNotification('Comparison data exported', 'success');
    }

    // Check if user has senior manager role and add nav link
    // Source: Fetch API for checking user role
    fetch('role.php')
      .then(response => response.json())
      .then(data => {
        if (!data || data.role !== 'SeniorManager') return;
        const navLinks = document.querySelector('.nav-links');
        if (!navLinks || document.getElementById('SeniorModuleTab')) return;
        const seniorLink = document.createElement('a');
        seniorLink.id = 'SeniorModuleTab';
        seniorLink.href = 'senior_manager.php';
        seniorLink.className = 'active';
        seniorLink.textContent = 'Senior Module';
        navLinks.insertBefore(seniorLink, navLinks.firstChild);
      })
      .catch(err => console.error('Role check failed:', err));
  </script>

  <!-- HTML Lab 1: Modal dialog structure -->
  <!-- Company Comparison Modal -->
  <div id="comparison-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="width: 90%; max-width: 1400px; height: 85vh;">
      <div class="modal-header">
        <h3>Compare Companies</h3>
        <button class="modal-close" onclick="closeComparisonModal()">&times;</button>
      </div>

      <div class="modal-body" style="height: calc(100% - 60px); overflow-y: auto;">
        <!-- Company Selection -->
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
          <label style="font-weight: bold; display: block; margin-bottom: 10px;">Select Companies to Compare:</label>
          <input type="text"
            id="comparison-company-search"
            placeholder="Search companies..."
            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 10px;">
          <div id="comparison-selected-companies" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
            <!-- Selected company chips appear here -->
          </div>
          <div id="comparison-hint" style="color: #666; font-size: 14px;">
            Select 2-5 companies to compare
          </div>
        </div>

        <!-- Chart Tabs -->
        <div style="margin-bottom: 20px; border-bottom: 2px solid #e0e0e0;">
          <button class="comparison-tab active" onclick="switchComparisonView('financial')" data-view="financial">
            Financial Health Trends
          </button>
          <button class="comparison-tab" onclick="switchComparisonView('disruptions')" data-view="disruptions">
            Disruption Comparison
          </button>
          <button class="comparison-tab" onclick="switchComparisonView('metrics')" data-view="metrics">
            Performance Metrics
          </button>
        </div>

        <!-- Summary Table -->
        <div id="comparison-summary" style="margin-bottom: 20px;">
          <!-- JS will populate summary table here -->
        </div>

        <!-- Chart Container -->
        <div id="comparison-chart" style="width: 100%; height: 500px;">
          <!-- Plotly chart will render here -->
        </div>

        <!-- Export Button -->
        <div style="margin-top: 20px; text-align: right;">
          <button onclick="exportComparisonData()"
            style="padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">
            Export Comparison Data
          </button>
        </div>
      </div>
    </div>
  </div>

</body>

</html>