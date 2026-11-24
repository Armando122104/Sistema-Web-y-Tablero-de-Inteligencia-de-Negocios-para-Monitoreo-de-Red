USE `hospital_telemetria_bi`;

-- ============================================================================
-- 1. ÍNDICES DE RENDIMIENTO ANALÍTICO (SARGABILITY Y FILTROS FRECUENTES)
-- ============================================================================
-- Aceleran las consultas de las vistas y los segmentadores (slicers) de Power BI

CREATE INDEX `idx_fact_estado_exp` ON `fact_sesiones_red` (`Estado`, `Experiencia`);
CREATE INDEX `idx_fact_app` ON `fact_sesiones_red` (`Aplicacion_Principal`);
CREATE INDEX `idx_disp_tipo_rol` ON `dim_dispositivo` (`Tipo_Dispositivo`, `Rol_Usuario`);
CREATE INDEX `idx_infra_vlan_banda` ON `dim_red_infraestructura` (`ID_Vlan`, `Tipo_Conexion`, `Banda_Wifi`);
CREATE INDEX `idx_ubic_area_piso` ON `dim_ubicacion` (`Area_Hospital`, `Piso`);
CREATE INDEX `idx_tiempo_dia_hora` ON `dim_tiempo` (`Dia_Semana`, `Hora_Conexion`);


-- ============================================================================
-- 2. VISTAS ANALÍTICAS PARA POWER BI Y PORTAL DE ROLES (GERENTE / SISTEMAS)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- VISTA 1: vw_telemetria_maestra_bi (Sábana desnormalizada con KPIs calculados)
-- Propósito: Vista principal para importar en Power BI o validar cruces completos.
-- Incluye clasificación de calidad de señal (RSSI) y tráfico total en GB.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_telemetria_maestra_bi` AS
SELECT 
    f.ID_Sesion,
    f.Estado,
    -- Dimensiones de Tiempo
    t.Primera_Conexion,
    t.Ultima_Hora_Conexion,
    t.Dia_Semana,
    t.Hora_Conexion,
    -- Dimensiones de Ubicación
    u.Area_Hospital,
    u.Piso,
    -- Dimensiones de Dispositivo
    d.Nombre_Cliente,
    d.Direccion_MAC,
    d.Direccion_IP,
    d.Fabricante,
    d.Tipo_Dispositivo,
    d.Sistema_Operativo,
    d.Rol_Usuario,
    -- Dimensiones de Infraestructura
    i.ID_Vlan,
    i.Red,
    i.Tipo_Conexion,
    i.SSID,
    i.Conectado_a AS Nodo_AP_Switch,
    i.Puerto_Switch,
    i.Banda_Wifi,
    i.Estandar_Wifi,
    i.Canal,
    -- Métricas Crudas
    f.Senal_dBm,
    f.Experiencia,
    f.Velocidad_TX,
    f.Velocidad_RX,
    f.Descarga_GB,
    f.Subida_GB,
    ROUND((IFNULL(f.Descarga_GB, 0) + IFNULL(f.Subida_GB, 0)), 3) AS Trafico_Total_GB,
    f.Tiempo_Conectado AS Tiempo_Conectado_Hrs,
    f.Aplicacion_Principal,
    -- Columnas Calculadas de Negocio (KPIs)
    CASE 
        WHEN f.Senal_dBm IS NULL THEN 'Cableado / N/A'
        WHEN f.Senal_dBm >= -60 THEN 'Excelente (> -60 dBm)'
        WHEN f.Senal_dBm BETWEEN -70 AND -61 THEN 'Buena (-61 a -70 dBm)'
        WHEN f.Senal_dBm BETWEEN -80 AND -71 THEN 'Regular (-71 a -80 dBm)'
        ELSE 'Crítica (< -80 dBm)'
    END AS Calidad_Senal,
    CASE 
        WHEN f.Experiencia >= 85 THEN 'Óptima'
        WHEN f.Experiencia BETWEEN 60 AND 84 THEN 'Aceptable'
        ELSE 'Degradada'
    END AS Nivel_Experiencia
FROM `fact_sesiones_red` f
INNER JOIN `dim_dispositivo` d ON f.ID_Dispositivo = d.ID_Dispositivo
INNER JOIN `dim_ubicacion` u ON f.ID_Ubicacion = u.ID_Ubicacion
INNER JOIN `dim_red_infraestructura` i ON f.ID_Infraestructura = i.ID_Infraestructura
INNER JOIN `dim_tiempo` t ON f.ID_Tiempo = t.ID_Tiempo;


-- ----------------------------------------------------------------------------
-- VISTA 2: vw_salud_red_por_area (Enfoque Ejecutivo / Rol: GERENTE)
-- Propósito: Responde qué áreas del hospital sufren mala experiencia WiFi, 
-- señal débil o saturación de tráfico por piso y área clínica.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_salud_red_por_area` AS
SELECT 
    u.Piso,
    u.Area_Hospital,
    COUNT(f.ID_Sesion) AS Total_Sesiones,
    COUNT(DISTINCT f.ID_Dispositivo) AS Dispositivos_Unicos,
    ROUND(AVG(f.Experiencia), 1) AS Experiencia_Promedio,
    ROUND(AVG(f.Senal_dBm), 1) AS Senal_Promedio_dBm,
    ROUND(SUM(f.Descarga_GB), 2) AS Total_Descarga_GB,
    ROUND(SUM(f.Subida_GB), 2) AS Total_Subida_GB,
    ROUND(SUM(IFNULL(f.Descarga_GB, 0) + IFNULL(f.Subida_GB, 0)), 2) AS Consumo_Total_GB,
    SUM(CASE WHEN f.Experiencia < 60 THEN 1 ELSE 0 END) AS Sesiones_Experiencia_Degradada,
    ROUND(
        (SUM(CASE WHEN f.Experiencia < 60 THEN 1 ELSE 0 END) * 100.0) / COUNT(f.ID_Sesion), 
        1
    ) AS Porcentaje_Fallas_SLA
