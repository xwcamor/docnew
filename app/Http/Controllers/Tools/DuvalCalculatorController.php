<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Diagnostics\DuvalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calculador Duval standalone (Tools, solo-super). Live calculation: el front
 * manda aceite + gases (ppm) y devolvemos el diagnóstico + la geometría de las
 * zonas, reusando el MISMO motor (DuvalService) que el módulo de trafos. Cero
 * lógica duplicada.
 */
class DuvalCalculatorController extends Controller
{
    /**
     * Casos con mapeo Duval definido. Aceites del tanque (Triángulo 1 mineral o
     * Triángulo 3 por aceite) + el conmutador LTC (Triángulo 2). Todo canónico
     * contra el Excel oficial de Duval.
     */
    private const OILS = [
        ['code' => 'mineral',         'name' => 'Mineral (T1 + T4/T5 + pentágonos)'],
        ['code' => 'silicona',        'name' => 'Silicona (T3)'],
        ['code' => 'vegetal_soya',    'name' => 'Éster Natural · Soya / FR3 (T3)'],
        ['code' => 'vegetal_girasol', 'name' => 'Éster Natural · Girasol / BioTemp (T3)'],
        ['code' => 'ester_sintetico', 'name' => 'Éster Sintético · Midel 7131 (T3)'],
        ['code' => 'ltc',             'name' => 'Conmutador LTC · mineral (T2)'],
    ];

    private const CODES = 'mineral,silicona,vegetal_soya,vegetal_girasol,ester_sintetico,ltc';

    public function index()
    {
        return inertia('Tools/Duval', [
            'oils' => self::OILS,
        ]);
    }

    public function calculate(Request $request, DuvalService $duval): JsonResponse
    {
        $data = $request->validate([
            'oil'  => ['required', 'string', 'in:'.self::CODES],
            'h2'   => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'ch4'  => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'c2h4' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'c2h6' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'c2h2' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $gas = [
            'h2'   => (float) ($data['h2'] ?? 0),
            'ch4'  => (float) ($data['ch4'] ?? 0),
            'c2h4' => (float) ($data['c2h4'] ?? 0),
            'c2h6' => (float) ($data['c2h6'] ?? 0),
            'c2h2' => (float) ($data['c2h2'] ?? 0),
        ];

        return response()->json([
            'duval'    => $duval->evaluateGases($gas, $data['oil']),
            'geometry' => $duval->geometry($data['oil']),
        ]);
    }
}
