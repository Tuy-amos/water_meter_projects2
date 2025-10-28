<?php
// Smart Water Meter Dashboard Logic

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "smart water meter"; 

$conn = new mysqli($servername, $username, $password, $dbname);
$connError = null;
$lastReading = null;
$goalFetchError = null;

$USAGE_GOAL_MONTHLY = 0.0;
$USAGE_GOAL_PERIOD = 'Monthly'; 
$goalSourceMessage = "Goal is currently set to the default value of 0.0 L.";
$currentCumulativeVolume = 0.0; 
$displayCumulativeVolume = '--'; 

if ($conn->connect_error) {
    $connError = "Connection failed: " . $conn->connect_error;
} else {
    // 1. Fetch the last sensor reading
    $sql = "SELECT id, timestamp, temperature, tds_value, turbidity_value, flow_rate, total_volume FROM users ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $lastReading = $result->fetch_assoc();
    }
    
    // 2. Fetch the current cumulative volume from its dedicated log table
    $sqlCumulative = "SELECT cumulative_volume FROM cumulative_volume_log WHERE id = 1 LIMIT 1";
    $resultCumulative = $conn->query($sqlCumulative);
    
    if ($resultCumulative && $resultCumulative->num_rows > 0) {
        $currentCumulativeVolume = floatval($resultCumulative->fetch_assoc()['cumulative_volume']); 
        $displayCumulativeVolume = number_format($currentCumulativeVolume, 3);
    } else {
        // Fallback: use the last reading's total_volume as the initial cumulative volume if log is empty
        if ($lastReading) {
            // NOTE: This assumes the 'total_volume' in 'users' is the incremental volume,
            // so this fallback is somewhat flawed but kept for consistency with original intent.
            $currentCumulativeVolume = floatval($lastReading['total_volume']);
            $displayCumulativeVolume = number_format($currentCumulativeVolume, 3);
        }
    }
    
    // 3. Fetch the usage goal
    $sqlGoal = "SELECT target_amount,goal_period FROM goals ORDER BY id DESC LIMIT 1";
    $resultGoal = $conn->query($sqlGoal);

    if ($resultGoal) {
        if ($resultGoal->num_rows > 0) {
            $fetchedGoalRow = $resultGoal->fetch_assoc();
            $fetchedGoal = floatval($fetchedGoalRow['target_amount']);
            $fetchedPeriod = $fetchedGoalRow['goal_period'] ?? 'Monthly'; 
            $USAGE_GOAL_MONTHLY = $fetchedGoal;
            $USAGE_GOAL_PERIOD = $fetchedPeriod; 
            $goalSourceMessage = "Goal set by user to {$USAGE_GOAL_MONTHLY} L (Period: {$USAGE_GOAL_PERIOD}).";
        } else {
            $goalFetchError = "No goal record found, Please set a goal.";
        }
    } else {
        $goalFetchError = "Could not retrieve a goal: " . $conn->error;
    }
}

$dateLabels = [];
$dailyUsages = [];

