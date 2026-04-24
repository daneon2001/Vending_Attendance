-- Validaciones operativas de catalogo de relojes (clocks)

-- 1) Totales base
SELECT 'clocks_total' AS metric, COUNT(*) AS total FROM clocks
UNION ALL
SELECT 'clocks_without_location', COUNT(*) FROM clocks WHERE location_id IS NULL
UNION ALL
SELECT 'clocks_without_name', COUNT(*) FROM clocks WHERE clock_name IS NULL OR TRIM(clock_name) = ''
UNION ALL
SELECT 'clocks_inactive', COUNT(*) FROM clocks WHERE status = 0;

-- 2) Duplicados por serial
SELECT serial_number, COUNT(*) AS duplicated
FROM clocks
WHERE serial_number IS NOT NULL AND TRIM(serial_number) <> ''
GROUP BY serial_number
HAVING COUNT(*) > 1;

-- 3) Relojes con empresa no encontrada
SELECT COUNT(*) AS clocks_company_not_found
FROM clocks c
LEFT JOIN companies co ON co.id = c.company_id
WHERE c.company_id IS NOT NULL
  AND co.id IS NULL;

-- 4) Muestra de relojes sin unidad asignada (pendientes de segunda fase)
SELECT c.id, c.serial_number, c.clock_name, c.company_id, co.name AS company_name
FROM clocks c
LEFT JOIN companies co ON co.id = c.company_id
WHERE c.location_id IS NULL
ORDER BY c.id
LIMIT 20;
