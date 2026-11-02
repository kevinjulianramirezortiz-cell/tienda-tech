-- Tienda Tech - Estructura de la base de datos (referencia)
-- Tu base ya existe en XAMPP con estas tablas, así que NO necesitas importar este archivo.
-- Solo sirve para recrearla en otra computadora.

CREATE DATABASE IF NOT EXISTS tienda_tech
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE tienda_tech;

CREATE TABLE IF NOT EXISTS productos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(120)  NOT NULL,
  categoria   VARCHAR(80)   NULL,
  precio      DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock       INT           NOT NULL DEFAULT 0,
  descripcion TEXT          NULL,
  imagen_ruta VARCHAR(255)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  usuario       VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol           VARCHAR(20)  NOT NULL DEFAULT 'vendedor'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