// --- FIX 2: Correcting Daily Usage Chart Data Calculation ---
if (!$connError && $conn) {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dateLabels[] = date('D, M d', strtotime("-$i days"));
        $startTime = $date . ' 00:00:00';
        // If it's today ($i=0), set end time to current time; otherwise, end of the day.
        $endTime = ($i == 0) ? date('Y-m-d H:i:s') : $date . ' 23:59:59'; 

        // CORRECTED SQL: Calculate usage by summing up the total_volume (incremental flow) 
        // recorded within the specific day's time boundaries.
        $sqlDailyUsage = "SELECT SUM(total_volume) AS daily_usage FROM users 
                          WHERE timestamp >= '$startTime' AND timestamp <= '$endTime'";
                          
        $resultDailyUsage = $conn->query($sqlDailyUsage);
        $dailyUsage = 0.0;

        if ($resultDailyUsage && $resultDailyUsage->num_rows > 0) {
            $row = $resultDailyUsage->fetch_assoc();
            // Use coalesce to handle null if no usage was recorded for the day
            $dailyUsage = floatval($row['daily_usage'] ?? 0.0);
        }
        
        $dailyUsages[] = round($dailyUsage, 2);
    }
}
$chartDataLabelsJson = json_encode($dateLabels);
$chartDataValuesJson = json_encode($dailyUsages);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$connError && $conn) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents("php://input"), true);

    if (isset($data['command']) && ($data['command'] === 'ON' || $data['command'] === 'OFF')) {
        $command = $data['command'];
        $update_sql = "INSERT INTO device_state (id, valve_status) VALUES (1, ?)
                      ON DUPLICATE KEY UPDATE valve_status = ?";
        
        $stmt = $conn->prepare($update_sql);
        
        if ($stmt) {
            $stmt->bind_param("ss", $command, $command);

            if ($stmt->execute()) {
                echo json_encode(["status" => "success", "message" => "Valve control set to $command."]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Database error updating valve status: " . $stmt->error]);
            }
            $stmt->close();
        } else {
             http_response_code(500);
             echo json_encode(["status" => "error", "message" => "Failed to prepare SQL statement for valve control: " . $conn->error]);
        }
        
        $conn->close();
        exit(); 
    }
    elseif (isset($data['temperature']) &&
             isset($data['tds_value']) && isset($data['turbidity_value']) &&
             isset($data['flow_rate']) && isset($data['total_volume'])) {

        $temperature = (float)$data['temperature'];
        $tds_value = (float)$data['tds_value'];
        $turbidity_value = (float)$data['turbidity_value'];
        $flow_rate = (float)$data['flow_rate'];
        $total_volume = (float)$data['total_volume']; // This is the incremental volume, or Delta_V
        
        $current_timestamp = date('Y-m-d H:i:s');
        
        $success = true;
        $sensor_log_message = "Sensor data logged successfully.";

        // Log the incremental reading to the 'users' table
        $sql = "INSERT INTO users (timestamp, temperature, tds_value, turbidity_value, flow_rate, total_volume)
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("sddddd", $current_timestamp, $temperature, $tds_value, $turbidity_value, $flow_rate, $total_volume);

            if (!$stmt->execute()) {
                $sensor_log_message = "Database error logging sensor data: " . $stmt->error;
                $success = false;
            }
            $stmt->close();
        } else {
            $sensor_log_message = "Failed to prepare SQL statement for sensor data: " . $conn->error;
            $success = false;
        }
        $cumulative_volume_response = 0.0;
        $cumulative_log_message = '';

        if ($success) {
            // Update the running total (cumulative volume log)
            $cumulative_sql = "SELECT cumulative_volume FROM cumulative_volume_log WHERE id = 1 LIMIT 1";
            $cumulative_result = $conn->query($cumulative_sql);
            
            $new_cumulative_volume = $total_volume; 
            
            if ($cumulative_result && $cumulative_result->num_rows > 0) {
                $state_row = $cumulative_result->fetch_assoc();
                $cumulative_volume = (float)$state_row['cumulative_volume'];
                // Correctly add the incremental volume to the cumulative total
                $new_cumulative_volume = $cumulative_volume + $total_volume;
                
                $update_sql = "UPDATE cumulative_volume_log SET 
                                 cumulative_volume = ?
                                 WHERE id = 1";
                
                $stmt_update = $conn->prepare($update_sql);
                if ($stmt_update) {
                    $stmt_update->bind_param("d", $new_cumulative_volume);
                    if (!$stmt_update->execute()) {
                        $cumulative_log_message = " | Cumulative update failed: " . $stmt_update->error;
                        $success = false;
                    }
                    $stmt_update->close();
                } else {
                    $cumulative_log_message = " | Failed to prepare cumulative update: " . $conn->error;
                    $success = false;
                }

            } else {
                // Initialize the cumulative log if it doesn't exist
                $insert_init_sql = "INSERT INTO cumulative_volume_log (id, cumulative_volume) 
                                    VALUES (1, ?)";
                $stmt_init = $conn->prepare($insert_init_sql);
                if ($stmt_init) {
                    $stmt_init->bind_param("d", $new_cumulative_volume);
                    if (!$stmt_init->execute()) {
                         $cumulative_log_message = ' (Cumulative log initialization failed: ' . $stmt_init->error . ')';
                         $success = false;
                    } else {
                         $cumulative_log_message = ' (Cumulative log initialized)';
                    }
                    $stmt_init->close();
                } else {
                    $cumulative_log_message = " | Failed to prepare cumulative insert: " . $conn->error;
                    $success = false;
                }
            }
            
            $cumulative_volume_response = round($new_cumulative_volume, 3);
        }
        if ($success) {
            echo json_encode([
                "status" => "success", 
                "message" => $sensor_log_message . $cumulative_log_message,
                "cumulative_volume" => $cumulative_volume_response
            ]);
        } else {
             http_response_code(500);
             echo json_encode([
                 "status" => "error", 
                 "message" => "Data processing failed: " . $sensor_log_message . $cumulative_log_message
             ]);
        }

        $conn->close();
        exit(); 
    }
}
$valveStatus = 'OFF';
if (!$connError && $conn) {
    $sqlValve = "SELECT valve_status FROM device_state WHERE id = 1 LIMIT 1";
    $resultValve = $conn->query($sqlValve);
    if ($resultValve && $resultValve->num_rows > 0) {
        $valveStatus = $resultValve->fetch_assoc()['valve_status'];
    }
}
$initialValveIsOn = $valveStatus === 'ON';
$initialValveClass = $initialValveIsOn ? 'on' : '';
$initialValveText = $initialValveIsOn ? 'OPEN (ON)' : 'CLOSED (OFF)';
$initialValveColor = $initialValveIsOn ? 'text-data-green' : 'text-data-red';
$initialValveCardClass = $initialValveIsOn ? 'on border-data-green' : 'off border-data-red'; 

$tdsValue = $lastReading ? intval($lastReading['tds_value']) : 0;
$turbidityValue = $lastReading ? floatval($lastReading['turbidity_value']) : 0.0;

// --- FIX 1: Correcting the getUsage Function ---
/**
 * Calculates water usage for a given time interval by summing up incremental 'total_volume' records.
 * The $currentVolume parameter is now unused, as the calculation is done via SUM().
 * @param mysqli $conn The database connection object.
 * @param string $interval The interval to check ('1 DAY', '7 DAY', '30 DAY').
 * @return float The total usage in Liters for the period.
 */
