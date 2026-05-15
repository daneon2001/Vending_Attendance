# Select Enhancement Guidelines

## Objetivo

El buscador visual de selects del sistema usa TomSelect, pero ahora funciona en modo `opt-in`.
Esto evita que dropdowns simples de filtros, toolbars y formularios pequeños rompan el layout.

## Regla general

Por defecto:

- Un `<select>` se mantiene nativo.
- No se mejora automáticamente.

Sólo se mejora con TomSelect si el select está marcado explícitamente con una de estas opciones:

```html
<select data-select-search="on">
```

```html
<select data-enhance-select="true">
```

```html
<select class="js-select-search">
```

## Cuándo usar buscador

Usa TomSelect sólo cuando el catálogo sea suficientemente grande como para necesitar búsqueda:

- empleados
- empresas
- unidades
- relojes/dispositivos
- catálogos extensos en modales de asignación

## Cuándo NO usar buscador

Deja el select nativo en:

- filtros simples
- Estado / Estatus
- Huella
- Face ID
- registros por página
- selects con pocas opciones
- toolbars

## Desactivar explícitamente

Si un select nunca debe ser tocado por el enhancer:

```html
<select data-select-search="off">
```

También se respeta:

```html
<select data-enhance-select="false">
```

## Notas de implementación

- El inicializador global vive en `resources/js/lib/searchable-selects.js`.
- Evita doble inicialización verificando `select.tomselect` y `data-select-initialized`.
- El dropdown usa contención visual con altura máxima y scroll.
- No uses inicialización global sobre `document.querySelectorAll('select')` sin marca explícita.

## Ejemplos

Select simple:

```html
<select name="status">
  <option value="">Todos</option>
  <option value="active">Activos</option>
</select>
```

Select con buscador:

```html
<select name="employee_id" data-select-search="on">
  <option value="">Selecciona empleado</option>
</select>
```

Select protegido:

```html
<select name="per_page" data-select-search="off">
  <option value="25">25</option>
  <option value="50">50</option>
</select>
```
