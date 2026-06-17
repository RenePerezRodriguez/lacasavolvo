<?php

namespace App\Helpers;

class NumerosEnLetras
{
    private static array $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];

    private static array $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private static array $centenas = ['', 'CIEN', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function convertir(float $numero, string $moneda = 'BOLIVIANOS'): string
    {
        $entero = (int) $numero;
        $centavos = (int) round(($numero - $entero) * 100);

        $letras = $entero === 0 ? 'CERO' : self::enteroALetras($entero);

        return trim($letras) . ' ' . $moneda . ' CON ' . str_pad((string) $centavos, 2, '0', STR_PAD_LEFT) . '/100';
    }

    private static function enteroALetras(int $n): string
    {
        if ($n === 0) return '';
        if ($n < 20) return self::$unidades[$n];
        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;
            if ($d === 2 && $u > 0) return 'VEINTI' . self::$unidades[$u];
            return self::$decenas[$d] . ($u > 0 ? ' Y ' . self::$unidades[$u] : '');
        }
        if ($n < 1000) {
            $c = intdiv($n, 100);
            $resto = $n % 100;
            $base = $c === 1 && $resto > 0 ? 'CIENTO' : self::$centenas[$c];
            return $base . ($resto > 0 ? ' ' . self::enteroALetras($resto) : '');
        }
        if ($n < 1_000_000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $prefijo = $miles === 1 ? 'MIL' : self::enteroALetras($miles) . ' MIL';
            return $prefijo . ($resto > 0 ? ' ' . self::enteroALetras($resto) : '');
        }
        $millones = intdiv($n, 1_000_000);
        $resto = $n % 1_000_000;
        $prefijo = $millones === 1 ? 'UN MILLÓN' : self::enteroALetras($millones) . ' MILLONES';
        return $prefijo . ($resto > 0 ? ' ' . self::enteroALetras($resto) : '');
    }
}