function getUsage($conn, $interval) {
    if (!$conn) return 0.0;
    $safeIntervals = ['1 DAY', '7 DAY', '30 DAY'];
    if (!in_array($interval, $safeIntervals)) {
        error_log("Attempted SQL Injection via invalid interval: " . $interval);
        return 0.0; 
    }

    // CORRECTED SQL: Sum all incremental total_volume entries since the start of the interval
    $sql = "SELECT SUM(total_volume) AS total_usage_in_period 
            FROM users 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $interval)";
            
    $result = $conn->query($sql);

    if ($result) {
        $row = $result->fetch_assoc();
        // Use coalesce to handle null if no usage was recorded in the period
        $usage = floatval($row['total_usage_in_period'] ?? 0.0);
        return max(0.0, $usage);
    }
    
    // Return 0.0 if query fails
    return 0.0;
}

$usageDay = 0.0;
$usageWeek = 0.0;
$usageMonth = 0.0;

// Calling the corrected getUsage function (removing the $currentCumulativeVolume parameter)
if (!$connError && $conn) {
    $usageDay = getUsage($conn, '1 DAY');
    $usageWeek = getUsage($conn, '7 DAY');
    $usageMonth = getUsage($conn, '30 DAY');
}

$currentGoalUsage = 0.0;
switch (strtolower($USAGE_GOAL_PERIOD)) {
    case 'daily':
    case 'day':
        $currentGoalUsage = $usageDay;
        break;
    case 'weekly':
    case 'week':
        $currentGoalUsage = $usageWeek;
        break;
    case 'monthly':
    case 'month':
    default:
        $currentGoalUsage = $usageMonth;
        break;
}

$usageToGoal = 0.0;
$goalStatusMessage = '';
$goalStatusClass = '';

if ($USAGE_GOAL_MONTHLY > 0) {
    $usageToGoal = $USAGE_GOAL_MONTHLY - $currentGoalUsage; 

    if ($usageToGoal > 1000) {
        $goalStatusMessage = "You are well under your {$USAGE_GOAL_PERIOD} goal! Great job on conservation.";
        $goalStatusClass = "bg-green-900/30 border-data-green text-green-300";
    } elseif ($usageToGoal > 0) {
        $goalStatusMessage = "You are currently under your {$USAGE_GOAL_PERIOD} goal. Keep monitoring usage closely!";
        $goalStatusClass = "bg-blue-900/30 border-water-blue text-blue-300";
    } elseif ($usageToGoal > -500) {
        $goalStatusMessage = "You are slightly over budget for your {$USAGE_GOAL_PERIOD} goal. Consider reducing consumption.";
        $goalStatusClass = "bg-orange-900/30 border-orange-500 text-orange-300";
    } else {
        $goalStatusMessage = "You have significantly exceeded your {$USAGE_GOAL_PERIOD} goal. Review your usage patterns.";
        $goalStatusClass = "bg-red-900/30 border-data-red text-red-300";
    }
} else {
    $usageToGoal = $USAGE_GOAL_MONTHLY;
    $goalStatusMessage = "Start tracking usage! Your {$USAGE_GOAL_PERIOD} goal is {$USAGE_GOAL_MONTHLY} L.";
    $goalStatusClass = "bg-gray-700/50 border-gray-500 text-gray-300";
}

$displayGoalMonthly = number_format($USAGE_GOAL_MONTHLY, 0);
$displayUsageToGoal = number_format(abs($usageToGoal), 2);
$isUnderGoal = $usageToGoal >= 0;
$goalPeriod = $USAGE_GOAL_PERIOD; 
$goalUnit = "Liters (L)";
$used = round($currentGoalUsage, 2);
$target = round($USAGE_GOAL_MONTHLY, 2);

if ($target > 0) {
    if ($used <= $target) {
        $remaining = max(0, $target - $used);
        $goalChartData = json_encode([$used, $remaining]);
        $goalChartLabels = json_encode(['Used', 'Remaining']);
        $goalChartColors = json_encode(['#4c7cff', '#10b981']); 
        $goalPrimaryMetric = number_format($remaining, 0);
        $goalMetricLabel = "Remaining";
    } else {
        $exceeded = $used - $target;
        $goalChartData = json_encode([$target, $exceeded]);
        $goalChartLabels = json_encode(['Goal Budget Used', 'Exceeded']);
        $goalChartColors = json_encode(['#4b5563', '#ef4444']);
        $goalPrimaryMetric = number_format($exceeded, 0);
        $goalMetricLabel = "Exceeded";
    }
} else {
    $goalChartData = json_encode([$used, 1]);
    $goalChartLabels = json_encode(['Used', 'No Goal']);
    $goalChartColors = json_encode(['#4c7cff', '#4b5563']);
    $goalPrimaryMetric = number_format($used, 0);
    $goalMetricLabel = "Used";
}

$RATE_PER_CUBIC_METER = 863.0; 
$LITERS_PER_CUBIC_METER = 1000.0; 
$RATE_PER_LITER = $RATE_PER_CUBIC_METER / $LITERS_PER_CUBIC_METER;

$totalCost = $currentCumulativeVolume * $RATE_PER_LITER; 
$totalVolumeCubicMeters = $currentCumulativeVolume / $LITERS_PER_CUBIC_METER; 

$displayRatePerM3 = number_format($RATE_PER_CUBIC_METER, 0);
$displayTotalCost = $lastReading ? number_format($totalCost, 2) : '--';
$displayVolumeM3 = $lastReading ? number_format($totalVolumeCubicMeters, 3) : '--';

$displayFlow = $lastReading ? number_format($lastReading['flow_rate'], 2) : '--';
$displayTurbidity = $lastReading ? number_format($turbidityValue, 1) : '--';
$displayTds = $lastReading ? intval($tdsValue) : '--';
$displayTemp = $lastReading ? number_format($lastReading['temperature'], 1) : '--';

