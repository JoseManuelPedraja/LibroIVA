<?php
/**
 * Plugin Comerciales para FacturaScripts
 * Libro de IVA trimestral para autónomos comerciales en España.
 *
 * Lee directamente las tablas nativas de FacturaScripts:
 *   - facturascli   → facturas emitidas (IVA repercutido)
 *   - facturasprov  → facturas de compras/gastos (IVA soportado)
 *
 * Calcula: IVA a pagar = IVA repercutido - IVA soportado deducible
 */

namespace FacturaScripts\Plugins\LibroIVA\Controller;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;

class ResumenTrimestral extends PanelController
{
    /** @var array Totales y cálculos del trimestre */
    public $datos = [];

    /** @var array Facturas emitidas del trimestre */
    public $facturas = [];

    /** @var array Facturas de compras/gastos del trimestre */
    public $compras = [];

    /** @var int Año seleccionado */
    public $anyo;

    /** @var int Trimestre (1-4) */
    public $trimestre;

    /** @var array Años disponibles en el selector */
    public $anyosDisponibles = [];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'accounting';
        $data['title'] = 'Libro de IVA';
        $data['icon'] = 'fas fa-book';
        return $data;
    }

    protected function createViews(): void
    {
        $this->addHtmlView(
            'ResumenTrimestral',
            'ResumenTrimestral',
            'FacturaCliente',
            'Libro de IVA',
            'fas fa-book'
        );
    }

    protected function loadData($viewName, $view): void
    {
        if ($viewName !== 'ResumenTrimestral') {
            return;
        }

        // Años disponibles
        $anyoActual = (int)date('Y');
        for ($y = $anyoActual; $y >= $anyoActual - 3; $y--) {
            $this->anyosDisponibles[] = $y;
        }

        // Parámetros
        $this->anyo      = intval($this->request->get('anyo', $anyoActual));
        $this->trimestre = intval($this->request->get('trimestre', (int)ceil(date('n') / 3)));
        if ($this->trimestre < 1 || $this->trimestre > 4) {
            $this->trimestre = 1;
        }

        // Rango de fechas
        $mesinicio   = ($this->trimestre - 1) * 3 + 1;
        $mesfin      = $this->trimestre * 3;
        $fechaInicio = sprintf('%04d-%02d-01', $this->anyo, $mesinicio);
        $fechaFin    = date('Y-m-t', mktime(0, 0, 0, $mesfin, 1, $this->anyo));

        $db = new DataBase();

        // ── VENTAS: facturas emitidas a clientes/fabricantes ──────────
        $totVentas = $db->select("
            SELECT COALESCE(SUM(neto),0)      AS neto,
                   COALESCE(SUM(totaliva),0)  AS iva,
                   COALESCE(SUM(totalirpf),0) AS irpf,
                   COALESCE(SUM(total),0)     AS total,
                   COUNT(*)                  AS num
            FROM facturascli
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
        ")[0] ?? [];

        $this->facturas = $db->select("
            SELECT codigo, fecha, nombrecliente, cifnif,
                   neto, totaliva, totalirpf, total
            FROM facturascli
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
            ORDER BY fecha ASC, codigo ASC
        ");

        // ── COMPRAS: facturas de proveedor (gasolina, teléfono...) ────
        $totCompras = $db->select("
            SELECT COALESCE(SUM(neto),0)     AS neto,
                   COALESCE(SUM(totaliva),0) AS iva,
                   COALESCE(SUM(total),0)    AS total,
                   COUNT(*)                 AS num
            FROM facturasprov
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
        ")[0] ?? [];

        $this->compras = $db->select("
            SELECT codigo, fecha, nombre AS nombreprov, cifnif,
                   neto, totaliva, total
            FROM facturasprov
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
            ORDER BY fecha ASC, codigo ASC
        ");

        // ── CÁLCULOS ──────────────────────────────────────────────────
        $ivaRep   = (float)($totVentas['iva']  ?? 0);
        $ivaSop   = (float)($totCompras['iva'] ?? 0);
        $ivaPagar = round($ivaRep - $ivaSop, 2);

        $netoVentas  = (float)($totVentas['neto']  ?? 0);
        $netoCompras = (float)($totCompras['neto'] ?? 0);
        $beneficio   = round($netoVentas - $netoCompras, 2);

        $this->datos = [
            'fecha_inicio'    => $fechaInicio,
            'fecha_fin'       => $fechaFin,

            // Ventas
            'neto_ventas'     => $netoVentas,
            'iva_repercutido' => $ivaRep,
            'irpf_retenido'   => (float)($totVentas['irpf']  ?? 0),
            'total_ventas'    => (float)($totVentas['total'] ?? 0),
            'num_facturas'    => (int)($totVentas['num']     ?? 0),

            // Compras
            'neto_compras'    => $netoCompras,
            'iva_soportado'   => $ivaSop,
            'total_compras'   => (float)($totCompras['total'] ?? 0),
            'num_compras'     => (int)($totCompras['num']     ?? 0),

            // Resultado
            'iva_a_pagar'     => $ivaPagar,
            'beneficio_neto'  => $beneficio,
        ];
    }
}
