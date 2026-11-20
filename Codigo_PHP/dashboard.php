<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
include 'conexion.php';

// Capturar filtros seleccionados
$area_filtro = $_GET['area'] ?? '';
$piso_filtro = $_GET['piso'] ?? '';
$conexion_filtro = $_GET['tipo_conexion'] ?? '';

// Poblar dinamicamente los selectores de filtros desde las dimensiones
$areas_disponibles = $pdo->query("SELECT DISTINCT Area_Hospital FROM dim_ubicacion ORDER BY Area_Hospital")->fetchAll(PDO::FETCH_COLUMN);
$pisos_disponibles = $pdo->query("SELECT DISTINCT Piso FROM dim_ubicacion ORDER BY Piso")->fetchAll(PDO::FETCH_COLUMN);
$conexiones_disponibles = $pdo->query("SELECT DISTINCT Tipo_Conexion FROM dim_red_infraestructura WHERE Tipo_Conexion IS NOT NULL ORDER BY Tipo_Conexion")->fetchAll(PDO::FETCH_COLUMN);

// Construir clausula WHERE dinamica para la vista de cada rol
$where_clauses = ["1=1"];
$params = [];

if (!empty($area_filtro)) {
    $where_clauses[] = "Area_Hospital = ?";
    $params[] = $area_filtro;
}
if (!empty($piso_filtro)) {
    $where_clauses[] = "Piso = ?";
    $params[] = $piso_filtro;
}
if (!empty($conexion_filtro)) {
    $where_clauses[] = "Tipo_Conexion = ?";
    $params[] = $conexion_filtro;
}
$where_sql = implode(" AND ", $where_clauses);

// KPIs Generales Dinamicos desde vw_telemetria_maestra_bi
$sql_kpis = "SELECT 
                COUNT(*) AS total_sesiones,
                ROUND(AVG(Experiencia), 1) AS exp_promedio,
                ROUND(SUM(Descarga_GB), 1) AS total_descarga,
                ROUND(SUM(Trafico_Total_GB), 1) AS trafico_total,
                ROUND(AVG(Senal_dBm), 1) AS senal_promedio,
                SUM(CASE WHEN Nivel_Experiencia = 'Degradada' THEN 1 ELSE 0 END) AS sesiones_degradadas
             FROM vw_telemetria_maestra_bi
             WHERE $where_sql";
$stmt_kpis = $pdo->prepare($sql_kpis);
$stmt_kpis->execute($params);
$kpis = $stmt_kpis->fetch(PDO::FETCH_ASSOC);

// Implementacion de dos roles
$rol = $_SESSION['rol'];

