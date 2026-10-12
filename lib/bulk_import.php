<?php
require_once __DIR__ . '/free_radius_client.php';
require_once __DIR__ . '/../include/customers.php';
require_once __DIR__ . '/radius_pending.php';
function importCustomersFromCsv($filePath) {
    if (!is_file($filePath) || filesize($filePath) > 2097152) throw new InvalidArgumentException('CSV maksimal 2 MB.');
    $handle = fopen($filePath, 'r');
    $header = fgetcsv($handle, 0, ',', '"', '');
    $expected = array('username','password','name','phone','email','profile','amount','due_day');
    if ($header !== $expected) { fclose($handle); throw new InvalidArgumentException('Header CSV tidak sesuai template.'); }
    $radius = new RadiusClient();
    $groups = $radius->listGroups(); $existing = array_column($radius->listUsers(), null, 'username');
    $rows = array(); $line = 1;
    try {
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if ($values === array(null)) continue;
            if (count($values) !== count($expected) || count($rows) >= 1000) throw new InvalidArgumentException('CSV maksimal 1000 baris dan 8 kolom.');
            $row = array_combine($expected, $values);
            $name = trim($row['username']); $row['username'] = $name;
            if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $name) || isset($rows[$name])) throw new InvalidArgumentException('Username invalid/duplikat pada baris ' . $line);
            if (!in_array($row['profile'], $groups, true)) throw new InvalidArgumentException('Paket tidak tersedia pada baris ' . $line);
            if (($row['password'] === '' && !isset($existing[$name])) || ($row['password'] !== '' && strlen($row['password']) < 8)) throw new InvalidArgumentException('Password baru minimal 8 karakter pada baris ' . $line);
            if (!ctype_digit($row['amount']) || !ctype_digit($row['due_day']) || (int)$row['due_day'] < 1 || (int)$row['due_day'] > 31) throw new InvalidArgumentException('Nominal/tanggal invalid pada baris ' . $line);
            $rows[$name] = $row;
        }
        // All rows validate before mutations. A runtime error reports partial progress explicitly.
        $saved = array();
        foreach ($rows as $name => $row) {
            try {
                radiusQueueReconnect($name, $row['profile']);
                if ($row['password'] === '') $radius->setProfile($name, $row['profile']);
                else $radius->upsertUser($name, $row['password'], $row['profile']);
                $saved[] = $row;
            } catch (Throwable $e) {
                customerBulkUpdateMetadata($saved);
                throw new RuntimeException(count($saved) . ' user tersimpan; impor berhenti pada ' . $name . '. Periksa log sebelum melanjutkan.', 0, $e);
            }
        }
        customerBulkUpdateMetadata($saved);
        return count($saved);
    } finally { fclose($handle); $radius->disconnect(); }
}
