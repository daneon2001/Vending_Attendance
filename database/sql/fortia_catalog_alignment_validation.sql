-- Validaciones base para alineacion Fortia (sin modificar employees)

-- 1) Conteos principales
SELECT 'employees' AS metric, COUNT(*) AS total FROM employees
UNION ALL
SELECT 'employee_details', COUNT(*) FROM employee_details
UNION ALL
SELECT 'companies', COUNT(*) FROM companies
UNION ALL
SELECT 'locations', COUNT(*) FROM locations;

-- 2) Duplicados de clave Fortia en employees (debe ser 0)
SELECT fortia_employee_id, COUNT(*) AS duplicated
FROM employees
GROUP BY fortia_employee_id
HAVING COUNT(*) > 1;

-- 3) Empleados sin match en companies por fortia_company_id (debe tender a 0)
SELECT COUNT(*) AS employees_without_company_match
FROM employees e
LEFT JOIN companies c
  ON c.fortia_company_id = e.company_id
WHERE e.company_id IS NOT NULL
  AND c.id IS NULL;

-- 4) Empleados sin match en locations por fortia_location_id (debe tender a 0)
SELECT COUNT(*) AS employees_without_location_match
FROM employees e
LEFT JOIN locations l
  ON l.fortia_location_id = e.base_location_id
WHERE e.base_location_id IS NOT NULL
  AND l.id IS NULL;

-- 5) Diferencias de nombre de empresa entre employees y companies
SELECT
    e.id,
    e.fortia_employee_id,
    e.company_id,
    e.company_name AS employee_company_name,
    c.name AS catalog_company_name
FROM employees e
JOIN companies c
  ON c.fortia_company_id = e.company_id
WHERE e.company_name IS NOT NULL
  AND c.name IS NOT NULL
  AND TRIM(LOWER(e.company_name)) <> TRIM(LOWER(c.name))
LIMIT 20;

-- 6) Diferencias de nombre de ubicacion entre employees y locations
SELECT
    e.id,
    e.fortia_employee_id,
    e.base_location_id,
    e.base_location_name AS employee_location_name,
    l.name AS catalog_location_name
FROM employees e
JOIN locations l
  ON l.fortia_location_id = e.base_location_id
WHERE e.base_location_name IS NOT NULL
  AND l.name IS NOT NULL
  AND TRIM(LOWER(e.base_location_name)) <> TRIM(LOWER(l.name))
LIMIT 20;

-- 7) Empleados sin employee_details
SELECT COUNT(*) AS employees_without_details
FROM employees e
LEFT JOIN employee_details d ON d.employee_id = e.id
WHERE d.id IS NULL;

-- 8) Inconsistencia entre employee_details y campos denormalizados de employees
SELECT
    SUM(CASE WHEN rs.cla_razon_social IS NOT NULL
             AND CAST(rs.cla_razon_social AS CHAR) <> CAST(e.company_id AS CHAR)
             THEN 1 ELSE 0 END) AS company_code_mismatch,
    SUM(CASE WHEN ub.cla_ubicacion IS NOT NULL
             AND CAST(ub.cla_ubicacion AS CHAR) <> CAST(e.base_location_id AS CHAR)
             THEN 1 ELSE 0 END) AS location_code_mismatch,
    SUM(CASE WHEN dep.cla_depto IS NOT NULL
             AND CAST(dep.cla_depto AS CHAR) <> CAST(e.department_id AS CHAR)
             THEN 1 ELSE 0 END) AS department_code_mismatch
FROM employee_details d
JOIN employees e ON e.id = d.employee_id
LEFT JOIN razones_sociales rs ON rs.id = d.razon_social_id
LEFT JOIN ubicaciones ub ON ub.id = d.ubicacion_id
LEFT JOIN departamentos dep ON dep.id = d.departamento_id;
