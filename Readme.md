# LibroIVA

Plugin para FacturaScripts 2025 orientado a **autónomos y agentes comerciales en España**.

Genera el libro de IVA trimestral con todo lo necesario para presentar los modelos fiscales trimestrales.

## Funcionalidades

- Resumen trimestral de IVA en pantalla (Mod. 303)
- Cálculo de IRPF retenido y rendimiento neto (Mod. 130)
- Exportación a PDF del libro completo con tres páginas:
  - **Pág. 1** — Facturas emitidas (clientes): base, IVA 21%, IRPF -15%, total
  - **Pág. 2** — Facturas recibidas (proveedores/gastos): base, IVA, total
  - **Pág. 3** — Resumen global para el gestor (Mod. 303 + Mod. 130)
- El PDF usa el motor de **PlantillasPDF** (Dinamic): respeta el template seleccionado, colores, logo y cabecera de cada instalación
- Formato PDF propio **"Libro IVA"** configurable de forma independiente desde Admin > Plantillas PDF
- Selector de año y trimestre (T1–T4)

## Requisitos

- FacturaScripts 2025 o superior
- Plugin **PlantillasPDF** instalado y activo
- Módulo de facturación activo (tablas `facturascli` y `facturasprov`)

## Instalación

1. Copia la carpeta `LibroIVA` dentro de `Plugins/` de tu instalación de FacturaScripts.
2. Activa el plugin desde **Admin > Plugins**.
3. Al activar, se crea automáticamente un formato PDF llamado **"Libro IVA"** en Admin > Plantillas PDF > Formatos de impresión.
4. Accede desde el menú **Contabilidad > Resumen Trimestral**.

## Uso

1. Selecciona el **año** y el **trimestre**.
2. Revisa el resumen en pantalla.
3. Pulsa **Descargar PDF** para obtener el libro listo para tu gestor.

## Configuración del PDF

El PDF usa el template y los ajustes globales configurados en **Admin > Plantillas PDF > General** (template, posición del logo, colores, fuente, etc.).

Adicionalmente, el plugin crea un formato específico **"Libro IVA"** en la pestaña **Formatos de impresión**, donde puedes personalizar opciones propias del libro (texto final, orientación, serie, etc.) de forma independiente a las facturas.

**Prioridad de búsqueda del formato:**
1. Formato llamado `Libro IVA` (creado automáticamente)
2. Formato con `autoaplicar = 1` para la empresa
3. Primer formato disponible

