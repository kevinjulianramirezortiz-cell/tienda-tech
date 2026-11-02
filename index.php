<?php
// ==========================================================
//  TIENDA TECH - SISTEMA DE INVENTARIO (versión PHP)
//  Materia: Programación 5
//  Tecnologías: PHP + MySQL (XAMPP) + PDO
// ==========================================================
session_start();
require __DIR__ . '/conexion.php';

const STOCK_BAJO = 5;                       // a partir de aquí se avisa "stock bajo"
$dirSubidas = __DIR__ . '/uploads/';
if (!is_dir($dirSubidas)) {
    mkdir($dirSubidas, 0755, true);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ---------- Funciones de ayuda ----------
function e($t): string { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }

function compacto(float $n, string $pref = ''): string {
    if ($n >= 1000000) return $pref . round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return $pref . round($n / 1000, 1) . 'k';
    return $pref . number_format($n, 0);
}

function urlImagen(?string $archivo): ?string {
    if (!$archivo) return null;
    $archivo = basename(str_replace('\\', '/', trim($archivo, " \t\"'")));
    return is_file(__DIR__ . '/uploads/' . $archivo) ? 'uploads/' . rawurlencode($archivo) : null;
}

function borrarImagen(?string $archivo): void {
    if (!$archivo) return;
    $ruta = __DIR__ . '/uploads/' . basename(str_replace('\\', '/', $archivo));
    if (is_file($ruta)) @unlink($ruta);
}

function subirImagen(array $f, string $dir): ?string {
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK)  throw new RuntimeException('No se pudo subir la imagen.');
    if ($f['size'] > 3 * 1024 * 1024)   throw new RuntimeException('La imagen supera los 3 MB.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Formato no válido. Usa JPG, PNG, WEBP o GIF.');

    $nombre = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $nombre)) {
        throw new RuntimeException('No se pudo guardar la imagen en el servidor.');
    }
    return $nombre;
}

// ---------- Exportar a CSV ----------
if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventario_tienda_tech.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Nombre', 'Categoría', 'Precio', 'Stock'], ',', '"', '\\');
    foreach ($pdo->query('SELECT id, nombre, categoria, precio, stock FROM productos ORDER BY id') as $r) {
        fputcsv($out, $r, ',', '"', '\\');
    }
    exit;
}

// ---------- Procesar formularios (agregar / editar / eliminar) ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$form = ['id' => '', 'nombre' => '', 'categoria' => '', 'precio' => '', 'stock' => '', 'descripcion' => '', 'imagen_ruta' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo otra vez.');
    }

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar') {
            $id        = (int)($_POST['id'] ?? 0);
            $nombre    = trim($_POST['nombre'] ?? '');
            $categoria = trim($_POST['categoria'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $precioTxt = trim($_POST['precio'] ?? '');
            $stockTxt  = trim($_POST['stock'] ?? '');

            // Conservamos lo escrito por si hay que mostrar un error
            $form = array_merge($form, [
                'id' => $id ?: '', 'nombre' => $nombre, 'categoria' => $categoria,
                'precio' => $precioTxt, 'stock' => $stockTxt, 'descripcion' => $descripcion,
            ]);

            if ($nombre === '' || $precioTxt === '' || $stockTxt === '') {
                throw new InvalidArgumentException('Completa nombre, precio y stock.');
            }
            if (!is_numeric($precioTxt) || (float)$precioTxt < 0) {
                throw new InvalidArgumentException('El precio debe ser un número mayor o igual a 0.');
            }
            if (filter_var($stockTxt, FILTER_VALIDATE_INT) === false || (int)$stockTxt < 0) {
                throw new InvalidArgumentException('El stock debe ser un número entero mayor o igual a 0.');
            }

            $precio = round((float)$precioTxt, 2);
            $stock  = (int)$stockTxt;
            $nueva  = subirImagen($_FILES['imagen'] ?? [], $dirSubidas);
            $cat    = $categoria !== '' ? $categoria : null;
            $desc   = $descripcion !== '' ? $descripcion : null;

            if ($id > 0) {
                $st = $pdo->prepare('SELECT imagen_ruta FROM productos WHERE id = ?');
                $st->execute([$id]);
                $actual = $st->fetchColumn();
                if ($actual === false) throw new InvalidArgumentException('El producto ya no existe.');

                $pdo->prepare('UPDATE productos SET nombre = ?, categoria = ?, precio = ?, stock = ?, descripcion = ?, imagen_ruta = ? WHERE id = ?')
                    ->execute([$nombre, $cat, $precio, $stock, $desc, $nueva ?? $actual, $id]);
                if ($nueva) borrarImagen($actual);
                $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => "Producto «{$nombre}» actualizado."];
            } else {
                $pdo->prepare('INSERT INTO productos (nombre, categoria, precio, stock, descripcion, imagen_ruta) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$nombre, $cat, $precio, $stock, $desc, $nueva]);
                $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => "Producto «{$nombre}» agregado."];
            }
            header('Location: index.php');
            exit;
        }

        if ($accion === 'eliminar') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT imagen_ruta FROM productos WHERE id = ?');
            $st->execute([$id]);
            $img = $st->fetchColumn();
            $pdo->prepare('DELETE FROM productos WHERE id = ?')->execute([$id]);
            if ($img) borrarImagen($img);
            $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => 'Producto eliminado.'];
            header('Location: index.php');
            exit;
        }
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $flash = ['tipo' => 'error', 'msg' => $ex->getMessage()];
    } catch (PDOException $ex) {
        $flash = ['tipo' => 'error', 'msg' => 'No se pudo guardar en la base de datos.'];
    }
}

