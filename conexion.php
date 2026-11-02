<?php
// ==========================================================
//  TIENDA TECH - Conexión a la base de datos (XAMPP / MySQL)
// ==========================================================
$config = [
    'host'    => '127.0.0.1',
    'puerto'  => 3306,
    'bd'      => 'tienda_tech',
    'usuario' => 'root',
    'clave'   => '',
];

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['puerto']};dbname={$config['bd']};charset=utf8mb4",
        $config['usuario'],
        $config['clave'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('<div style="font-family:sans-serif;max-width:480px;margin:80px auto;padding:24px;background:#fff;border-top:4px solid #e74c3c">'
       . '<h2 style="margin:0 0 8px;color:#2c3e50">No se pudo conectar</h2>'
       . '<p style="color:#555">Enciende MySQL en XAMPP y verifica que la base <b>tienda_tech</b> exista.</p></div>');
}