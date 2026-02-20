<?php
/**
 * Plugin Comerciales para FacturaScripts
 * Inicialización: registra las páginas en el menú al instalar/actualizar.
 */

namespace FacturaScripts\Plugins\LibroIVA;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Template\InitClass;

class Init extends InitClass
{
    /**
     * Se ejecuta cada vez que el plugin carga.
     */
    public function init(): void
    {
    }

    /**
     * Se ejecuta al desinstalar el plugin.
     */
    public function uninstall(): void
    {
    }

    /**
     * Se ejecuta al instalar o actualizar el plugin.
     * Crea el formato PDF "Libro IVA" en formatos_documentos si no existe,
     * para que sea configurable desde Admin > Plantillas PDF.
     */
    public function update(): void
    {
        $db = new DataBase();

        // Solo si PlantillasPDF está instalado (tabla existe)
        if (!$db->tableExists('formatos_documentos')) {
            return;
        }

        // Crear el formato si no existe todavía
        $exists = $db->select("SELECT id FROM formatos_documentos WHERE nombre = 'Libro IVA' LIMIT 1");
        if (!empty($exists)) {
            return;
        }

        $formatoClass = '\\FacturaScripts\\Dinamic\\Model\\FormatoDocumento';
        if (!class_exists($formatoClass)) {
            return;
        }

        $formato = new $formatoClass();
        $formato->nombre = 'Libro IVA';
        $formato->titulo = 'Libro IVA';
        $formato->tipodoc = 'LibroIVA';
        $formato->autoaplicar = false;
        $formato->save();
    }
}
