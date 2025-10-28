
<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "smart water meter";

$conn = new mysqli($servername,$username,$password,$dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "SELECT id, temperature, tds_value, turbidity_value, flow_rate, total_volume FROM users";
$result=$conn->query($sql);
$users=[];
if($result->num_rows>0)
    {
        while($row=$result->fetch_assoc()){
            $users[]=$row;
        }
    }
    $conn->close();
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Water Meter Data Dashboard</title>
        <link rel="stylesheet" href="style.css"> 
        <link href="https://cdn.jsdelivr.net/npm/flowbite@3.1.2/dist/flowbite.min.css" rel="stylesheet" />
        <style>
            .my1 { 
                font-family: sans-serif; 
                padding: 20px; 
                background-color: #1c1b29ff; 
            }
            .my2 { 
                overflow-x: auto; 
                margin-top: 20px;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }
            table {
                width: 100%;
                border-collapse: collapse;
                min-width: 800px; 
                background-color: white;
            }
            th, td {
                padding: 15px;
                text-align: left;
                border: 1px solid #ddd;
            }
            th {
                background-color: #007bff;
                color: white;
                text-transform: uppercase;
                font-size: 0.9em;
            }
            tr:nth-child(even) {
                background-color: #f2f2f2;
            }
            .dillo{
                    color:white;
                    font-size: 40px;
                    font-family: 'Franklin Gothic Medium', 'Arial Narrow', Arial, sans-serif;
            }
            .p{
                color: rgb(184, 204, 222);
            }
        </style>
    </head>
    <body class="my1">

<a href="UI.php">
    <button class="relative inline-flex items-center justify-center p-0.5 mb-2 me-2 overflow-hidden text-sm font-medium text-gray-900 rounded-lg group bg-gradient-to-br from-green-400 to-blue-600 group-hover:from-green-400 group-hover:to-blue-600 hover:text-white dark:text-white focus:ring-4 focus:outline-none focus:ring-green-200 dark:focus:ring-green-800">
        <span class="relative px-5 py-2.5 transition-all ease-in duration-75 bg-white dark:bg-gray-900 rounded-md group-hover:bg-transparent group-hover:dark:bg-transparent flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 me-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Back to dashboard
        </span>
    </button>
</a>

        <div class="dillo">
        <h2>Smart Water Meter Dashboard</h2>
        </div>
        <h3 class="p">Real-time Manage and still focusing on meter readings</h3>
        <div class="my2">
        <?php if(count($users)>0):?>
            <table>
            <tr>
                <th>ID</th>
                <th>TEMP (&deg;C)</th>
                <th>TDS (PPM)</th>
                <th>TURBIDITY (NTU)</th>
                <th>FLOW RATE (L/min)</th>
                <th>TOTAL VOLUME (L)</th>
        </tr>
        <?php foreach ($users as $user):?>
            <tr>
                <td><?=$user['id']?></td>
                <td><?=$user['temperature']?></td>
                <td><?=$user['tds_value']?></td>
                <td><?=$user['turbidity_value']?></td>
                <td><?=$user['flow_rate']?></td>
                <td><?=$user['total_volume']?></td>
        </tr>
        <?php endforeach;?>
            </table>
            <?php else:?>
                <p> No data found. Please ensure the ESP8266 is sending data to temp.php.</p>
                <?php endif;?>
                <br>
                </div>
    </body>
    </html>