$displayUsageDay = number_format($usageDay);
$displayUsageWeek = number_format($usageWeek);
$displayUsageMonth = number_format($usageMonth);

$displayTimestamp = $lastReading ? date('Y-m-d H:i:s', strtotime($lastReading['timestamp'])) : 'N/A';

$statusMessage = "No current data to assess quality.";
$statusClass = "bg-gray-700/50 border-gray-500 text-gray-300";

if ($lastReading) {
    $statusMessage = "✅ Water Quality is currently **Excellent** and meets typical drinking standards.";
    $statusClass = "bg-green-900/30 border-data-green text-green-300";

    if ($turbidityValue > 5.0) {
        $statusMessage = "🛑 **WARNING: HIGH TURBIDITY ({$displayTurbidity} NTU).** The water is very cloudy. Consumption is NOT recommended.";
        $statusClass = "bg-red-900/30 border-data-red text-red-300";
    }
    else if ($tdsValue > 300) {
        $statusMessage = "❗ **ALERT: High TDS ({$displayTds} ppm).** The water quality is poor. Consider using filtration or further testing.";
        $statusClass = "bg-orange-900/30 border-orange-500 text-orange-300";
    }
    else if ($tdsValue > 150) {
        $statusMessage = "🔸 **Note: Elevated TDS ({$displayTds} ppm).** Water is typically safe but monitor quality closely.";
        $statusClass = "bg-blue-900/30 border-water-blue text-blue-300";
    }
    else if (floatval($displayFlow) == 0 && $currentCumulativeVolume > 0) {
        $statusMessage = "🔄 No flow detected, but total volume is high. Is the water supply currently off?";
        $statusClass = "bg-yellow-900/30 border-yellow-500 text-yellow-300";
    }
}
if ($_SERVER['REQUEST_METHOD'] != 'POST' && !$connError && $conn) {
    $conn->close();
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Water Meter Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        'water-blue': '#4c7cff', 
                        'data-green': '#10b981', 
                        'data-red': '#ef4444',    
                        'data-purple': '#a78bfa', 
                        'orange-500': '#f97316',
                        'yellow-500': '#eab308',
                        'money-green': '#059669', 
                        'sidebar-bg': '#111827', 
                        'sidebar-link': '#9ca3af', 
                        'sidebar-active': '#4c7cff', 
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #1f2937; color: #f3f4f6; } 
        .metric-card {
            background-color: #374151; 
            border: 1px solid #4b5563; 
            border-radius: 0.75rem; 
            padding: 1.5rem; 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4), 0 4px 6px -2px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 20px -5px rgba(0, 0, 0, 0.5);
        }
        .sidebar {
            background-color: #111827; 
            width: 16rem; 
            min-height: 100vh;
        }
        .sidebar-link {
            transition: all 0.2s;
            border-radius: 0.5rem;
        }
        .sidebar-link:hover {
            background-color: rgba(76, 124, 255, 0.1); 
            color: #4c7cff;
        }
        @media (min-width: 768px) {
            .app-container {
                display: flex;
            }
            .sidebar {
                position: sticky;
                top: 0;
                height: 100vh;
            }
        }
        @media (max-width: 767px) {
            .sidebar {
                width: 100%;
                min-height: auto;
                border-bottom: 1px solid #374151;
            }
        }
        .toggle-switch {
            width: 70px;
            height: 35px;
            background-color: #4b5563; 
            border-radius: 9999px;
            position: relative;
            cursor: pointer;
            transition: background-color 0.4s ease, box-shadow 0.4s ease;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.4);
            user-select: none;
        }

        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 31px; 
            height: 31px;
            background-color: #f3f4f6; 
            border-radius: 50%;
            transition: transform 0.4s cubic-bezier(0.25, 0.8, 0.5, 1.0), box-shadow 0.4s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }

        .toggle-switch.on {
            background-color: #10b981; 
            box-shadow: 0 0 8px 2px rgba(16, 185, 129, 0.6);
        }

        .toggle-switch.on::after {
            transform: translateX(35px);
        }
        @keyframes flow-animation {
            0% { stroke-dashoffset: 0; }
            100% { stroke-dashoffset: -20; }
        }
        @keyframes pulse-shadow-green {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            50% { box-shadow: 0 0 10px 5px rgba(16, 185, 129, 0.7); }
        }
        #valve-svg circle, #valve-svg #valve-handle {
            transition: all 0.5s cubic-bezier(0.25, 0.8, 0.5, 1.0);
        }
        .valve-on #flow-path {
            opacity: 1;
            animation: flow-animation 1s linear infinite;
            stroke: #4c7cff; 
        }

        .valve-on #valve-handle {
            transform-origin: 50% 50%;
            transform: translate(0px, -20px) rotate(90deg) scale(0.7); 
            fill: #10b981; 
        }
        .valve-on circle {
            fill: #10b981; 
            stroke: #34d399;
            animation: pulse-shadow-green 2s infinite;
        }
        .valve-on rect:not(#valve-handle) { 
            fill: #4b5563;
        }
        .valve-off #flow-path {
            opacity: 0;
            animation: none;
        }
        .valve-off #valve-handle {
            transform-origin: 50% 50%;
            transform: translate(0px, 0px) rotate(0deg) scale(1); 
            fill: #9ca3af; 
        }
        .valve-off circle {
            fill: #ef4444; 
            stroke: #f87171; 
            box-shadow: none;
            animation: none;
        }
        .valve-off rect:not(#valve-handle) { 
            fill: #374151;
        }
        #valve.on {
            border-color: #10b981; 
        }
        #valve.off {
            border-color: #ef4444; 
        }

    </style>
