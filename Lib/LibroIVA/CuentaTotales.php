<?php
/**
 * Plugin LibroIVA para FacturaScripts
 *
 * Suma el debe/haber contable de un grupo de subcuentas (por prefijo de código)
 * dentro de un rango de fechas, leyendo directamente los asientos, en vez de
 * las facturas. Necesario porque el importe real de IVA repercutido/soportado
 * o de un gasto puede haberse ajustado en el asiento (p.ej. prorrata de IVA) y
 * no coincidir con lo que indica la factura de origen.
 */

namespace FacturaScripts\Plugins\LibroIVA\Lib\LibroIVA;

use FacturaScripts\Core\Base\DataBase;

class CuentaTotales
{
    /**
     * Excluye del cálculo los asientos de cierre y regularización de PyG,
     * que traspasan todo el saldo de las cuentas de gastos/ingresos a la 129
     * y falsearían el total si el cierre cae dentro del rango de fechas.
     */
    const OPERACIONES_EXCLUIDAS = ['C', 'R'];

    /**
     * @param DataBase $db
     * @param string $prefijo Código de cuenta o subcuenta, p.ej. '477', '472', '6'
     * @param string $fechaInicio
     * @param string $fechaFin
     * @param string[] $excluirPrefijos Subcuentas a excluir aunque empiecen por $prefijo, p.ej. ['678']
     *
     * @return array{debe: float, haber: float}
     */
    public static function debeHaber(DataBase $db, string $prefijo, string $fechaInicio, string $fechaFin, array $excluirPrefijos = []): array
    {
        $exclusion = '';
        foreach ($excluirPrefijos as $excluir) {
            $exclusion .= ' AND p.codsubcuenta NOT LIKE ' . $db->var2str($excluir . '%');
        }

        $operaciones = implode(',', array_map([$db, 'var2str'], self::OPERACIONES_EXCLUIDAS));

        $sql = "SELECT COALESCE(SUM(p.debe),0) AS debe, COALESCE(SUM(p.haber),0) AS haber
            FROM partidas p
            JOIN asientos a ON a.idasiento = p.idasiento
            WHERE a.fecha >= " . $db->var2str($fechaInicio) . "
              AND a.fecha <= " . $db->var2str($fechaFin) . "
              AND p.codsubcuenta LIKE " . $db->var2str($prefijo . '%') . "
              AND (a.operacion IS NULL OR a.operacion NOT IN (" . $operaciones . "))
              {$exclusion}";

        $row = $db->select($sql)[0] ?? [];
        return [
            'debe' => (float)($row['debe'] ?? 0),
            'haber' => (float)($row['haber'] ?? 0),
        ];
    }

    /**
     * Saldo acreedor (haber - debe): para cuentas de pasivo/ingreso, como el 477 (IVA repercutido).
     */
    public static function saldoAcreedor(DataBase $db, string $prefijo, string $fechaInicio, string $fechaFin, array $excluirPrefijos = []): float
    {
        $importes = self::debeHaber($db, $prefijo, $fechaInicio, $fechaFin, $excluirPrefijos);
        return round($importes['haber'] - $importes['debe'], 2);
    }

    /**
     * Saldo deudor (debe - haber): para cuentas de activo/gasto, como el 472 (IVA soportado) o el grupo 6 (gastos).
     */
    public static function saldoDeudor(DataBase $db, string $prefijo, string $fechaInicio, string $fechaFin, array $excluirPrefijos = []): float
    {
        $importes = self::debeHaber($db, $prefijo, $fechaInicio, $fechaFin, $excluirPrefijos);
        return round($importes['debe'] - $importes['haber'], 2);
    }

    /**
     * ¿Hay algún asiento contable en este periodo?
     *
     * FacturaScripts contabiliza automáticamente cada factura de cliente/proveedor
     * en cuanto se guarda (ver InvoiceTrait::onChangeTotal), así que en el 99% de
     * las instalaciones esto es true aunque el usuario nunca haya tocado el menú
     * de Contabilidad. Solo da false si el ejercicio está cerrado, no tiene plan
     * contable instalado, o directamente no hay asientos en ese rango de fechas.
     * Sirve para no mostrar ceros falsos cuando la contabilidad no está disponible.
     */
    public static function hayAsientosEnPeriodo(DataBase $db, string $fechaInicio, string $fechaFin): bool
    {
        $sql = 'SELECT 1 FROM asientos WHERE fecha >= ' . $db->var2str($fechaInicio)
            . ' AND fecha <= ' . $db->var2str($fechaFin) . ' LIMIT 1';

        return !empty($db->select($sql));
    }
}
