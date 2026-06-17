<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Builder;

/**
 * SearchHelper — Búsqueda inteligente multi-campo y multi-tabla.
 *
 * Tokeniza el string de búsqueda en palabras individuales y construye
 * condiciones WHERE que buscan cada token en las columnas especificadas.
 * Soporta búsqueda cross-table (ej: "muñoz caja febi" encuentra productos
 * cuya descripción contiene "muñoz"/"caja" y cuya marca es "febi").
 */
class SearchHelper
{
    /**
     * Aplica búsqueda tokenizada a un query Builder.
     *
     * @param Builder $query       Query de Eloquent al que agregar las condiciones.
     * @param string  $search      Texto de búsqueda del usuario.
     * @param array   $directCols  Columnas de la tabla principal donde buscar (ej: ['codigo', 'descripcion']).
     * @param array   $relCols     Columnas en relaciones (ej: ['marca.nombre', 'industria.nombre']).
     *                              Formato: 'relacion.columna'. La relación debe estar cargada o ser joinable.
     * @return Builder
     */
    public static function apply(Builder $query, string $search, array $directCols = [], array $relCols = []): Builder
    {
        $search = ltrim(trim($search), '#');
        if ($search === '') {
            return $query;
        }

        // Si es un número entero, buscar también por ID exacto
        $numericSearch = is_numeric($search) ? (int) $search : null;

        // Tokenizar: dividir por espacios, filtrar tokens vacíos
        $tokens = array_filter(explode(' ', $search), fn($t) => strlen($t) > 0);

        // Si hay un solo token, hacer LIKE simple (más rápido)
        if (count($tokens) === 1) {
            return self::singleTokenSearch($query, $search, $directCols, $relCols, $numericSearch);
        }

        // Multi-token: cada token debe aparecer en AL MENOS una columna
        return self::multiTokenSearch($query, $tokens, $directCols, $relCols, $numericSearch);
    }

    /**
     * Búsqueda de un solo token: LIKE simple en todas las columnas.
     */
    private static function singleTokenSearch(Builder $query, string $search, array $directCols, array $relCols, ?int $numericSearch): Builder
    {
        $like = '%' . $search . '%';

        return $query->where(function (Builder $q) use ($like, $directCols, $relCols, $numericSearch) {
            // Columnas directas
            foreach ($directCols as $col) {
                $q->orWhere($col, 'like', $like);
            }

            // Columnas en relaciones (usamos whereHas para cada relación)
            $rels = self::groupRelCols($relCols);
            foreach ($rels as $rel => $cols) {
                $q->orWhereHas($rel, function (Builder $rq) use ($cols, $like) {
                    $rq->where(function (Builder $inner) use ($cols, $like) {
                        foreach ($cols as $col) {
                            $inner->orWhere($col, 'like', $like);
                        }
                    });
                });
            }

            // Búsqueda por ID exacto si es numérico
            if ($numericSearch !== null) {
                $q->orWhere('id', $numericSearch);
            }
        });
    }

    /**
     * Búsqueda multi-token: cada token debe coincidir en alguna columna.
     * Usa un enfoque AND: todos los tokens deben aparecer en al menos una columna.
     *
     * Todo el bloque (tokens AND + ID exacto OR) se agrupa en un solo where()
     * para no contaminar filtros previos del query (estado, fechas, sucursal).
     */
    private static function multiTokenSearch(Builder $query, array $tokens, array $directCols, array $relCols, ?int $numericSearch): Builder
    {
        return $query->where(function (Builder $outer) use ($tokens, $directCols, $relCols, $numericSearch) {
            // Grupo AND: cada token debe aparecer en al menos una columna
            $outer->where(function (Builder $andGroup) use ($tokens, $directCols, $relCols) {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';

                    $andGroup->where(function (Builder $q) use ($like, $directCols, $relCols) {
                        // Columnas directas
                        foreach ($directCols as $col) {
                            $q->orWhere($col, 'like', $like);
                        }

                        // Columnas en relaciones
                        $rels = self::groupRelCols($relCols);
                        foreach ($rels as $rel => $cols) {
                            $q->orWhereHas($rel, function (Builder $rq) use ($cols, $like) {
                                $rq->where(function (Builder $inner) use ($cols, $like) {
                                    foreach ($cols as $col) {
                                        $inner->orWhere($col, 'like', $like);
                                    }
                                });
                            });
                        }
                    });
                }
            });

            // Búsqueda por ID exacto (opcional) — dentro del grupo, sin romper filtros externos
            if ($numericSearch !== null) {
                $outer->orWhere('id', $numericSearch);
            }
        });
    }

    /**
     * Agrupa columnas de relación por nombre de relación.
     * Convierte ['marca.nombre', 'industria.nombre', 'marca.descripcion']
     * en ['marca' => ['nombre', 'descripcion'], 'industria' => ['nombre']].
     */
    private static function groupRelCols(array $relCols): array
    {
        $grouped = [];
        foreach ($relCols as $col) {
            $parts = explode('.', $col, 2);
            if (count($parts) === 2) {
                $grouped[$parts[0]][] = $parts[1];
            }
        }
        return $grouped;
    }
}