if ($rol === 'gerente') {
    // Salud de red por area filtrada
    $sql_salud = "SELECT 
                    Piso,
                    Area_Hospital,
                    COUNT(ID_Sesion) AS Total_Sesiones,
                    COUNT(DISTINCT Direccion_MAC) AS Dispositivos_Unicos,
                    ROUND(AVG(Experiencia), 1) AS Experiencia_Promedio,
                    ROUND(AVG(Senal_dBm), 1) AS Senal_Promedio_dBm,
                    ROUND(SUM(Trafico_Total_GB), 2) AS Consumo_Total_GB,
                    ROUND((SUM(CASE WHEN Experiencia < 60 THEN 1 ELSE 0 END) * 100.0) / NULLIF(COUNT(ID_Sesion), 0), 1) AS Porcentaje_Fallas_SLA
                  FROM vw_telemetria_maestra_bi
                  WHERE $where_sql
                  GROUP BY Piso, Area_Hospital
                  ORDER BY Experiencia_Promedio ASC, Consumo_Total_GB DESC";
    $stmt_salud = $pdo->prepare($sql_salud);
    $stmt_salud->execute($params);
    $salud_areas = $stmt_salud->fetchAll(PDO::FETCH_ASSOC);
    
    // Identificar area con mayor demanda dentro de los filtros
    $area_top = !empty($salud_areas) ? $salud_areas[0] : null;
    $max_gb = 0;
    foreach ($salud_areas as $sa) {
        if ($sa['Consumo_Total_GB'] > $max_gb) {
            $max_gb = $sa['Consumo_Total_GB'];
            $area_top = $sa;
        }
    }
} else {
    // Auditoria IoT e Infraestructura filtrada
    $sql_iot = "SELECT 
                    ID_Vlan,
                    Red,
                    Tipo_Dispositivo,
                    Rol_Usuario,
                    Banda_Wifi,
                    ROUND(AVG(Senal_dBm), 1) AS Promedio_RSSI_dBm,
                    ROUND(AVG(Experiencia), 1) AS Promedio_Experiencia,
                    ROUND(AVG(Velocidad_TX), 1) AS Promedio_TX_Mbps,
                    ROUND(AVG(Velocidad_RX), 1) AS Promedio_RX_Mbps
                FROM vw_telemetria_maestra_bi
                WHERE $where_sql
                GROUP BY ID_Vlan, Red, Tipo_Dispositivo, Rol_Usuario, Banda_Wifi
                ORDER BY Promedio_Experiencia ASC
                LIMIT 15";
    $stmt_iot = $pdo->prepare($sql_iot);
    $stmt_iot->execute($params);
    $auditoria_iot = $stmt_iot->fetchAll(PDO::FETCH_ASSOC);
    
    // Top consumo por aplicacion y hora pico filtrado
    $sql_pico = "SELECT 
                    Dia_Semana,
                    Hora_Conexion,
                    Aplicacion_Principal,
                    ROUND(SUM(Trafico_Total_GB), 2) AS Trafico_Total_GB
                 FROM vw_telemetria_maestra_bi
                 WHERE $where_sql
                 GROUP BY Dia_Semana, Hora_Conexion, Aplicacion_Principal
                 ORDER BY Trafico_Total_GB DESC
                 LIMIT 8";
    $stmt_pico = $pdo->prepare($sql_pico);
    $stmt_pico->execute($params);
    $horas_pico = $stmt_pico->fetchAll(PDO::FETCH_ASSOC);
    
    // Porcentaje de trafico en red de invitados o streaming filtrado
    $sql_inv = "SELECT 
                    ROUND((SUM(CASE WHEN Red LIKE '%Invitado%' OR Aplicacion_Principal LIKE '%Stream%' OR Aplicacion_Principal LIKE '%Video%' THEN Trafico_Total_GB ELSE 0 END) * 100.0) / NULLIF(SUM(Trafico_Total_GB), 0), 1) AS pct_no_critico
                FROM vw_telemetria_maestra_bi
                WHERE $where_sql";
    $stmt_inv = $pdo->prepare($sql_inv);
    $stmt_inv->execute($params);
    $pct_no_critico = $stmt_inv->fetchColumn() ?? 0;

    // Piso con peor señal promedio filtrado, considerando unicamente la red wireless
    $sql_piso = "SELECT Piso, ROUND(AVG(Senal_dBm), 1) as rssi 
                 FROM vw_telemetria_maestra_bi 
                 WHERE $where_sql AND Senal_dBm IS NOT NULL 
                 GROUP BY Piso 
                 ORDER BY rssi ASC 
                 LIMIT 1";
    $stmt_piso = $pdo->prepare($sql_piso);
    $stmt_piso->execute($params);
    $peor_piso = $stmt_piso->fetch(PDO::FETCH_ASSOC);
}