// ---------- Modo edición ----------
if (isset($_GET['editar']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM productos WHERE id = ?');
    $st->execute([(int)$_GET['editar']]);
    if ($fila = $st->fetch()) {
        $form = [
            'id' => $fila['id'], 'nombre' => $fila['nombre'], 'categoria' => $fila['categoria'] ?? '',
            'precio' => $fila['precio'], 'stock' => $fila['stock'], 'descripcion' => $fila['descripcion'] ?? '',
            'imagen_ruta' => $fila['imagen_ruta'] ?? '',
        ];
    }
}
$editando = $form['id'] !== '';

// ---------- Filtros de la lista ----------
$q     = trim($_GET['q'] ?? '');
$cat   = trim($_GET['cat'] ?? '');
$vista = $_GET['vista'] ?? 'todos';
if (!in_array($vista, ['todos', 'bajo', 'agotado'], true)) $vista = 'todos';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(nombre LIKE :q1 OR categoria LIKE :q2 OR descripcion LIKE :q3)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = "%{$q}%";
}
if ($cat !== '') {
    $where[] = 'categoria = :cat';
    $params[':cat'] = $cat;
}
if ($vista === 'bajo')    $where[] = 'stock <= ' . STOCK_BAJO;
if ($vista === 'agotado') $where[] = 'stock = 0';

$sql = 'SELECT * FROM productos' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$productos = $st->fetchAll();

// ---------- Datos para los paneles ----------
$res = $pdo->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(stock), 0) AS unidades,
            COALESCE(SUM(CASE WHEN stock <= ' . STOCK_BAJO . ' THEN 1 ELSE 0 END), 0) AS bajos,
            COALESCE(SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END), 0) AS agotados,
            COALESCE(SUM(precio * stock), 0) AS valor
     FROM productos'
)->fetch();

