-- ------------------------------------------------------------
-- Script: seed_attendance_dec_2025.sql
-- Propósito: Generar checadas de prueba (entradas/salidas) para
--            empleados que ya tienen huella registrada.
-- Base:     Proyecto Medical Life - asistencia biométrica
-- Uso:      Ejecutar en entorno local de pruebas MySQL 8+
-- ------------------------------------------------------------

SET @start_date := '2025-12-01';
SET @end_date   := '2025-12-20';
SET @seed_tag   := 'seed_dec_2025';

-- Limpieza previa (permite re-ejecutar el script sin duplicar)
DELETE
FROM attendance_logs
WHERE function_str = @seed_tag
  AND log_date >= @start_date
  AND log_date < DATE_ADD(@end_date, INTERVAL 1 DAY);

-- Inserta 4 checadas base por día (Entrada, Comida salida/regreso, Salida)
WITH RECURSIVE date_series AS (
    SELECT @start_date AS log_day
    UNION ALL
    SELECT DATE_ADD(log_day, INTERVAL 1 DAY)
    FROM date_series
    WHERE log_day < @end_date
),
eligible_employees AS (
    SELECT id,
           fortia_employee_id,
           company_id,
           base_location_id
    FROM employees
    WHERE has_fingerprint = 1
),
active_days AS (
    SELECT
        e.id AS employee_id,
        e.fortia_employee_id,
        e.company_id,
        e.base_location_id AS location_id,
        ds.log_day,
        RAND(CRC32(CONCAT(e.id, DATE_FORMAT(ds.log_day, '%Y%m%d'), 'active'))) AS day_probability
    FROM eligible_employees e
    CROSS JOIN date_series ds
),
selected_days AS (
    SELECT *
    FROM active_days
    WHERE day_probability < 0.85 -- aprox 85% de días trabajados
),
slots AS (
    SELECT 1 AS slot_no, '07:30:00' AS base_time, 120 AS jitter_range, 1 AS log_type
    UNION ALL SELECT 2, '13:00:00', 120, 2
    UNION ALL SELECT 3, '14:00:00', 150, 3
    UNION ALL SELECT 4, '17:00:00', 210, 4
)
INSERT INTO attendance_logs (
    employee_id,
    fortia_employee_id,
    company_id,
    location_id,
    device_id,
    log_date,
    log_type,
    function_int,
    function_str,
    sent_to_fortia_at,
    fortia_status,
    fortia_response_payload,
    created_at,
    updated_at
)
SELECT
    d.employee_id,
    d.fortia_employee_id,
    d.company_id,
    d.location_id,
    NULL AS device_id,
    TIMESTAMPADD(
        MINUTE,
        FLOOR(RAND(CRC32(CONCAT(d.employee_id, DATE_FORMAT(d.log_day, '%Y%m%d'), s.slot_no))) * s.jitter_range),
        TIMESTAMP(d.log_day, s.base_time)
    ) AS log_date,
    s.log_type,
    NULL,
    @seed_tag,
    NULL,
    NULL,
    NULL,
    NOW(),
    NOW()
FROM selected_days d
JOIN slots s ON 1 = 1;

-- Doble entrada (15-25% de probabilidad)
WITH duplicate_entry_days AS (
    SELECT
        d.employee_id,
        d.fortia_employee_id,
        d.company_id,
        d.location_id,
        d.log_day
    FROM selected_days d
    WHERE RAND(CRC32(CONCAT(d.employee_id, DATE_FORMAT(d.log_day, '%Y%m%d'), 'dup_entry'))) BETWEEN 0.15 AND 0.40
)
INSERT INTO attendance_logs (
    employee_id,
    fortia_employee_id,
    company_id,
    location_id,
    device_id,
    log_date,
    log_type,
    function_int,
    function_str,
    sent_to_fortia_at,
    fortia_status,
    fortia_response_payload,
    created_at,
    updated_at
)
SELECT
    d.employee_id,
    d.fortia_employee_id,
    d.company_id,
    d.location_id,
    NULL,
    TIMESTAMPADD(
        MINUTE,
        3 + FLOOR(RAND(CRC32(CONCAT(d.employee_id, DATE_FORMAT(d.log_day, '%Y%m%d'), 'dup_entry_time'))) * 6),
        TIMESTAMP(d.log_day, '07:45:00')
    ),
    1,
    NULL,
    @seed_tag,
    NULL,
    NULL,
    NULL,
    NOW(),
    NOW()
