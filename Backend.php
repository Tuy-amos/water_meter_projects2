
<?php
header('Content-Type: application/json');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "smart water meter";

$response = ['status' => 'error', 'message' => 'Initialization error', 'valve_status' => 'OFF', 'cumulative_volume' => 0.00];
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    $response['message'] = "Connection failed: " . $conn->connect_error;
    die(json_encode($response));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    if (isset($data['temperature'], $data['tds_value'], $data['turbidity_value'], $data['flow_rate'], $data['total_volume'])) {

        $temperature = $conn->real_escape_string($data['temperature']);
        $tds_value = $conn->real_escape_string($data['tds_value']);
        $turbidity_value = $conn->real_escape_string($data['turbidity_value']);
        $flow_rate = $conn->real_escape_string($data['flow_rate']);
        $total_volume_string = $conn->real_escape_string($data['total_volume']);
        
        $incoming_total_volume = (float)$data['total_volume'];
    
        $sql = "INSERT INTO users (temperature, tds_value, turbidity_value, flow_rate, total_volume) 
                VALUES ('$temperature', '$tds_value', '$turbidity_value', '$flow_rate', '$total_volume_string')";
        
        if ($conn->query($sql) === TRUE) {
            $response['status'] = 'success';
            $response['message'] = 'Data logged successfully.';
        } else {
            $response['status'] = 'error';
            $response['message'] = "Database insertion failed: " . $conn->error;
        }

        $cumulative_sql = "SELECT cumulative_volume FROM cumulative_volume_log WHERE id = 1 LIMIT 1";
        $cumulative_result = $conn->query($cumulative_sql);
        
        if ($cumulative_result && $cumulative_result->num_rows > 0) {
            $state_row = $cumulative_result->fetch_assoc();
            $cumulative_volume = (float)$state_row['cumulative_volume'];
            $new_cumulative_volume = $cumulative_volume + $incoming_total_volume;
            
            $update_sql = "UPDATE cumulative_volume_log SET 
                           cumulative_volume = $new_cumulative_volume
                           WHERE id = 1";
            
            if ($conn->query($update_sql) === TRUE) {
                $response['cumulative_volume'] = round($new_cumulative_volume, 3);
            } else {
                $response['message'] .= " | Cumulative volume update failed: " . $conn->error;
            }
        } else {
            $insert_init_sql = "INSERT INTO cumulative_volume_log (id, cumulative_volume) 
                                 VALUES (1, $incoming_total_volume)";
            $conn->query($insert_init_sql);
            $response['cumulative_volume'] = $incoming_total_volume;
            $response['message'] .= ' (Cumulative log initialized)';
        }

    } else {
        $response['message'] = 'Missing sensor data in JSON payload.';
    }
} else {
    $response['message'] = 'Invalid request method. Only POST is allowed.';
}

$valve_sql = "SELECT valve_status FROM device_state WHERE id = 1 LIMIT 1";
$result = $conn->query($valve_sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $response['valve_status'] = $row['valve_status']; 
} else {
    $response['valve_status'] = 'OFF'; 
    $response['message'] .= ' (Valve status not found in DB. Defaulting to OFF)';
}

echo json_encode($response);

$conn->close();
?>
