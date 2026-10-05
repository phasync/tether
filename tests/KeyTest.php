<?php

use Tether\Tether;

function load_key(string $file): string
{
    return (new ReflectionMethod(Tether::class, 'loadKey'))->invoke(null, $file);
}

beforeEach(function () {
    putenv('TETHER_SECRET');
    $this->dir = sys_get_temp_dir() . '/tether-test-' . bin2hex(random_bytes(4));
    mkdir($this->dir, 0700);
    $this->file = $this->dir . '/key';
});

afterEach(function () {
    array_map('unlink', glob($this->dir . '/*'));
    rmdir($this->dir);
});

test('a key file made for the workers is private, long, and the same for every reader', function () {
    $key = load_key($this->file);
    expect(strlen($key))->toBeGreaterThanOrEqual(32);
    expect(load_key($this->file))->toBe($key);
    expect(fileperms($this->file) & 0777)->toBe(0600);
    expect(glob($this->dir . '/*'))->toBe([$this->file]);
});

test('an empty key file is an error, not a key', function () {
    touch($this->file);
    expect(fn () => load_key($this->file))->toThrow(RuntimeException::class);
});

test('a short TETHER_SECRET is an error', function () {
    putenv('TETHER_SECRET=secret');
    expect(fn () => load_key($this->file))->toThrow(RuntimeException::class);
    putenv('TETHER_SECRET');
});

test('a long TETHER_SECRET is the key', function () {
    putenv('TETHER_SECRET=' . str_repeat('k', 40));
    expect(load_key($this->file))->toBe(str_repeat('k', 40));
    expect(file_exists($this->file))->toBeFalse();
    putenv('TETHER_SECRET');
});
