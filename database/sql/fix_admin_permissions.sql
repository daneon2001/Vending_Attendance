-- Asegura que el rol Administrador y los permisos de configuraciГіn existan.
SET @now := NOW();
SET @adminEmail := 'admin@asistencias.test';

INSERT INTO roles (name, description, is_system, created_at, updated_at)
SELECT 'Administrador', 'Acceso total al sistema', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Administrador');

INSERT INTO permissions (module, action, name, description, created_at, updated_at)
SELECT 'settings', 'view', 'ConfiguraciГіn - Ver', 'Permite ver ConfiguraciГіn', @now, @now
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE module = 'settings' AND action = 'view'
);

INSERT INTO permissions (module, action, name, description, created_at, updated_at)
SELECT 'settings', 'manage', 'ConfiguraciГіn - Administrar', 'Permite administrar ConfiguraciГіn', @now, @now
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE module = 'settings' AND action = 'manage'
);

INSERT INTO permission_role (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, @now, @now
FROM roles r
JOIN permissions p ON p.module = 'settings' AND p.action IN ('view', 'manage')
WHERE r.name = 'Administrador'
  AND NOT EXISTS (
      SELECT 1 FROM permission_role pr
      WHERE pr.role_id = r.id
        AND pr.permission_id = p.id
  );

INSERT INTO role_user (role_id, user_id, created_at, updated_at)
SELECT r.id, u.id, @now, @now
FROM roles r
JOIN users u ON u.email = @adminEmail
WHERE r.name = 'Administrador'
  AND NOT EXISTS (
      SELECT 1 FROM role_user ru
      WHERE ru.role_id = r.id
        AND ru.user_id = u.id
  );