$categorias = $pdo->query("SELECT categoria FROM productos WHERE categoria IS NOT NULL AND categoria <> '' GROUP BY categoria ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

$barras = $pdo->query(
    "SELECT COALESCE(NULLIF(categoria, ''), 'Sin categoría') AS c, COUNT(*) AS n
     FROM productos GROUP BY c ORDER BY n DESC LIMIT 5"
)->fetchAll();

$bajos = $pdo->query('SELECT nombre, stock FROM productos WHERE stock <= ' . STOCK_BAJO . ' ORDER BY stock ASC, id DESC LIMIT 4')->fetchAll();

function urlVista(string $v, string $q, string $c): string {
    return 'index.php?' . http_build_query(array_filter(['vista' => $v === 'todos' ? '' : $v, 'q' => $q, 'cat' => $c]));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tienda Tech · Inventario digital</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div class="contenedor">

  <h1 class="titulo-pagina">Tienda Tech · Inventario digital</h1>

  <?php if ($flash): ?>
    <div class="aviso <?= $flash['tipo'] === 'error' ? 'error' : '' ?>" role="status"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <!-- ===== Barra de pestañas + buscador ===== -->
  <nav class="barra">
    <div class="pestanas">
      <a class="pestana <?= $vista !== 'bajo' ? 'activa' : '' ?>" href="index.php"><i class="fa-solid fa-box-open"></i> Inventario</a>
      <a class="pestana" href="#formulario"><i class="fa-solid fa-plus"></i> Nuevo producto</a>
      <a class="pestana <?= $vista === 'bajo' ? 'activa' : '' ?>" href="index.php?vista=bajo">
        <i class="fa-solid fa-envelope"></i> Alertas
        <?php if ((int)$res['bajos'] > 0): ?><span class="insignia"><?= (int)$res['bajos'] ?></span><?php endif; ?>
      </a>
    </div>
    <form class="buscador" method="get" action="index.php" role="search">
      <?php if ($vista !== 'todos'): ?><input type="hidden" name="vista" value="<?= e($vista) ?>"><?php endif; ?>
      <div class="caja">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar producto…" aria-label="Buscar producto">
        <button type="submit" aria-label="Buscar"><i class="fa-solid fa-magnifying-glass"></i></button>
      </div>
    </form>
  </nav>

  <!-- ===== Paneles ===== -->
  <div class="rejilla">

    <!-- Columna izquierda: formulario -->
    <div class="columna">
      <section class="tarjeta" id="formulario">
        <div class="cuerpo">
          <h2><?= $editando ? 'Editar producto' : 'Nuevo producto' ?></h2>
          <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?= e($form['id']) ?>">

            <div class="campo">
              <input type="text" name="nombre" maxlength="120" required placeholder="Nombre (ej: Audífonos Gamer)" value="<?= e($form['nombre']) ?>">
              <i class="fa-solid fa-tag"></i>
            </div>
            <div class="campo">
              <input type="text" name="categoria" maxlength="80" list="lista-categorias" placeholder="Categoría (ej: Accesorios)" value="<?= e($form['categoria']) ?>">
              <datalist id="lista-categorias">
                <?php foreach ($categorias as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
              </datalist>
              <i class="fa-solid fa-layer-group"></i>
            </div>
            <div class="campo">
              <textarea name="descripcion" rows="2" maxlength="500" placeholder="Descripción (opcional)"><?= e($form['descripcion']) ?></textarea>
            </div>
            <div class="fila-doble">
              <div class="campo">
                <input type="number" name="precio" min="0" step="0.01" required placeholder="Precio" value="<?= e($form['precio']) ?>">
                <i class="fa-solid fa-dollar-sign"></i>
              </div>
              <div class="campo">
                <input type="number" name="stock" min="0" step="1" required placeholder="Stock" value="<?= e($form['stock']) ?>">
                <i class="fa-solid fa-cubes"></i>
              </div>
            </div>
            <div class="campo">
              <input type="file" name="imagen" accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Imagen del producto">
            </div>
            <?php if ($editando && urlImagen($form['imagen_ruta'])): ?>
              <p style="text-align:center;font-size:11px;color:var(--suave);margin:-4px 0 6px">Si no eliges otra imagen, se conserva la actual.</p>
            <?php endif; ?>

            <button class="boton" type="submit"><?= $editando ? 'Guardar cambios' : 'Agregar producto' ?></button>
            <?php if ($editando): ?><a class="enlace-centro" href="index.php">Cancelar edición</a><?php endif; ?>
          </form>
        </div>
        <a class="pie-azul" href="index.php?exportar=1"><i class="fa-solid fa-file-csv"></i> Descargar inventario (CSV)</a>
      </section>
    </div>

    <!-- Columna central: filtro, estadísticas, categorías -->
    <div class="columna">
      <section class="tarjeta">
        <div class="cuerpo">
          <h2>Filtrar</h2>
          <form method="get" action="index.php">
            <?php if ($vista !== 'todos'): ?><input type="hidden" name="vista" value="<?= e($vista) ?>"><?php endif; ?>
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
            <div class="campo">
              <select name="cat" aria-label="Categoría">
                <option value="">Todas las categorías</option>
                <?php foreach ($categorias as $c): ?>
                  <option value="<?= e($c) ?>" <?= $c === $cat ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="boton" type="submit">Filtrar</button>
          </form>
        </div>
      </section>

      <div class="estadisticas">
        <div class="est e1"><div class="icono"><i class="fa-solid fa-boxes-stacked"></i></div><div class="dato"><?= compacto((float)$res['total']) ?><small>Productos</small></div></div>
        <div class="est e2"><div class="icono"><i class="fa-solid fa-cubes"></i></div><div class="dato"><?= compacto((float)$res['unidades']) ?><small>Unidades</small></div></div>
        <div class="est e3"><div class="icono"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="dato"><?= compacto((float)$res['bajos']) ?><small>Stock bajo</small></div></div>
        <div class="est e4"><div class="icono"><i class="fa-solid fa-sack-dollar"></i></div><div class="dato"><?= compacto((float)$res['valor'], '$') ?><small>Valor</small></div></div>
      </div>

      <section class="tarjeta">
        <div class="cuerpo">
          <h2>Categorías</h2>
          <?php if (!$barras): ?>
            <p style="text-align:center;color:var(--suave);margin:0">Aún no hay productos.</p>
          <?php endif; ?>
          <?php foreach ($barras as $b): $pct = $res['total'] ? round($b['n'] / $res['total'] * 100) : 0; ?>
            <div class="barra-cat">
              <div class="rotulo"><span><?= e($b['c']) ?></span><span><?= (int)$b['n'] ?></span></div>
              <div class="pista"><div class="relleno" style="width:<?= $pct ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <!-- Columna derecha: tienda y alertas -->
    <div class="columna der">
      <section class="tarjeta perfil">
        <div class="cabeza">
          <div class="avatar"><i class="fa-solid fa-store"></i></div>
          <div class="nombre">Tienda Tech de las Tres Bellakas</div>
          <div class="rol">Inventario digital</div>
        </div>
        <div class="mini-tabs">
          <a href="<?= e(urlVista('todos', $q, $cat)) ?>" class="<?= $vista === 'todos' ? 'activa' : '' ?>">Todos</a>
          <a href="<?= e(urlVista('bajo', $q, $cat)) ?>" class="<?= $vista === 'bajo' ? 'activa' : '' ?>">Stock bajo</a>
          <a href="<?= e(urlVista('agotado', $q, $cat)) ?>" class="<?= $vista === 'agotado' ? 'activa' : '' ?>">Agotados</a>
        </div>
        <p class="texto">
          Tienes <?= (int)$res['total'] ?> productos registrados con <?= (int)$res['unidades'] ?> unidades en total.
          <?= (int)$res['agotados'] ?> agotados y <?= (int)$res['bajos'] ?> con stock de <?= STOCK_BAJO ?> o menos.
        </p>
      </section>

      <section class="tarjeta alertas">
        <div class="cabeza-azul"><i class="fa-solid fa-bell"></i> Por reponer</div>
        <?php if ($bajos): ?>
          <ul>
            <?php foreach ($bajos as $b): ?>
              <li>
                <b><?= e($b['nombre']) ?></b>
                <span class="chip <?= (int)$b['stock'] === 0 ? 'cero' : 'bajo' ?>"><?= (int)$b['stock'] === 0 ? 'Agotado' : (int)$b['stock'] . ' uds' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <ul><li class="vacio" style="justify-content:center">Todo el inventario está en buen nivel.</li></ul>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <!-- ===== Lista de productos ===== -->
  <div class="encabezado-seccion">
    <h2>Productos en inventario</h2>
    <span class="cuenta"><?= count($productos) ?> resultado<?= count($productos) === 1 ? '' : 's' ?></span>
  </div>

  <div class="productos">
    <?php if (!$productos): ?>
      <div class="tarjeta vacio-grande">
        <i class="fa-regular fa-folder-open"></i>
        <?= ($q !== '' || $cat !== '' || $vista !== 'todos') ? 'No hay productos con esos filtros.' : 'Todavía no hay productos. Agrega el primero desde el formulario.' ?>
      </div>
    <?php endif; ?>

    <?php foreach ($productos as $p):
        $img = urlImagen($p['imagen_ruta']);
        $stk = (int)$p['stock'];
        $clase = $stk === 0 ? 'cero' : ($stk <= STOCK_BAJO ? 'bajo' : '');
    ?>
      <article class="tarjeta producto">
        <div class="foto">
          <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($p['nombre']) ?>" loading="lazy">
          <?php else: ?><i class="fa-regular fa-image"></i><?php endif; ?>
        </div>
        <div class="info">
          <div class="cat"><?= e($p['categoria'] ?: 'Sin categoría') ?></div>
          <div class="nom"><?= e($p['nombre']) ?></div>
          <?php if (!empty($p['descripcion'])): ?><p class="desc"><?= e($p['descripcion']) ?></p><?php endif; ?>
          <div class="linea">
            <span class="precio">$<?= number_format((float)$p['precio'], 2) ?></span>
            <span class="chip <?= $clase ?>"><?= $stk === 0 ? 'Agotado' : 'Stock: ' . $stk ?></span>
          </div>
        </div>
        <div class="acciones">
          <a href="index.php?editar=<?= (int)$p['id'] ?>#formulario"><i class="fa-solid fa-pen"></i> Editar</a>
          <form method="post" onsubmit="return confirm('¿Seguro que quieres eliminar este producto?');">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit"><i class="fa-solid fa-trash"></i> Eliminar</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <footer>Tienda Tech · Programación 5</footer>
</div>
</body>
</html>
