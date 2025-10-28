
<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "smart water meter";

$conn = new mysqli($servername,$username,$password,$dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "SELECT id, goal_name, target_amount, target_date,goal_period FROM goals";
$result=$conn->query($sql);
$goals=[];
if($result->num_rows>0)
    {
        while($row=$result->fetch_assoc()){
            $goals[]=$row;
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
        <link href="https://cdn.jsdelivr.net/npm/flowbite@3.1.2/dist/flowbite.min.css" rel="stylesheet" />
        <link rel="stylesheet" href="style.css"> 
        <style>
            .my1 { 
                font-family: sans-serif; 
                padding: 20px; 
                background-color: #1f2028ff; 
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
            .dillox{
                color:white;
                font-size: 40px;
                font-family: 'Franklin Gothic Medium', 'Arial Narrow', Arial, sans-serif;
            }
            .p{
                color: rgb(184, 204, 222);
            }
            .p1{
                color: rgb(184, 204, 222);
                font-size: 20px;
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

        <div class="dillox">
        <h1>Goal Dashboard </h1>
        </div>
        <p class="p1">Smart Water Meter </p>
        <p class="p">Goal That was ready setted,all founds in this Table</p>
        <div class="my2">
        <?php if(count($goals)>0):?>
            <table>
            <tr>
                <th>ID</th>
                <th>Name of Goal</th>
                <th>Target in liters</th>
                <th>For Period</th>
                <th>Date</th>
        </tr>
        <?php foreach ($goals as $user):?>
            <tr>
                <td><?=$user['id']?></td>
                <td><?=$user['goal_name']?></td>
                <td><?=$user['target_amount']?> L</td>
                <td>For <?=$user['goal_period']?></td>
                <td><?=$user['target_date']?></td>
        </tr>
        <?php endforeach;?>
            </table>
            <?php else:?>
                <p> No data found.</p>
                <?php endif;?>
                <br>
                </div>
    </body>
    </html>
