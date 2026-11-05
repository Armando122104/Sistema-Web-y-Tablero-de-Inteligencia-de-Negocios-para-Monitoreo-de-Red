<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
include 'conexion.php';

// Capturar filtros seleccionados
$acion_filtro = $_GET['area'] ?? '';
$piso_filtro = $_GET['piso'] ?? '';
$conexion_filtro = $_GET['tipo_conexion'] ?? '';

// Consulta base con filtros dinamicos
$sql = "SELECT f.*, u.Area_Hospital, u.Piso, i.Red, i.Tipo_Conexion, d.Tipo_Dispositivo, d.Rol_Usuario
        FROM fact_sesiones_red f
        JOIN dim_ubicacion u ON f.ID_Ubicacion = u.ID_Ubicacion
        JOIN dim_red_infraestructura i ON f.ID_Infraestructura = i.ID_Infraestructura
        JOIN dim_dispositivo d ON f.ID_Dispositivo = d.ID_Dispositivo
        WHERE 1=1";

$params = [];
if (!empty($area_filtro)) {
    $sql .= " AND u.Area_Hospital = ?";
    $params[] = $area_filtro;
}
if (!empty($piso_filtro)) {
    $sql .= " AND u.Piso = ?";
    $params[] = $piso_filtro;
}
if (!empty($conexion_filtro)) {
    $sql .= " AND i.Tipo_Conexion = ?";
    $params[] = $conexion_filtro;
}

$sql .= " LIMIT 50"; // Mostrar unicamente 50 filas al inicio
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Telemetría Hospitalaria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-dark bg-dark px-4">
        <span class="navbar-brand mb-0 h1">Hospital BI - Panel de Control</span>
        <div>
            <span class="text-white me-3">Usuario: <strong><?php echo $_SESSION['usuario']; ?></strong> (Rol: <em><?php echo ucfirst($_SESSION['rol']); ?></em>)</span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container-fluid py-4">
        
        <?php if ($_SESSION['rol'] == 'gerente'): ?>
            <!-- Vista gerencial -->
            <div class="alert alert-info">
                <strong>Vista Ejecutiva:</strong> Resumen enfocado en la experiencia general del usuario y volumen de uso por área.
            </div>
            <div class="row text-center mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm p-3">
                        <h5>Experiencia Global Promedio</h5>
                        <h3 class="text-success">76.4%</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm p-3">
                        <h5>Tráfico Total Descargado</h5>
                        <h3 class="text-primary">174.7 GB</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm p-3">
                        <h5>Área con Mayor Demanda</h5>
                        <h3 class="text-warning">Hospitalización (P1)</h3>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Vista de sistemas -->
            <div class="alert alert-secondary">
                <strong>Vista de Infraestructura y Redes:</strong> Monitoreo activo de parámetros de RF, dBm y distribución de canales.
            </div>
            <div class="row text-center mb-4">
                <div class="col-md-3">
                    <div class="card shadow-sm p-3">
                        <h6>Señal WiFi Promedio</h6>
                        <h4 class="text-primary">-59.8 dBm</h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm p-3">
                        <h6>Streaming Red Invitados</h6>
                        <h4 class="text-danger">29.3%</h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm p-3">
                        <h6>Sesiones Activas</h6>
                        <h4 class="text-success">1,000</h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm p-3">
                        <h6>Anomalías P3</h6>
                        <h4 class="text-warning">Atenuación Alta</h4>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filtros de datos en la web -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Filtros de Búsqueda</h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Área del Hospital</label>
                        <select name="area" class="form-select">
                            <option value="">Todas las áreas</option>
                            <option value="Hospitalización">Hospitalización</option>
                            <option value="Urgencias">Urgencias</option>
                            <option value="UCI">UCI</option>
                            <option value="Dirección">Dirección</option>
                            <option value="Consulta Externa">Consulta Externa</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Piso</label>
                        <select name="piso" class="form-select">
                            <option value="">Todos los pisos</option>
                            <option value="PB">Planta Baja (PB)</option>
                            <option value="P1">Piso 1</option>
                            <option value="P2">Piso 2</option>
                            <option value="P3">Piso 3</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tipo de Conexión</label>
                        <select name="tipo_conexion" class="form-select">
                            <option value="">Todas</option>
                            <option value="WiFi">WiFi</option>
                            <option value="Cableado">Cableado</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100 me-2">Filtrar</button>
                        <a href="dashboard.php" class="btn btn-secondary w-100">Limpiar</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-3">Detalle de Sesiones de Red Registradas</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID Sesión</th>
                                <th>Dispositivo / Rol</th>
                                <th>Área</th>
                                <th>Piso</th>
                                <th>Conexión</th>
                                <th>Red VLAN</th>
                                <th>Señal (dBm)</th>
                                <th>Experiencia</th>
                                <th>Descarga (GB)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($sesiones) > 0): ?>
                                <?php foreach($sesiones as $row): ?>
                                    <tr>
                                        <td><?php echo $row['ID_Sesion']; ?></td>
                                        <td><?php echo $row['Tipo_Dispositivo'] . " (" . $row['Rol_Usuario'] . ")"; ?></td>
                                        <td><?php echo $row['Area_Hospital']; ?></td>
                                        <td><?php echo $row['Piso']; ?></td>
                                        <td><span class="badge bg-<?php echo ($row['Tipo_Conexion']=='WiFi')?'info':'secondary'; ?>"><?php echo $row['Tipo_Conexion']; ?></span></td>
                                        <td><?php echo $row['Red']; ?></td>
                                        <td><?php echo $row['Senal_dBm'] !== null ? $row['Senal_dBm'] . ' dBm' : 'N/A'; ?></td>
                                        <td><?php echo $row['Experiencia'] . '%'; ?></td>
                                        <td><?php echo $row['Descarga_GB'] . ' GB'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No se encontraron registros con los filtros seleccionados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</body>
</html>