</head>
<body class="font-sans">
    <div class="app-container">
        <aside class="sidebar p-6 flex-shrink-0 z-10">
            <div class="text-center mb-10">
                <h1 class="text-3xl font-extrabold text-water-blue tracking-tight">Smart Water meter</h1>
                <p class="text-xs text-gray-400">Smart Conservation and water Efficiency</p>
            </div>

        <nav class="space-y-2">
            <a href="#overview" class="sidebar-link block p-3 text-sidebar-link hover:text-water-blue font-semibold flex items-center">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                Dashboard Overview
            </a>
            <a href="Table.php" class="sidebar-link block p-3 text-sidebar-link hover:text-water-blue font-semibold flex items-center">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18M3 18h18M3 6h18V4a2 2 0 00-2-2H5a2 2 0 00-2 2v2zm0 16a2 2 0 002 2h14a2 2 0 002-2v-4H3v4z"></path>
                </svg>
                Table for received data form hardware
            </a>
            <a href="GoalTable.php" class="sidebar-link block p-3 text-sidebar-link hover:text-water-blue font-semibold flex items-center">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10m-8-4v4l8 4 8-4m-8 4V7"></path>
                </svg>
                Table of A setted goal
            </a>
            <a href="#billing" class="sidebar-link block p-3 text-water-blue font-semibold flex items-center bg-water-blue/10">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                First User
            </a>
            <a href="#valve" class="sidebar-link block p-3 text-sidebar-link hover:text-data-red font-semibold flex items-center">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Second User
            </a>
        </nav>
        
            <div class="mt-auto pt-8 border-t border-gray-700 text-sm text-gray-500 text-center">
                <p>Last Data Point:</p>
                <p id="display-timestamp-sidebar" class="font-medium"><?= $displayTimestamp ?></p>
            </div>
        </aside>

        <main class="flex-grow p-6 md:p-10 max-w-7xl w-full">
            
            <header id="overview" class="mb-10 pt-4">
                <h1 class="text-4xl font-extrabold text-gray-100 tracking-tight border-b border-gray-700 pb-4">
                   Main Dashboard Overview
                </h1>
                <p class="text-gray-400 mt-2">Real-time water quality, consumption analysis, and device control.</p>
            </header>
            
            <?php if ($connError): ?>
                <div class="p-4 mb-8 bg-red-900/30 border-l-4 border-data-red text-red-300 rounded-xl shadow-lg font-medium" role="alert">
                    <p class="font-bold">Database Connection Error:</p>
                    <p><?php echo htmlspecialchars($connError); ?></p>
                </div>
            <?php elseif (!$lastReading): ?>
                <div class="p-4 mb-8 bg-yellow-900/30 border-l-4 border-yellow-500 text-yellow-300 rounded-xl shadow-lg font-medium" role="alert">
                    No data records found. Ensure your meter is connected and sending data to the database.
                </div>
            <?php else: ?>
                <div id="quality" class="p-4 mb-8 border-l-4 rounded-xl shadow-2xl font-medium <?= $statusClass ?> bg-gray-800/50" role="alert">
                    <p class="font-bold text-xl mb-1 drop-shadow-md">Current Water Quality Status</p>
                    <p><?= $statusMessage ?></p>
                </div>
            <?php endif; ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                
                    <div class="metric-card bg-gray-800/50 border-l-4 border-water-blue text-center h-70 flex flex-col justify-center">
                       <p class="text-sm font-medium text-water-blue uppercase">Total Volume</p>
                       <p class="text-4xl font-extrabold text-water-blue mt-1"><?= $displayCumulativeVolume ?> <span class="text-lg text-gray-400">L</span></p>
                       <p class="text-xs text-gray-400 mt-1">Meter Reading</p>
                   </div>

                   <div class="metric-card bg-gray-800/50 border-l-4 border-data-purple text-center h-70 flex flex-col justify-center">
                       <p class="text-sm font-medium text-data-purple uppercase">Total Volume</p>
                       <p class="text-4xl font-extrabold text-data-purple mt-1"><?= $displayVolumeM3 ?> <span class="text-lg text-gray-400">m³</span></p>
                       <p class="text-xs text-gray-400 mt-1">Meter Reading (m³)</p>
                   </div>

                   <div class="metric-card bg-gray-800/50 border-l-4 border-data-green text-center h-70 flex flex-col justify-center">
                       <p class="text-sm font-medium text-data-green uppercase">Flow Rate</p>
                       <p class="text-4xl font-extrabold text-data-green mt-1"><?= $displayFlow ?> <span class="text-lg text-gray-400">L/min</span></p>
                       <p class="text-xs text-gray-400 mt-1">Current Measurement</p>
                   </div>

                <div id="valve" class="metric-card bg-gray-800/50 border-l-4 <?= $initialValveCardClass ?> flex flex-col justify-between items-center text-center">
                    <h2 class="text-lg font-bold text-gray-100 mb-2 flex items-center">
                        <svg class="w-5 h-5 mr-2 <?= $initialValveColor ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0l-10 10m10 0v-6"></path></svg>
                        Main Valve
                    </h2>

                    <div id="valve-graphic-container" class="my-4 w-full flex justify-center">
                        <svg id="valve-svg" class="w-24 h-24 <?= $initialValveIsOn ? 'valve-on' : 'valve-off' ?>" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="10" y="45" width="80" height="10" rx="2" class="fill-gray-600 transition-colors duration-500"/>

                            <path id="flow-path" d="M10 50 L90 50" stroke-dasharray="10 10" stroke-width="6" class="stroke-water-blue opacity-0 transition-opacity duration-500"/>
                
                            <circle cx="50" cy="50" r="20" class="stroke-gray-400 stroke-2 fill-gray-900 transition-all duration-500" style="transition-property: all;"/>

                            <rect id="valve-handle" x="47" y="20" width="6" height="30" rx="1" class="fill-gray-400 transition-transform duration-500"/>
                        </svg>
                    </div>

                    <div class="flex flex-col items-center w-full">
                        <p id="valve-status-text" class="text-2xl font-extrabold <?= $initialValveColor ?> transition-colors duration-500 mb-3 drop-shadow-lg"><?= $initialValveText ?></p>
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-data-red font-semibold">OFF</span>
                            <div id="valve-toggle" class="toggle-switch <?= $initialValveClass ?>"></div>
                            <span class="text-sm text-data-green font-semibold">ON</span>
                        </div>
                    </div>
                    <p id="valve-message" class="text-xs mt-3 text-gray-500 italic h-4">Tap the switch to change state.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">  
                <div class="lg:col-span-2 space-y-8">
                    <div id="consumption" class="metric-card">
                        <h2 class="text-2xl font-bold text-gray-100 mb-6 flex items-center border-b border-gray-600 pb-2">
                            <svg class="w-6 h-6 mr-3 text-water-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Recent Water Consumption
                        </h2>
                        <div class="grid grid-cols-3 gap-4 text-center">
                            
                            <div class="p-4 bg-blue-900/30 rounded-xl shadow-lg border-b-4 border-water-blue">
                                <p class="text-xs font-medium text-water-blue uppercase tracking-wider">Today's Usage</p>
                                <p class="text-4xl font-extrabold text-water-blue mt-1">
                                    <?= $displayUsageDay ?> <span class="text-lg font-semibold text-gray-400">L</span>
                                </p>
                            </div>
                            
                            <div class="p-4 bg-green-900/30 rounded-xl shadow-lg border-b-4 border-data-green">
                                <p class="text-xs font-medium text-data-green uppercase tracking-wider">Weekly Usage</p>
                                <p class="text-4xl font-extrabold text-data-green mt-1">
                                    <?= $displayUsageWeek ?> <span class="text-lg font-semibold text-gray-400">L</span>
                                </p>
                            </div>

                            <div class="p-4 bg-purple-900/30 rounded-xl shadow-lg border-b-4 border-data-purple">
                                <p class="text-xs font-medium text-data-purple uppercase tracking-wider">Monthly Usage</p>
                                <p class="text-4xl font-extrabold text-data-purple mt-1">
                                    <?= $displayUsageMonth ?> <span class="text-lg font-semibold text-gray-400">L</span>
                                </p>
                            </div>
                        </div>

                        <div class="mt-8">
                            <h3 class="text-xl font-semibold text-gray-200 mb-4">Last 7 Days Consumption Trend</h3>
                            <div class="h-64">
                                <canvas id="dailyUsageChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div id="quality-details" class="metric-card">
                        <h2 class="text-2xl font-bold text-gray-100 mb-6 flex items-center border-b border-gray-600 pb-2">
                            <svg class="w-6 h-6 mr-3 text-data-green" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            Water Quality Parameters
                        </h2>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-center">
                            
                            <div class="p-4 bg-gray-900/40 rounded-lg">
                                <p class="text-lg font-bold text-data-red"><?= $displayTds ?></p>
                                <p class="text-xs text-gray-400 uppercase">TDS (ppm)</p>
                            </div>
                            
                            <div class="p-4 bg-gray-900/40 rounded-lg">
                                <p class="text-lg font-bold text-water-blue"><?= $displayTurbidity ?></p>
                                <p class="text-xs text-gray-400 uppercase">Turbidity (NTU)</p>
                            </div>
                            <div class="p-4 bg-gray-900/40 rounded-lg">
                                <p class="text-lg font-bold text-orange-500"><?= $displayTemp ?>°C</p>
                                <p class="text-xs text-gray-400 uppercase">Temperature</p>
                            </div>

                        </div>
                        <p class="text-xs text-gray-500 italic mt-4"><?= $goalSourceMessage ?></p>
                    </div>
                       <details class="bg-gray-800 shadow-2xl rounded-xl p-6 mb-8 border border-gray-700">
                                  <summary class="text-2xl font-bold text-gray-100 flex items-center cursor-pointer relative list-none">
                                      <svg class="w-6 h-6 mr-3 text-water-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                      Water Status Guidelines
                                  </summary>
                                  
                                  <div class="space-y-4 pt-4 border-t border-gray-700 mt-4">
                                      <div class="p-3 bg-green-900/30 border-l-4 border-data-green rounded">
                                          <p class="font-semibold text-green-300">✅ Excellent Quality (TDS < 150 ppm, Turbidity $\le$ 1.0 NTU)</p>
                                          <p class="text-sm text-green-400">Water is safe, clean, and meets high drinking standards. No action required.</p>
                                      </div>
                                      <div class="p-3 bg-blue-900/30 border-l-4 border-water-blue rounded">
                                          <p class="font-semibold text-blue-300">🔸 Elevated TDS (TDS 150 - 300 ppm)</p>
                                          <p class="text-sm text-blue-400">Total Dissolved Solids are elevated. The water is typically safe but may have a noticeable taste. Monitor closely and consider basic filtration.</p>
                                      </div>
                                      <div class="p-3 bg-orange-900/30 border-l-4 border-orange-500 rounded">
                                          <p class="font-semibold text-orange-300">❗ High TDS Alert (TDS > 300 ppm)</p>
                                          <p class="text-sm text-orange-400">The high level of solids suggests poor water quality. Consumption is not recommended without further testing or advanced purification (e.g., Reverse Osmosis).</p>
                                      </div>
                                      <div class="p-3 bg-red-900/30 border-l-4 border-data-red rounded">
                                          <p class="font-semibold text-red-300">🛑 High Turbidity Warning (Turbidity > 5.0 NTU)</p>
                                          <p class="text-sm text-red-400">The water is very cloudy. This indicates contamination (solids, sediment, or biological matter). **Consumption is strongly NOT recommended.**</p>
                                      </div>
                                      <div class="p-3 bg-yellow-900/30 border-l-4 border-yellow-500 rounded">
                                          <p class="font-semibold text-yellow-300">🔄 No Flow Detected (Flow = 0 L/min, Volume > 0 L)</p>
                                          <p class="text-sm text-yellow-400">The meter indicates total volume used is non-zero, but no water is currently flowing. This usually means the main water supply is turned off.</p>
                                      </div>
                                  </div>
                          </details>
                </div>
                <div class="space-y-8">
    <div id="goal-tracking" class="metric-card border-l-4 border-data-purple">
        <h2 class="text-2xl font-bold text-gray-100 mb-6 flex items-center border-b border-gray-600 pb-2">
            <svg class="w-6 h-6 mr-3 text-data-purple" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            Goal Setter Tracking
        </h2>
        <div class="text-center mb-6">
            <p class="text-sm font-medium text-gray-400 uppercase tracking-wider">Target Goal (<?= $goalPeriod ?>)</p>
            <p class="text-4xl font-extrabold text-data-purple mt-1">
                <?= $displayGoalMonthly ?> <span class="text-xl font-semibold text-gray-400"><?= $goalUnit ?></span>
            </p>
            <p class="text-xs italic mt-1 <?= $goalStatusClass ?> p-1 rounded-full inline-block px-3 transition-all duration-500"><?= $goalStatusMessage ?></p>
        </div>
        <div class="relative flex justify-center">
            <div class="w-48 h-48">
                <canvas id="goalDoughnutChart"></canvas>
            </div>
            <div id="goal-chart-center" class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                <p class="text-sm font-medium text-gray-400 uppercase"><?= $goalMetricLabel ?></p>
                <p class="text-3xl font-extrabold text-gray-100"><?= number_format($usageToGoal, 0) ?></p>
                <p class="text-sm text-gray-500"><?= $goalUnit ?></p>
            </div>
        </div>
                        <div>
                            <div class="mt-6 flex justify-around text-center text-sm">
                                <div>
                                      <span class="inline-block w-3 h-3 rounded-full bg-water-blue mr-2"></span>
                                      <span class="text-gray-400">Used:</span>
                                      <span class="font-bold text-water-blue"><?= number_format($used ?? 0, 0) ?> L</span>
                                  </div>
                                  <div>
                                      <?php if ($isUnderGoal): ?>
                                          <span class="inline-block w-3 h-3 rounded-full bg-data-green mr-2"></span>
                                          <span class="text-gray-400">Per:</span>
                                          <span class="font-bold text-data-green"><?= strtolower(htmlspecialchars($USAGE_GOAL_PERIOD ?? 'N/A')) ?></span>
                                      <?php else: ?>
                                          <span class="inline-block w-3 h-3 rounded-full bg-data-red mr-2"></span>
                                          <span class="text-gray-400">Over:</span>
                                          <span class="font-bold text-data-red"><?= number_format(abs($usageToGoal ?? 0), 0) ?> L</span>
                                      <?php endif; ?>
                                  </div>
                              </div>
                      
                          </div>
                              <div class="text-center mt-3">
                                  <a href="BackendforGoal.php" class="inline-flex items-center justify-center px-8 py-3 border border-transparent text-base font-medium rounded-full shadow-lg text-white bg-data-purple hover:bg-purple-700 transition duration-300 transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-purple-500 focus:ring-opacity-50">
                                      <svg class="w-5 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                      Set Goal and Review Analysis
                                  </a>
                              </div>
                      </div>

                    <div id="billing" class="metric-card border-l-4 border-money-green">
                        <h2 class="text-2xl font-bold text-gray-100 mb-6 flex items-center border-b border-gray-600 pb-2">
                            <svg class="w-6 h-6 mr-3 text-money-green" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                            Billing Summary
                        </h2>
                        
                        <div class="space-y-4">
                            <div class="p-4 bg-gray-900/40 rounded-lg">
                                <p class="text-sm font-medium text-gray-400 uppercase">Payment Total Cost</p>
                                <p class="text-3xl font-extrabold text-money-green mt-1">
                                    FRW  <?= $displayTotalCost ?>
                                </p>
                            </div>
                            
                            <div class="p-4 bg-gray-900/40 rounded-lg">
                                <p class="text-sm font-medium text-gray-400 uppercase">Usage liters</p>
                                <p class="text-3xl font-extrabold text-data-purple mt-1">
                                    <?= $displayCumulativeVolume ?> <span class="text-lg font-semibold text-gray-400">L</span>
                                </p>
                            </div>

                            <div class="p-4 bg-gray-900/40 rounded-lg">
                                <p class="text-sm font-medium text-gray-400 uppercase">Standard cost</p>
                                <p class="text-xl font-bold text-gray-200 mt-1">
                                    FRW <?= $displayRatePerM3 ?> <span class="text-sm text-gray-500">per m³</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <div id="message-modal" class="hidden fixed inset-0 bg-black bg-opacity-75 z-50 flex justify-center items-center">
                <div class="bg-gray-800 p-6 rounded-xl shadow-2xl border-t-4 border-water-blue w-full max-w-sm">
                    <h3 id="modal-title" class="text-xl font-bold text-gray-100 mb-2">Message</h3>
                    <p id="modal-message" class="text-gray-300 mb-4"></p>
                    <button id="modal-close" class="w-full bg-water-blue hover:bg-water-blue/80 text-white font-semibold py-2 rounded-lg transition-colors">Close</button>
                </div>
            </div>

        </main>
    </div>

    <script>
        const initialValveStatus = <?= json_encode($initialValveIsOn) ?>;
        const chartLabels = <?= $chartDataLabelsJson ?>;
        const chartValues = <?= $chartDataValuesJson ?>;
        const goalChartData = <?= $goalChartData ?>;
        const goalChartLabels = <?= $goalChartLabels ?>;
        const goalChartColors = <?= $goalChartColors ?>;
    
        function showMessage(title, message, color = 'border-water-blue') {
            const modal = document.getElementById('message-modal');
            document.getElementById('modal-title').textContent = title;
            document.getElementById('modal-message').textContent = message;

            const modalContent = modal.querySelector('div');
            modalContent.classList.remove('border-water-blue', 'border-data-red', 'border-data-green');
            modalContent.classList.add(color);

            modal.classList.remove('hidden');
        }
        document.addEventListener('DOMContentLoaded', () => {
            const valveToggle = document.getElementById('valve-toggle');
            const valveStatusText = document.getElementById('valve-status-text');
            const valveCard = document.getElementById('valve');
            const valveGraphic = document.getElementById('valve-svg');
            const valveMessage = document.getElementById('valve-message');
            const modalClose = document.getElementById('modal-close');

            let isValveOn = initialValveStatus;

            function setValveState(isOn) {
                isValveOn = isOn;
                valveToggle.classList.toggle('on', isOn);
                valveCard.classList.toggle('on', isOn);
                valveCard.classList.toggle('off', !isOn);
                valveGraphic.classList.toggle('valve-on', isOn);
                valveGraphic.classList.toggle('valve-off', !isOn);
                
                const colorClass = isOn ? 'text-data-green' : 'text-data-red';
                const text = isOn ? 'OPEN (ON)' : 'CLOSED (OFF)';

                valveStatusText.classList.remove('text-data-green', 'text-data-red');
                valveStatusText.classList.add(colorClass);
                valveStatusText.textContent = text;
            }

            setValveState(initialValveStatus); 

            valveToggle.addEventListener('click', async () => {
                valveMessage.textContent = "Sending command...";
                const newStatus = !isValveOn;
                const command = newStatus ? 'ON' : 'OFF';

                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ command: command })
                    });

                    const result = await response.json();

                    if (result.status === 'success') {
                        setValveState(newStatus);
                        showMessage('Success', result.message, newStatus ? 'border-data-green' : 'border-data-red');
                    } else {
                        showMessage('Error', result.message, 'border-data-red');
                    }
                } catch (error) {
                    showMessage('Network Error', 'Failed to communicate with the server. ' + error.message, 'border-data-red');
                } finally {
                    valveMessage.textContent = "Tap the switch to change state.";
                }
            });

            modalClose.addEventListener('click', () => {
                document.getElementById('message-modal').classList.add('hidden');
            });
            Chart.defaults.color = '#e5e7eb'; 
            Chart.defaults.font.family = 'Inter';

            const dailyUsageCtx = document.getElementById('dailyUsageChart').getContext('2d');
            new Chart(dailyUsageCtx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Water Consumption (L)',
                        data: chartValues,
                        backgroundColor: '#4c7cff', 
                        borderColor: '#4c7cff',
                        borderWidth: 1,
                        borderRadius: 8,
                        hoverBackgroundColor: '#a78bfa',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: false },
                        tooltip: { mode: 'index', intersect: false },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#4b5563' }, 
                            ticks: { callback: (value) => value + ' L' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
            const goalCtx = document.getElementById('goalDoughnutChart').getContext('2d');
            const goalChart = new Chart(goalCtx, {
                type: 'doughnut',
                data: {
                    labels: goalChartLabels,
                    datasets: [{
                        data: goalChartData,
                        backgroundColor: goalChartColors,
                        hoverOffset: 8,
                        borderWidth: 0,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '80%', 
                    plugins: {
                        legend: { display: false }, 
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += context.parsed.toFixed(2) + ' L';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>