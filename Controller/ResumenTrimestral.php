<?php
/**
 * Plugin LibroIVA para FacturaScripts
 * Libro de IVA trimestral para autónomos comerciales en España.
 *
 * Las tablas de facturas (facturascli/facturasprov) solo se usan para el
 * detalle/listado. El IVA repercutido, el IVA soportado y los gastos
 * deducibles del Modelo 303/130 se calculan a partir de la contabilidad
 * (cuentas 477, 472 y grupo 6 excepto 678), porque el importe real puede
 * haberse ajustado en el asiento (p.ej. prorrata de IVA) y no coincidir
 * con el de la factura de origen.
 *
 * Calcula: IVA a pagar = IVA repercutido (cta. 477) - IVA soportado deducible (cta. 472)
 */

namespace FacturaScripts\Plugins\LibroIVA\Controller;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Plugins\LibroIVA\Lib\LibroIVA\CuentaTotales;

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

        // ── CÁLCULOS (según CONTABILIDAD, no según las facturas) ───────
        // FacturaScripts contabiliza automáticamente cada factura al guardarla,
        // así que esto es true en la inmensa mayoría de instalaciones. Si no hay
        // ningún asiento en el periodo (ejercicio sin plan contable, cerrado...),
        // usamos las facturas como antes, en vez de mostrar ceros falsos.
        $contabilidadDisponible = CuentaTotales::hayAsientosEnPeriodo($db, $fechaInicio, $fechaFin);

        if ($contabilidadDisponible) {
            // 477 = IVA repercutido (Hacienda Pública, acreedora) → saldo acreedor
            $ivaRep = CuentaTotales::saldoAcreedor($db, '477', $fechaInicio, $fechaFin);
            // 472 = IVA soportado (Hacienda Pública, deudora) → saldo deudor
            $ivaSop = CuentaTotales::saldoDeudor($db, '472', $fechaInicio, $fechaFin);
            // Grupo 6 = gastos, excepto la 678 (gastos excepcionales, no deducibles)
            $gastosDeducibles = CuentaTotales::saldoDeudor($db, '6', $fechaInicio, $fechaFin, ['678']);
        } else {
            $ivaRep = (float)($totVentas['iva'] ?? 0);
            $ivaSop = (float)($totCompras['iva'] ?? 0);
            $gastosDeducibles = (float)($totCompras['neto'] ?? 0);
        }
        $ivaPagar = round($ivaRep - $ivaSop, 2);

        $netoVentas = (float)($totVentas['neto'] ?? 0);
        $beneficio  = round($netoVentas - $gastosDeducibles, 2);

        $this->datos = [
            'fecha_inicio'    => $fechaInicio,
            'fecha_fin'       => $fechaFin,

            // Ventas
            'neto_ventas'     => $netoVentas,
            'irpf_retenido'   => (float)($totVentas['irpf']  ?? 0),
            'total_ventas'    => (float)($totVentas['total'] ?? 0),
            'num_facturas'    => (int)($totVentas['num']     ?? 0),
            // Total de la tabla de facturas emitidas (solo para cuadrar ese listado)
            'iva_repercutido_facturas' => (float)($totVentas['iva'] ?? 0),

            // Compras (detalle/listado de facturas de proveedor)
            'neto_compras_facturas' => (float)($totCompras['neto'] ?? 0),
            'iva_soportado_facturas' => (float)($totCompras['iva'] ?? 0),
            'total_compras'   => (float)($totCompras['total'] ?? 0),
            'num_compras'     => (int)($totCompras['num']     ?? 0),

            // Modelo 303 / Modelo 130 (según contabilidad: cuentas 477, 472 y grupo 6)
            'iva_repercutido' => $ivaRep,
            'iva_soportado'   => $ivaSop,
            'gastos_deducibles' => $gastosDeducibles,
            'iva_a_pagar'     => $ivaPagar,
            'beneficio_neto'  => $beneficio,
            'contabilidad_disponible' => $contabilidadDisponible,
        ];
    }
}
