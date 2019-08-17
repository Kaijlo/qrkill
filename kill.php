<?php

session_start();

require_once 'priv/errorhandler.php';
require_once 'priv/pdo.php';

if(!isset($_SESSION['qr']['id']))
{
    echo json_encode(['error' => 'Din session har gått ut. Vänligen logga in igen.']);
    die();
}

$postData = json_decode(file_get_contents('php://input'), true);

if(!isset($postData['secret']))
{
    echo json_encode(['error' => 'Ingen kod angiven.']);
    die();
}

$secret = $postData['secret'];

$sql = 'SELECT alive FROM qr_players WHERE qr_users_id = ?';
$alive = DB::prepare($sql)->execute([$_SESSION['qr']['id']])->fetchColumn();

if($alive != 1)
{
    echo json_encode(['error' => 'Du är tyvärr död och kan inte mörda någon.']);
    die();
}

$sql = '
SELECT 
    event.id,
    target.alive,
    (
        target.qr_users_id = (
            SELECT target 
            FROM qr_players AS hunter 
            WHERE hunter.qr_users_id = ? AND hunter.qr_events_id = event.id
        )
    ) AS correct_secret
FROM qr_players AS target
JOIN qr_events AS event
	ON event.id = target.qr_events_id 
    	AND NOW() > event.start_date 
        AND NOW() < event.end_date
WHERE target.secret = ?
';
$info =  DB::prepare($sql)->execute([$_SESSION['qr']['id'], $secret])->fetch();

if(!$info || $info['correct_secret'] == 0)
{
    echo json_encode(['error' => 'Koden du angav var inte korrekt']);
    die();
}

if($info['alive'] == 0)
{
    echo json_encode(['error' => 'Denna person är redan död. Ta det lungt.']);
    die();
}

$sql = 'UPDATE qr_players SET alive = 0 WHERE secret = ?';
DB::prepare($sql)->execute([$secret]);

$sql = '
INSERT INTO qr_kills (target, killer, qr_events_id) 
VALUES ((SELECT qr_users_id FROM qr_players WHERE secret = ?), ?, ?)
';
DB::prepare($sql)->execute([$secret, $_SESSION['qr']['id'], $info['id']]);

$sql = "
SELECT qr_users_id
FROM qr_players
WHERE target IS NULL AND qr_events_id = ?
ORDER BY created_date ASC LIMIT 1
";
$player_without_target = DB::prepare($sql)->execute([$info['id']])->fetchColumn();

if($player_without_target)
{
    $sql = '
    UPDATE qr_players as killer
    JOIN qr_players AS victim ON victim.secret = ?
    JOIN qr_players AS new_player ON new_player.qr_users_id = ?
    SET new_player.target = victim.target, killer.target = new_player.qr_users_id
    WHERE killer.qr_users_id = ? AND killer.qr_events_id = ?
    ';
    DB::prepare($sql)->execute([$secret, $player_without_target, $_SESSION['qr']['id'], $info['id']]);
}
else
{
    $sql = '
    UPDATE qr_players as killer
    JOIN (SELECT target FROM qr_players WHERE secret = ?) as victim
    SET killer.target = victim.target 
    WHERE qr_users_id = ? AND qr_events_id = ?
    ';
    DB::prepare($sql)->execute([$secret, $_SESSION['qr']['id'], $info['id']]);
}

echo json_encode(['success' => true]);
