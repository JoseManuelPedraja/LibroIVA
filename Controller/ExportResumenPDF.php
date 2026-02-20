<?php
/**
 * Plugin LibroIVA para FacturaScripts
 * Exporta el Libro de IVA trimestral en PDF:
 *   Página 1 → Facturas emitidas (detalle)
 *   Página 2 → Facturas recibidas / compras (detalle)
 *   Página 3 → Resumen global (Mod.303 + Mod.130) para el gestor
 */

namespace FacturaScripts\Plugins\LibroIVA\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\Export\PDFExport;

class ExportResumenPDF extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'accounting';
        $data['title'] = 'Exportar Libro IVA PDF';
        $data['icon'] = 'fas fa-file-pdf';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        // ── Parámetros ────────────────────────────────────────────────
        $anyo      = intval($this->request->get('anyo', date('Y')));
        $trimestre = intval($this->request->get('trimestre', (int)ceil(date('n') / 3)));
        if ($trimestre < 1 || $trimestre > 4) {
            $trimestre = 1;
        }

        $mesinicio   = ($trimestre - 1) * 3 + 1;
        $mesfin      = $trimestre * 3;
        $fechaInicio = sprintf('%04d-%02d-01', $anyo, $mesinicio);
        $fechaFin    = date('Y-m-t', mktime(0, 0, 0, $mesfin, 1, $anyo));
        $periodo     = "T{$trimestre} {$anyo}  ({$fechaInicio} — {$fechaFin})";

        $db = new DataBase();

        // ── Facturas emitidas ─────────────────────────────────────────
        $facturas = $db->select("
            SELECT codigo, fecha, nombrecliente, cifnif,
                   neto, totaliva, totalirpf, total
            FROM facturascli
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
            ORDER BY fecha ASC, codigo ASC
        ");

        $totV = $db->select("
            SELECT COALESCE(SUM(neto),0)      AS neto,
                   COALESCE(SUM(totaliva),0)  AS iva,
                   COALESCE(SUM(totalirpf),0) AS irpf,
                   COALESCE(SUM(total),0)     AS total,
                   COUNT(*)                   AS num
            FROM facturascli
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
        ")[0] ?? [];

        // ── Facturas recibidas / compras ──────────────────────────────
        $compras = $db->select("
            SELECT codigo, fecha, nombre AS nombreprov, cifnif,
                   neto, totaliva, total
            FROM facturasprov
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
            ORDER BY fecha ASC, codigo ASC
        ");

        $totC = $db->select("
            SELECT COALESCE(SUM(neto),0)     AS neto,
                   COALESCE(SUM(totaliva),0) AS iva,
                   COALESCE(SUM(total),0)    AS total,
                   COUNT(*)                  AS num
            FROM facturasprov
            WHERE fecha >= '{$fechaInicio}' AND fecha <= '{$fechaFin}'
        ")[0] ?? [];

        // ── Cálculos globales ─────────────────────────────────────────
        $ivaPagar  = round((float)($totV['iva']  ?? 0) - (float)($totC['iva']  ?? 0), 2);
        $beneficio = round((float)($totV['neto'] ?? 0) - (float)($totC['neto'] ?? 0), 2);

        // ── Formato y empresa ─────────────────────────────────────────
        $fmt = $db->select("SELECT id FROM formatos ORDER BY id ASC LIMIT 1");
        $idformato = (int)($fmt[0]['id'] ?? 1);

        $emp = $db->select("SELECT idempresa FROM empresas ORDER BY idempresa ASC LIMIT 1");
        $idempresa = (int)($emp[0]['idempresa'] ?? 1);

        // ── Iniciar PDF ───────────────────────────────────────────────
        $pdf = new PDFExport();
        $pdf->newDoc("Libro IVA — {$periodo}", $idformato, 'es_ES');
        $pdf->setCompany($idempresa);

        // ════════════════════════════════════════════════════════════
        // PÁGINA 1 — Facturas emitidas
        // ════════════════════════════════════════════════════════════
        $hF    = ['Nº Factura', 'Fecha', 'Cliente / Fabricante', 'NIF', 'Base', 'IVA 21%', 'IRPF -15%', 'Total'];
        $rowsF = [];
        foreach ($facturas as $f) {
            $rowsF[] = [
                $f['codigo']        ?? '',
                $f['fecha']         ?? '',
                $f['nombrecliente'] ?? '',
                $f['cifnif']        ?? '',
                number_format((float)($f['neto']      ?? 0), 2, ',', '.') . ' €',
                number_format((float)($f['totaliva']  ?? 0), 2, ',', '.') . ' €',
                '-' . number_format((float)($f['totalirpf'] ?? 0), 2, ',', '.') . ' €',
                number_format((float)($f['total']     ?? 0), 2, ',', '.') . ' €',
            ];
        }
        // Fila de totales
        $rowsF[] = [
            'TOTAL', '', (int)($totV['num'] ?? 0) . ' facturas', '',
            number_format((float)($totV['neto']  ?? 0), 2, ',', '.') . ' €',
            number_format((float)($totV['iva']   ?? 0), 2, ',', '.') . ' €',
            '-' . number_format((float)($totV['irpf'] ?? 0), 2, ',', '.') . ' €',
            number_format((float)($totV['total'] ?? 0), 2, ',', '.') . ' €',
        ];
        $pdf->addTablePage($hF, $rowsF, [], "Facturas Emitidas — {$periodo}");

        // ════════════════════════════════════════════════════════════
        // PÁGINA 2 — Facturas recibidas (compras y gastos)
        // ════════════════════════════════════════════════════════════
        $hC    = ['Nº', 'Fecha', 'Proveedor', 'NIF', 'Base', 'IVA', 'Total'];
        $rowsC = [];
        foreach ($compras as $c) {
            $rowsC[] = [
                $c['codigo']     ?? '',
                $c['fecha']      ?? '',
                $c['nombreprov'] ?? '',
                $c['cifnif']     ?? '',
                number_format((float)($c['neto']     ?? 0), 2, ',', '.') . ' €',
                number_format((float)($c['totaliva'] ?? 0), 2, ',', '.') . ' €',
                number_format((float)($c['total']    ?? 0), 2, ',', '.') . ' €',
            ];
        }
        // Fila de totales
        $rowsC[] = [
            'TOTAL', '', (int)($totC['num'] ?? 0) . ' compras', '',
            number_format((float)($totC['neto']  ?? 0), 2, ',', '.') . ' €',
            number_format((float)($totC['iva']   ?? 0), 2, ',', '.') . ' €',
            number_format((float)($totC['total'] ?? 0), 2, ',', '.') . ' €',
        ];
        $pdf->addTablePage($hC, $rowsC, [], "Facturas Recibidas — {$periodo}");

        // ════════════════════════════════════════════════════════════
        // PÁGINA 3 — Resumen global para el gestor
        // ════════════════════════════════════════════════════════════
        $hR    = ['Concepto', 'Importe'];
        $rowsR = [
            // IVA — Modelo 303
            ['IVA — MODELO 303', ''],
            ['Facturas emitidas (' . (int)($totV['num'] ?? 0) . ')',               ''],
            ['  Base imponible (ingresos sin IVA)',
                number_format((float)($totV['neto'] ?? 0), 2, ',', '.') . ' €'],
            ['  IVA repercutido  →  casilla 01',
                number_format((float)($totV['iva']  ?? 0), 2, ',', '.') . ' €'],
            ['  IRPF retenido por clientes (ya ingresado)',
                number_format((float)($totV['irpf'] ?? 0), 2, ',', '.') . ' €'],
            ['', ''],
            ['Facturas recibidas / gastos (' . (int)($totC['num'] ?? 0) . ')',     ''],
            ['  Base imponible (gastos sin IVA)',
                number_format((float)($totC['neto'] ?? 0), 2, ',', '.') . ' €'],
            ['  IVA soportado deducible  →  casilla 28',
                number_format((float)($totC['iva']  ?? 0), 2, ',', '.') . ' €'],
            ['', ''],
            ['IVA A INGRESAR A HACIENDA  (Mod. 303)',
                number_format($ivaPagar, 2, ',', '.') . ' €'],

            // Separador
            ['', ''],

            // IRPF — Modelo 130
            ['IRPF — MODELO 130 (estimación directa)', ''],
            ['Ingresos netos (base facturas emitidas)',
                number_format((float)($totV['neto'] ?? 0), 2, ',', '.') . ' €'],
            ['Gastos deducibles (base facturas recibidas)',
                number_format((float)($totC['neto'] ?? 0), 2, ',', '.') . ' €'],
            ['Rendimiento neto estimado',
                number_format($beneficio, 2, ',', '.') . ' €'],
            ['IRPF retenido por clientes (ya ingresado)',
                number_format((float)($totV['irpf'] ?? 0), 2, ',', '.') . ' €'],
        ];
        $pdf->addTablePage($hR, $rowsR, [], "Resumen para el gestor — {$periodo}");

        // ── Salida PDF ────────────────────────────────────────────────
        $this->setTemplate(false);
        $pdf->show($response);
    }
}
