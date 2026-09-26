<?php

return function () {
    $db = mini\db();
    $db->exec('CREATE TABLE IF NOT EXISTS visits (id INTEGER PRIMARY KEY, at INTEGER)');
    $db->exec('INSERT INTO visits (at) VALUES (' . time() . ')');

    return ['visits' => (int) $db->queryField('SELECT COUNT(*) FROM visits')];
};