// Detalle de sesiones filtradas desde vw_telemetria_maestra_bi
$sql_detalle = "SELECT * FROM vw_telemetria_maestra_bi WHERE $where_sql LIMIT 100";
$stmt_detalle = $pdo->prepare($sql_detalle);
$stmt_detalle->execute($params);
$sesiones = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoreo de Logs - Telemetría Hospitalaria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --azul-institucional: #1B396A;
            --azul-suave: #DCE6F2;
            --naranja-acento: #E8852A;
        }
        body { background-color: #f4f6f9; }
        .navbar-institucional { background-color: var(--azul-institucional); }
        .card-kpi { border: none; border-left: 4px solid var(--azul-institucional); border-radius: 8px; }
        .card-kpi.accent { border-left-color: var(--naranja-acento); }
        .btn-institucional { background-color: var(--azul-institucional); color: #fff; border: none; }
        .btn-institucional:hover { background-color: #13294d; color: #fff; }
        .table-institucional thead { background-color: var(--azul-institucional); color: #fff; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark navbar-institucional px-4 shadow-sm">
        <span class="navbar-brand mb-0 h1">Hospital BI — Telemetría de Red</span>
        <div>
            <span class="text-white me-3">
                Usuario: <strong><?php echo htmlspecialchars($_SESSION['usuario']); ?></strong> 
                <span class="badge bg-light text-dark ms-1"><?php echo ucfirst(htmlspecialchars($rol)); ?></span>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
        </div>
    </nav>

    <div class="container-fluid py-4 px-4">

        <!-- Filtros Globales -->
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-body">
                <h6 class="card-title text-uppercase text-muted fw-bold mb-3">Filtros de Segmentación</h6>
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Área del Hospital</label>
                        <select name="area" class="form-select">
                            <option value="">Todas las áreas</option>
                            <?php foreach ($areas_disponibles as $area): ?>
                                <option value="<?php echo htmlspecialchars($area); ?>" <?php echo ($area_filtro === $area) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($area); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Piso</label>
                        <select name="piso" class="form-select">
                            <option value="">Todos los pisos</option>
                            <?php foreach ($pisos_disponibles as $piso): ?>
                                <option value="<?php echo htmlspecialchars($piso); ?>" <?php echo ($piso_filtro === $piso) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($piso); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Tipo de Conexión</label>
                        <select name="tipo_conexion" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($conexiones_disponibles as $conn): ?>
                                <option value="<?php echo htmlspecialchars($conn); ?>" <?php echo ($conexion_filtro === $conn) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($conn); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-institucional w-100 me-2">Aplicar Filtros</button>
                        <a href="dashboard.php" class="btn btn-outline-secondary w-100">Limpiar</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($rol === 'gerente'): ?>

            <!-- Vista gerencial -->
            <div class="alert border-0 shadow-sm" style="background-color: var(--azul-suave); color: var(--azul-institucional);">
                <strong>Vista Ejecutiva:</strong> Indicadores clave de nivel de servicio (SLA), experiencia por área clínica y demanda de capacidad.
            </div>

            <!-- Tarjetas KPI Gerente -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card card-kpi shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">EXPERIENCIA GLOBAL PROMEDIO</small>
                        <h3 class="mt-2 mb-0 <?php echo (($kpis['exp_promedio'] ?? 0) >= 75) ? 'text-success' : 'text-warning'; ?>">
                            <?php echo $kpis['exp_promedio'] ?? 0; ?>%
                        </h3>
                        <small class="text-muted">Meta SLA: &ge; 80%</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-kpi shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">TRÁFICO TOTAL CONSUMIDO</small>
                        <h3 class="mt-2 mb-0" style="color: var(--azul-institucional);">
                            <?php echo $kpis['trafico_total'] ?? 0; ?> GB
                        </h3>
                        <small class="text-muted">Descarga: <?php echo $kpis['total_descarga'] ?? 0; ?> GB</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-kpi accent shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">ÁREA DE MAYOR DEMANDA</small>
                        <h4 class="mt-2 mb-0" style="color: var(--naranja-acento);">
                            <?php echo $area_top ? htmlspecialchars($area_top['Area_Hospital']) . " (" . htmlspecialchars($area_top['Piso']) . ")" : 'Sin datos'; ?>
                        </h4>
                        <small class="text-muted"><?php echo $area_top ? $area_top['Consumo_Total_GB'] . ' GB acumulados' : 'Ajusta los filtros'; ?></small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-kpi accent shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">SESIONES CON EXPERIENCIA DEGRADADA</small>
                        <h3 class="mt-2 mb-0 text-danger">
                            <?php echo number_format($kpis['sesiones_degradadas'] ?? 0); ?>
                        </h3>
                        <small class="text-muted">De <?php echo number_format($kpis['total_sesiones'] ?? 0); ?> sesiones filtradas</small>
                    </div>
                </div>
            </div>

            <!-- Grafica y Tabla Ejecutiva por area -->
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="card-title fw-bold mb-3" style="color: var(--azul-institucional);">Consumo de Datos (GB) y Experiencia (%) por Área</h6>
                            <?php if (!empty($salud_areas)): ?>
                                <canvas id="chartGerenteAreas" height="220"></canvas>
                            <?php else: ?>
                                <div class="text-center text-muted py-5">No hay datos suficientes para graficar con los filtros actuales.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="card-title fw-bold mb-3" style="color: var(--azul-institucional);">Matriz de Cumplimiento SLA por Área y Piso</h6>
                            <div class="table-responsive" style="max-height: 310px;">
                                <table class="table table-sm table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Piso</th>
                                            <th>Área Hospital</th>
                                            <th>Dispositivos</th>
                                            <th>Exp. Prom.</th>
                                            <th>Consumo</th>
                                            <th>% Fallas SLA</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($salud_areas)): ?>
                                            <?php foreach ($salud_areas as $sa): ?>
                                                <tr>
                                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($sa['Piso']); ?></span></td>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars($sa['Area_Hospital']); ?></td>
                                                    <td><?php echo $sa['Dispositivos_Unicos']; ?></td>
                                                    <td><?php echo $sa['Experiencia_Promedio']; ?>%</td>
                                                    <td><?php echo $sa['Consumo_Total_GB']; ?> GB</td>
                                                    <td>
                                                        <span class="badge bg-<?php echo ($sa['Porcentaje_Fallas_SLA'] > 20) ? 'danger' : (($sa['Porcentaje_Fallas_SLA'] > 10) ? 'warning text-dark' : 'success'); ?>">
                                                            <?php echo $sa['Porcentaje_Fallas_SLA']; ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4">Sin registros para esta combinación de filtros.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ==================== VISTA SISTEMAS / REDES ==================== -->
            <div class="alert border-0 shadow-sm" style="background-color: var(--azul-suave); color: var(--azul-institucional);">
                <strong>Vista de Ingeniería de Redes (Sistemas):</strong> Monitoreo de RF (dBm), segmentación VLAN, comportamiento IoT/Médico y horas pico.
            </div>

            <!-- Tarjetas KPI Sistemas -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card card-kpi shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">SEÑAL WIFI PROMEDIO (RSSI)</small>
                        <h3 class="mt-2 mb-0" style="color: var(--azul-institucional);">
                            <?php echo ($kpis['senal_promedio'] !== null) ? $kpis['senal_promedio'] . ' dBm' : 'N/A'; ?>
                        </h3>
                        <small class="text-muted">Umbral óptimo: &gt; -65 dBm</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-kpi accent shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">TRÁFICO NO CRÍTICO / INVITADOS</small>
                        <h3 class="mt-2 mb-0 text-danger">
                            <?php echo $pct_no_critico; ?>%
                        </h3>
                        <small class="text-muted">Del volumen filtrado en GB</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-kpi shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">SESIONES ANALIZADAS</small>
                        <h3 class="mt-2 mb-0 text-success">
                            <?php echo number_format($kpis['total_sesiones'] ?? 0); ?>
                        </h3>
                        <small class="text-muted">Experiencia media: <?php echo $kpis['exp_promedio'] ?? 0; ?>%</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-kpi accent shadow-sm p-3 h-100">
                        <small class="text-muted fw-bold">ZONA CON MAYOR ATENUACIÓN</small>
                        <h4 class="mt-2 mb-0" style="color: var(--naranja-acento);">
                            <?php echo $peor_piso ? htmlspecialchars($peor_piso['Piso']) . " (" . $peor_piso['rssi'] . " dBm)" : 'N/A'; ?>
                        </h4>
                        <small class="text-muted"><?php echo $peor_piso ? 'Requiere revisión de cobertura AP' : 'Sin registros WiFi filtrados'; ?></small>
                    </div>
                </div>
            </div>

            <!-- Gráfica y Tabla Técnica para Sistemas -->
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="card-title fw-bold mb-3" style="color: var(--azul-institucional);">Auditoría de Infraestructura y Dispositivos IoT (Peor Experiencia)</h6>
                            <div class="table-responsive" style="max-height: 310px;">
                                <table class="table table-sm table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>VLAN / Red</th>
                                            <th>Dispositivo (Rol)</th>
                                            <th>Banda</th>
                                            <th>RSSI</th>
                                            <th>Exp.</th>
                                            <th>TX/RX Mbps</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($auditoria_iot)): ?>
                                            <?php foreach ($auditoria_iot as $iot): ?>
                                                <tr>
                                                    <td><small class="fw-bold">VLAN <?php echo $iot['ID_Vlan']; ?></small><br><small class="text-muted"><?php echo htmlspecialchars($iot['Red']); ?></small></td>
                                                    <td><?php echo htmlspecialchars($iot['Tipo_Dispositivo']); ?><br><small class="text-muted"><?php echo htmlspecialchars($iot['Rol_Usuario']); ?></small></td>
                                                    <td><?php echo htmlspecialchars($iot['Banda_Wifi'] ?? 'Cable'); ?></td>
                                                    <td><?php echo $iot['Promedio_RSSI_dBm'] !== null ? $iot['Promedio_RSSI_dBm'] . ' dBm' : 'N/A'; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo ($iot['Promedio_Experiencia'] < 65) ? 'danger' : 'success'; ?>">
                                                            <?php echo $iot['Promedio_Experiencia']; ?>%
                                                        </span>
                                                    </td>
                                                    <td><small><?php echo $iot['Promedio_TX_Mbps'] . ' / ' . $iot['Promedio_RX_Mbps']; ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4">Sin registros para esta combinación de filtros.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="card-title fw-bold mb-3" style="color: var(--azul-institucional);">Top Aplicaciones y Horas Pico de Tráfico (GB)</h6>
                            <?php if (!empty($horas_pico)): ?>
                                <canvas id="chartSistemasPico" height="220"></canvas>
                            <?php else: ?>
                                <div class="text-center text-muted py-5">No hay datos de tráfico para graficar con los filtros actuales.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabla Maestra de Sesiones -->
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0 fw-bold" style="color: var(--azul-institucional);">Detalle de Sesiones de Red (Top 50 registros filtrados)</h5>
                    <span class="badge bg-secondary">Fuente: vw_telemetria_maestra_bi</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle table-institucional">
                        <thead>
                            <tr>
                                <th>ID Sesión</th>
                                <th>Dispositivo / Rol</th>
                                <th>Área (Piso)</th>
                                <th>Conexión / Banda</th>
                                <th>Red / VLAN</th>
                                <th>Calidad Señal</th>
                                <th>Experiencia</th>
                                <th>Aplicación</th>
                                <th>Tráfico Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sesiones) > 0): ?>
                                <?php foreach ($sesiones as $row): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['ID_Sesion']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($row['Tipo_Dispositivo']); ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($row['Rol_Usuario']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['Area_Hospital']) . " <strong>(" . htmlspecialchars($row['Piso']) . ")</strong>"; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo ($row['Tipo_Conexion'] == 'WiFi') ? 'info text-dark' : 'secondary'; ?>">
                                                <?php echo htmlspecialchars($row['Tipo_Conexion']); ?>
                                            </span>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($row['Banda_Wifi'] ?? 'Ethernet'); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['Red']); ?> <small class="text-muted">(VLAN <?php echo $row['ID_Vlan']; ?>)</small></td>
                                        <td>
                                            <?php echo $row['Senal_dBm'] !== null ? $row['Senal_dBm'] . ' dBm' : 'N/A'; ?>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($row['Calidad_Senal']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo ($row['Nivel_Experiencia'] == 'Óptima') ? 'success' : (($row['Nivel_Experiencia'] == 'Aceptable') ? 'warning text-dark' : 'danger'); ?>">
                                                <?php echo $row['Experiencia'] . '% (' . $row['Nivel_Experiencia'] . ')'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['Aplicacion_Principal']); ?></td>
                                        <td class="fw-semibold"><?php echo $row['Trafico_Total_GB'] . ' GB'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No se encontraron registros con los filtros seleccionados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts de Visualizacion -->
    <script>
        <?php if ($rol === 'gerente' && !empty($salud_areas)): ?>
            const ctxGerente = document.getElementById('chartGerenteAreas').getContext('2d');
            new Chart(ctxGerente, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_map(fn($r) => $r['Area_Hospital'] . ' (' . $r['Piso'] . ')', $salud_areas)); ?>,
                    datasets: [
                        {
                            label: 'Consumo Total (GB)',
                            data: <?php echo json_encode(array_column($salud_areas, 'Consumo_Total_GB')); ?>,
                            backgroundColor: '#1B396A',
                            yAxisID: 'y'
                        },
                        {
                            label: 'Experiencia Promedio (%)',
                            data: <?php echo json_encode(array_column($salud_areas, 'Experiencia_Promedio')); ?>,
                            backgroundColor: '#E8852A',
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: { type: 'linear', position: 'left', title: { display: true, text: 'GB Consumidos' } },
                        y1: { type: 'linear', position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: 'Experiencia (%)' } }
                    }
                }
            });
        <?php elseif ($rol !== 'gerente' && !empty($horas_pico)): ?>
            const ctxSistemas = document.getElementById('chartSistemasPico').getContext('2d');
            new Chart(ctxSistemas, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_map(fn($r) => $r['Aplicacion_Principal'] . ' (' . $r['Dia_Semana'] . ' ' . $r['Hora_Conexion'] . 'h)', $horas_pico)); ?>,
                    datasets: [{
                        label: 'Tráfico Total (GB)',
                        data: <?php echo json_encode(array_column($horas_pico, 'Trafico_Total_GB')); ?>,
                        backgroundColor: '#1B396A',
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { x: { title: { display: true, text: 'Volumen de Tráfico (GB)' } } }
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>