FROM duplicate_entry_days d;

-- Doble salida (10-20% de probabilidad)
WITH duplicate_exit_days AS (
    SELECT
        d.employee_id,
        d.fortia_employee_id,
        d.company_id,
        d.location_id,
        d.log_day
    FROM selected_days d
    WHERE RAND(CRC32(CONCAT(d.employee_id, DATE_FORMAT(d.log_day, '%Y%m%d'), 'dup_exit'))) BETWEEN 0.10 AND 0.30
)
INSERT INTO attendance_logs (
    employee_id,
    fortia_employee_id,
    company_id,
    location_id,
    device_id,
    log_date,
    log_type,
    function_int,
    function_str,
    sent_to_fortia_at,
    fortia_status,
    fortia_response_payload,
    created_at,
    updated_at
)
SELECT
    d.employee_id,
    d.fortia_employee_id,
    d.company_id,
    d.location_id,
    NULL,
    TIMESTAMPADD(
        MINUTE,
        5 + FLOOR(RAND(CRC32(CONCAT(d.employee_id, DATE_FORMAT(d.log_day, '%Y%m%d'), 'dup_exit_time'))) * 10),
        TIMESTAMP(d.log_day, '19:00:00')
    ),
    4,
    NULL,
    @seed_tag,
    NULL,
    NULL,
    NULL,
    NOW(),
    NOW()
FROM duplicate_exit_days d;

-- Evento de ruido (5-10%): una marca extra entre comida y salida
WITH noise_days AS (
    SELECT
        d.employee_id,
        d.fortia_employee_id,
        d.company_id,
        d.location_id,
        d.log_day
    FROM selected_days d
    WHERE RAND(CRC32(CONCAT(d.employee_id, DATE_FORMAT(d.log_day, '%Y%m%d'), 'noise'))) BETWEEN 0.05 AND 0.15
)
INSERT INTO attendance_logs (
    employee_id,
    fortia_employee_id,
    company_id,
    location_id,
    device_id,
    log_date,
    log_type,
    function_int,
    function_str,
    sent_to_fortia_at,
    fortia_status,
    fortia_response_payload,
    created_at,
    updated_at
)
SELECT
    d.employee_id,
    d.fortia_employee_id,
    d.company_id,
    d.location_id,
    NULL,
    TIMESTAMPADD(
        MINUTE,
        FLOOR(RAND(CRC32(CONCAT(d.employee_id, DATE_FORMAT(d.log_day, '%Y%m%d'), 'noise_time'))) * 80),
        TIMESTAMP(d.log_day, '15:45:00')
    ),
    3,
    NULL,
    @seed_tag,
    NULL,
    NULL,
    NULL,
    NOW(),
    NOW()
FROM noise_days d;

-- ------------------------------------------------------------
-- Consultas de verificación
-- ------------------------------------------------------------
-- Conteo de empleados con huella
SELECT COUNT(*) AS empleados_con_huella
FROM employees
WHERE has_fingerprint = 1;

-- Conteo de registros generados por día
SELECT DATE(log_date) AS dia, COUNT(*) AS total_registros
FROM attendance_logs
WHERE function_str = @seed_tag
GROUP BY dia
ORDER BY dia;

-- Ejemplo de un empleado concreto (reemplazar 1 por el ID deseado)
SELECT *
FROM attendance_logs
WHERE employee_id = 1
  AND function_str = @seed_tag
ORDER BY log_date;
