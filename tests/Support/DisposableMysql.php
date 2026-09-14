<?php

namespace Tests\Support;

use App\Support\Testing\TestDatabasePolicy;
use PDO;

final class DisposableMysql
{
    public readonly string $name;

    public readonly array $config;

    private PDO $admin;

    private bool $created = false;

    public function __construct(array $base)
    {
        $this->name = 'vending_attendance_test_'.bin2hex(random_bytes(8));
        unset($base['url'], $base['read'], $base['write'], $base['name']);
        $this->config = array_merge($base, ['database' => $this->name, 'driver' => 'mysql']);
        TestDatabasePolicy::assertSafe('testing', $this->config);
        $this->admin = new PDO('mysql:host='.$this->config['host'].';port='.($this->config['port'] ?? 3306).';charset=utf8mb4', $this->config['username'], $this->config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // No IF NOT EXISTS: never adopt or delete a preexisting database.
        $this->admin->exec('CREATE DATABASE `'.$this->name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->created = true;
    }

    public function close(): void
    {
        TestDatabasePolicy::assertSafe('testing', $this->config);
        if ($this->created) {
            $this->admin->exec('DROP DATABASE `'.$this->name.'`');
            $this->created = false;
        }
        $q = $this->admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
        $q->execute([$this->name]);
        if ((int) $q->fetchColumn() !== 0) {
            throw new \RuntimeException('TEST_DATABASE_SAFETY_BLOCKED reason=disposable cleanup incomplete database='.$this->name);
        }
    }
}
