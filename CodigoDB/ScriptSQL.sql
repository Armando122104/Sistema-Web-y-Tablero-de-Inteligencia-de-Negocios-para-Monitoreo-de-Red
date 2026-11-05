CREATE DATABASE IF NOT EXISTS hospital_telemetria_bi
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE hospital_telemetria_bi;


CREATE TABLE dim_dispositivo (
    ID_Dispositivo VARCHAR(15) PRIMARY KEY,
    Nombre_Cliente VARCHAR(100) NOT NULL,
    Direccion_MAC VARCHAR(20) NOT NULL,
    Direccion_IP VARCHAR(20) NOT NULL,
    Fabricante VARCHAR(50),
    Tipo_Dispositivo VARCHAR(50),
    Sistema_Operativo VARCHAR(50),
    Rol_Usuario VARCHAR(50)
) ENGINE=InnoDB;

CREATE TABLE dim_ubicacion (
    ID_Ubicacion VARCHAR(15) PRIMARY KEY,
    Area_Hospital VARCHAR(100) NOT NULL,
    Piso VARCHAR(10) NOT NULL
) ENGINE=InnoDB;


CREATE TABLE dim_red_infraestructura (
    ID_Infraestructura VARCHAR(15) PRIMARY KEY,
    ID_Vlan INT,
    Red VARCHAR(50),
    Tipo_Conexion VARCHAR(20),
    SSID VARCHAR(50),
    Conectado_a VARCHAR(100),
    Puerto_Switch FLOAT,
    Banda_Wifi VARCHAR(20),
    Estandar_Wifi VARCHAR(30),
    Canal INT
) ENGINE=InnoDB;


CREATE TABLE dim_tiempo (
    ID_Tiempo VARCHAR(15) PRIMARY KEY,
    Primera_Conexion DATETIME,
    Ultima_Hora_Conexion DATETIME,
    Dia_Semana VARCHAR(20),
    Hora_Conexion INT
) ENGINE=InnoDB;

CREATE TABLE fact_sesiones_red (
    ID_Sesion VARCHAR(15) PRIMARY KEY,
    ID_Dispositivo VARCHAR(15) NOT NULL,
    ID_Ubicacion VARCHAR(15) NOT NULL,
    ID_Infraestructura VARCHAR(15) NOT NULL,
    ID_Tiempo VARCHAR(15) NOT NULL,
    Senal_dBm FLOAT,
    Experiencia INT,
    Velocidad_TX FLOAT,
    Velocidad_RX FLOAT,
    Descarga_GB DECIMAL(10,3),
    Subida_GB DECIMAL(10,3),
    Tiempo_Conectado FLOAT,
    Aplicacion_Principal VARCHAR(100),
    Estado VARCHAR(30),
    CONSTRAINT fk_fact_dispositivo FOREIGN KEY (ID_Dispositivo) REFERENCES dim_dispositivo(ID_Dispositivo),
    CONSTRAINT fk_fact_ubicacion FOREIGN KEY (ID_Ubicacion) REFERENCES dim_ubicacion(ID_Ubicacion),
    CONSTRAINT fk_fact_infraestructura FOREIGN KEY (ID_Infraestructura) REFERENCES dim_red_infraestructura(ID_Infraestructura),
    CONSTRAINT fk_fact_tiempo FOREIGN KEY (ID_Tiempo) REFERENCES dim_tiempo(ID_Tiempo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('gerente', 'sistemas') NOT NULL,
    nombre_completo VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- Roles de prueba
INSERT INTO usuarios (usuario, password, rol, nombre_completo) VALUES 
('gerente', 'admin123', 'gerente', 'Director / Gerencia Hospitalaria'),
('sistemas', 'admin123', 'sistemas', 'Ing. de Soporte / Redes');