FROM `fact_sesiones_red` f
INNER JOIN `dim_ubicacion` u ON f.ID_Ubicacion = u.ID_Ubicacion
GROUP BY u.Piso, u.Area_Hospital
ORDER BY Experiencia_Promedio ASC, Consumo_Total_GB DESC;


-- ----------------------------------------------------------------------------
-- VISTA 3: vw_auditoria_iot_infraestructura (Enfoque Técnico / Rol: SISTEMAS)
-- Propósito: Evalúa el comportamiento de dispositivos IoT/médicos vs usuarios,
-- identificando problemas por VLAN, banda (2.4GHz vs 5GHz) y Access Point.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_auditoria_iot_infraestructura` AS
SELECT 
    i.ID_Vlan,
    i.Red,
    i.SSID,
    i.Conectado_a AS Equipo_Red,
    i.Tipo_Conexion,
    i.Banda_Wifi,
    i.Estandar_Wifi,
    d.Tipo_Dispositivo,
    d.Rol_Usuario,
    COUNT(f.ID_Sesion) AS Cantidad_Conexiones,
    ROUND(AVG(f.Senal_dBm), 1) AS Promedio_RSSI_dBm,
    ROUND(AVG(f.Experiencia), 1) AS Promedio_Experiencia,
    ROUND(AVG(f.Velocidad_TX), 1) AS Promedio_TX_Mbps,
    ROUND(AVG(f.Velocidad_RX), 1) AS Promedio_RX_Mbps,
    ROUND(SUM(IFNULL(f.Descarga_GB, 0) + IFNULL(f.Subida_GB, 0)), 2) AS Trafico_Acumulado_GB
FROM `fact_sesiones_red` f
INNER JOIN `dim_red_infraestructura` i ON f.ID_Infraestructura = i.ID_Infraestructura
INNER JOIN `dim_dispositivo` d ON f.ID_Dispositivo = d.ID_Dispositivo
GROUP BY 
    i.ID_Vlan, i.Red, i.SSID, i.Conectado_a, 
    i.Tipo_Conexion, i.Banda_Wifi, i.Estandar_Wifi, 
    d.Tipo_Dispositivo, d.Rol_Usuario
ORDER BY Promedio_Experiencia ASC;


-- ----------------------------------------------------------------------------
-- VISTA 4: vw_consumo_aplicaciones_horas_pico (Ancho de Banda y Capacidad)
-- Propósito: Identifica qué aplicaciones consumen más ancho de banda y en qué
-- días/horas se producen los cuellos de botella en el hospital.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_consumo_aplicaciones_horas_pico` AS
SELECT 
    t.Dia_Semana,
    t.Hora_Conexion,
    f.Aplicacion_Principal,
    i.Red,
    COUNT(f.ID_Sesion) AS Sesiones_Activas,
    ROUND(SUM(f.Descarga_GB), 2) AS Descarga_GB,
    ROUND(SUM(f.Subida_GB), 2) AS Subida_GB,
    ROUND(SUM(IFNULL(f.Descarga_GB, 0) + IFNULL(f.Subida_GB, 0)), 2) AS Trafico_Total_GB,
    ROUND(AVG(f.Tiempo_Conectado), 2) AS Duracion_Media_Hrs
FROM `fact_sesiones_red` f
INNER JOIN `dim_tiempo` t ON f.ID_Tiempo = t.ID_Tiempo
INNER JOIN `dim_red_infraestructura` i ON f.ID_Infraestructura = i.ID_Infraestructura
GROUP BY t.Dia_Semana, t.Hora_Conexion, f.Aplicacion_Principal, i.Red
ORDER BY Trafico_Total_GB DESC;