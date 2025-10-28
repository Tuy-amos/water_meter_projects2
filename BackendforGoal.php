
<?php
$host = 'localhost';
$db = 'smart water meter'; 
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$max_goals = 20;
$delete_limit = 10;

$message_type = 'info';
$message_body = 'Please Create the form to set a new goal.';
$all_goals = [];
$pdo = null; 
$current_goal_count = 0;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $sql_count = "SELECT COUNT(id) AS goal_count FROM goals";
    $stmt_count = $pdo->query($sql_count);
    $goal_count_result = $stmt_count->fetch();
    $current_goal_count = $goal_count_result ? (int)$goal_count_result['goal_count'] : 0;
    if ($_SERVER["REQUEST_METHOD"] != "POST" && $current_goal_count >= $max_goals) {
        $message_type = 'warning';
        $message_body = "Goal setting is currently paused. You have **$current_goal_count** goals saved, which meets the maximum limit of $max_goals. The oldest goals will be cleaned up automatically.";
    }
    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        if ($current_goal_count >= $max_goals) {
            $message_type = 'warning';
            $message_body = "Warning: The maximum limit of $max_goals goals has been reached (Currently **$current_goal_count** saved goals). Your new goal was **not saved**. The system will now check for cleanup.";
        } else {
            $goal_name = filter_input(INPUT_POST, 'goal_name', FILTER_SANITIZE_SPECIAL_CHARS);
            $target_amount = filter_input(INPUT_POST, 'target_amount', FILTER_VALIDATE_FLOAT);
            $target_date = filter_input(INPUT_POST, 'target_date', FILTER_SANITIZE_STRING);
            $goal_period = filter_input(INPUT_POST, 'goal_period', FILTER_SANITIZE_STRING);
            
            $valid_periods = ['Day', 'Week', 'Month'];
            $is_period_valid = in_array($goal_period, $valid_periods);

            if (!$goal_name || $target_amount === false || $target_amount <= 0 || !$target_date || !$is_period_valid) {
                $message_type = 'error';
                $message_body = 'Please ensure all fields are filled correctly. Target amount must be a positive number and Goal Period must be selected.';
            } else {
                $sql = "INSERT INTO goals (goal_name, target_amount, goal_period, target_date) VALUES (?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$goal_name, $target_amount, $goal_period, $target_date]);

                $message_type = 'success';
                $message_body = "Success! Goal \"{$goal_name}\" has been saved. Target: **" . number_format($target_amount, 2) . "** per **{$goal_period}**, due **{$target_date}**.";
                $stmt_count = $pdo->query($sql_count);
                $goal_count_result = $stmt_count->fetch();
                $current_goal_count = $goal_count_result ? (int)$goal_count_result['goal_count'] : 0;
            }
        }
    }
    
    if ($current_goal_count >= $max_goals) {
        
        $sql_oldest_ids = "SELECT id FROM goals ORDER BY id ASC LIMIT $delete_limit";
        $stmt_oldest_ids = $pdo->query($sql_oldest_ids);
        $ids_to_delete = $stmt_oldest_ids->fetchAll(PDO::FETCH_COLUMN, 0); 

        if (!empty($ids_to_delete)) {
            $placeholders = str_repeat('?,', count($ids_to_delete) - 1) . '?';
            $sql_delete = "DELETE FROM goals WHERE id IN ($placeholders)";
            $stmt_delete = $pdo->prepare($sql_delete);
            $stmt_delete->execute($ids_to_delete);

            $cleanup_message = "Automated Cleanup Triggered: You had **$current_goal_count** goals (limit $max_goals). **" . count($ids_to_delete) . " oldest goal(s)** (IDs: " . implode(', ', $ids_to_delete) . ") have been deleted.";

            if ($message_type === 'info' || $message_type === 'success') {
                $message_type = 'info';
                $message_body = $cleanup_message;
            } else if ($message_type === 'warning') {
                $message_body .= "\n\n$cleanup_message";
            }
            $stmt_count = $pdo->query($sql_count);
            $goal_count_result = $stmt_count->fetch();
            $current_goal_count = $goal_count_result ? (int)$goal_count_result['goal_count'] : 0;
        }
    }

    $sql_fetch = "SELECT id, goal_name, target_amount, goal_period, target_date FROM goals ORDER BY id DESC";
    $stmt_fetch = $pdo->query($sql_fetch);
    $all_goals = $stmt_fetch->fetchAll();


} catch (\PDOException $e) {
    $message_type = 'error';
    $message_body = 'Database Error: Could not process the request. Error: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goal Setter</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            min-height: 100vh;
        }
        @keyframes goalFadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-goal-load {
            animation: goalFadeIn 0.5s ease-out forwards;
            opacity: 0; 
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-200 flex flex-col items-center p-4 md:p-8">
    <div class="w-full max-w-5xl">
        <div class="bg-gray-800 p-6 md:p-8 rounded-xl shadow-2xl mb-8 border border-gray-700">
            <h1 class="text-3xl font-extrabold text-indigo-400 mb-6 text-center">Goal Submission Status</h1>
            
            <?php
            $bg_color = '';
            $icon = '';
            $title = '';

            if ($message_type === 'success') {
                $bg_color = 'bg-green-100 border-green-600 text-green-800';
                $icon = '✅';
                $title = 'Goal Saved!';
            } elseif ($message_type === 'error') {
                $bg_color = 'bg-red-100 border-red-600 text-red-800';
                $icon = '❌';
                $title = 'Submission Error';
            } elseif ($message_type === 'warning') {
                 $bg_color = 'bg-yellow-100 border-yellow-600 text-yellow-800';
                 $icon = '⚠️';
                 $title = 'Limit Reached!';
            } else { 
                $bg_color = 'bg-blue-100 border-blue-600 text-blue-800';
                $title = strpos($message_body, 'Cleanup Triggered') !== false ? 'History Cleaned' : 'Awaiting Submission';
                $icon = strpos($message_body, 'Cleanup Triggered') !== false ? '🗑️' : 'ℹ️';
            }
            ?>
                  <div class="mt-6 text-center">
                      <a href="UI.php"
                          class="inline-flex items-center px-1 py-6 border border-transparent text-base font-medium rounded-xl shadow-xl text-white bg-green-1000 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 transform hover:scale-[1.03]">
                       ↩ Back To Dashboard ↪       
                      </a>
                      <br>
                  </div>
            <div class="p-4 rounded-lg border-l-4 border-r-4 <?= $bg_color ?> transition duration-300 shadow-lg" role="alert">
                <p class="font-bold text-lg mb-2"><?= $icon ?> <?= $title ?></p>
                <p class="text-sm mt-1 whitespace-pre-line">
                    <?= nl2br(htmlspecialchars(str_replace(['**', '*'], ['<b>', '</b>'], $message_body))) ?>
                </p>
                <p class="text-xs mt-3 font-semibold">
                    Current Goal Count: <span class="text-base"><?= $current_goal_count ?></span> / <?= $max_goals ?>
                </p>
            </div>

            <div class="mt-6 text-center">
                <a href="Goal.html"
                    class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-xl shadow-xl text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 transform hover:scale-[1.03]">
                    Go to Goal Setting Form →
                </a>
            </div>
        </div>

        <div class="mt-12 bg-gray-800 p-6 md:p-8 rounded-xl shadow-2xl border border-gray-700">
            <h2 class="text-2xl font-extrabold text-white mb-8 text-center p-4 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-700 shadow-xl">
                A Setted Goal History
            </h2>

            <?php if (empty($all_goals)): ?>
                <p class="text-center text-gray-400 p-4 bg-gray-700 rounded-xl border border-gray-600">
                    No goals have been saved yet. Set a goal to start tracking!
                </p>
            <?php else: ?>
                
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <?php $delay_counter = 0; ?>
                    <?php foreach ($all_goals as $goal): ?>
                    <?php $delay_counter += 0.1; ?>
                    <div class="bg-gray-700 border-t-8 border-indigo-500 rounded-xl p-6 shadow-xl hover:shadow-2xl transition duration-300 transform hover:scale-[1.02] animate-goal-load"
                        style="animation-delay: <?= $delay_counter ?>s;">
                        <h3 class="text-xl font-extrabold text-indigo-300 mb-4 border-b pb-2 border-indigo-300/50 truncate">
                            <?= htmlspecialchars($goal['goal_name']) ?>
                        </h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center text-gray-200 p-2 bg-gray-800 rounded-lg border border-gray-600">
                                <span class="font-semibold text-sm text-gray-400">Target Amount:</span>
                                <span class="text-xl font-extrabold text-purple-400">
                                    <?= number_format($goal['target_amount'], 2) ?> L
                                </span>
                            </div>
                            <div class="flex justify-between items-center text-gray-200 p-2 bg-indigo-700/30 rounded-lg border border-indigo-600">
                                <span class="font-bold text-sm text-indigo-200">Asetted Period:</span>
                                <span class="text-lg font-extrabold text-indigo-300 bg-gray-800 px-3 py-1 rounded-full shadow-md">
                                    <?= htmlspecialchars($goal['goal_period']) ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center text-gray-200 p-2 bg-gray-800 rounded-lg border border-gray-600">
                                <span class="font-semibold text-sm text-gray-400">Target Date:</span>
                                <span class="text-md font-bold text-gray-300">
                                    <?= htmlspecialchars($goal['target_date']) ?>
                                </span>
                            </div>
                        </div>

                        <div class="mt-5 text-xs text-right text-gray-500">
                            No of Goal: <?= htmlspecialchars($goal['id']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>
