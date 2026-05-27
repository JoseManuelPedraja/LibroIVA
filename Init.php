<?php
/**
 * Plugin Comerciales para FacturaScripts
 * Inicialización: registra las páginas en el menú al instalar/actualizar.
 */

namespace FacturaScripts\Plugins\LibroIVA;

use FacturaScripts\Core\Template\InitClass;

class Init extends InitClass
{
    /**
     * Se ejecuta cada vez que el plugin carga.
     */
    public function init(): void
    {
        // De momento no necesitamos nada aquí
    }

    /**
     * Se ejecuta al desinstalar el plugin.
     */
    public function uninstall(): void
    {
        // De momento no necesitamos limpiar nada al desinstalar
    }

    /**
     * Se ejecuta al instalar o actualizar el plugin.
     * En FacturaScripts 2025 las páginas se registran automáticamente
     * desde el método getPageData() de cada controlador.
     */
    public function update(): void
    {
        // Las páginas se auto-registran desde los controladores
    }
}
