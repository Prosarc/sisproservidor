<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DescargarDocumentosWati;
use App\Jobs\VerificarPagoWati;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\CreateSolicitudExpressService;

class VerificarPagoController extends Controller
{
    public function verificar(Request $request)
    {
        $bsuid       = trim((string) $request->input('bsuid'));
        $telefono    = trim((string) $request->input('numberPhone'));
        // WATI deja la variable literal ({{BSUID}}) cuando el contacto no la tiene
        $esVacio     = fn (string $v) => $v === '' || (bool) preg_match('/^\{\{.*\}\}$|^@/', $v);
        $numberPhone = !$esVacio($bsuid) ? $bsuid : (!$esVacio($telefono) ? $telefono : null);
        $monto       = $request->input('monto');
        // Margen de días aceptado para la fecha del comprobante (0 = solo hoy)
        $diasMargen  = (int) $request->input('diasMargen', 30);

        Log::info('VerificarPago iniciado', ['phone' => $numberPhone, 'monto' => $monto, 'diasMargen' => $diasMargen]);

        if (!$numberPhone) {
            Log::warning('VerificarPago: falta numberPhone y bsuid', ['body' => array_keys($request->all())]);
            return response()->json(['error' => 'numberPhone o bsuid es requerido'], 422);
        }

        // Nuevo ciclo de verificación: limpiar marcador y resultado anterior
        $phoneLimpio = VerificarPagoWati::claveIdentificador($numberPhone);
        $marcador    = storage_path('app/verificaciones/' . $phoneLimpio . '.saved');
        $resultado   = storage_path('app/verificaciones/' . $phoneLimpio . '.json');
        if (file_exists($marcador)) {
            @unlink($marcador);
        }
        if (file_exists($resultado)) {
            @unlink($resultado);
        }

        $job = new VerificarPagoWati($numberPhone, $monto, $diasMargen);
        $job->handle();

        return response()->json(['ok' => true], 200);
    }

    public function resultado(Request $request, string $phone)
    {
        // Disparar internamente el guardado del/los comprobante(s) de pago en el proyecto,
        // se acepte o se rechace el pago. Usa el mismo flujo que el endpoint de documentos.
        $this->guardarComprobantes($request, $phone);

        $archivo = storage_path('app/verificaciones/' . VerificarPagoWati::claveIdentificador($phone) . '.json');

        if (!file_exists($archivo)) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Estamos verificando tu pago, espera unos segundos...',
            ]);
        }

        $resultado = json_decode(file_get_contents($archivo), true);

        $respuesta = VerificarPagoWati::enriquecerResultado(
            $resultado ?? [],
            $request->input('monto')
        );

        if ($respuesta['ok'] && $request->filled('idCliente')) {
            try {
                $datos = DB::table('solicitudes_express')
                    ->where('idCliente', $request->input('idCliente'))
                    ->orderByDesc('id')
                    ->first();
                $idSolicitud = $datos->id;
                $idCliente = (int) $request->input('idCliente');
                log::info('Este es el ID del cliente recibido: ' . $idCliente);
                $service = new CreateSolicitudExpressService();
                $solicitudServicio = $service->createSolicitud($idCliente, $idSolicitud);
                } catch (\Exception $e) {
                    Log::error('Error al crear SolicitudServicio: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Error interno al procesar la solicitud: ' . $e->getMessage(),
                    ], 500);
                }
            $fechaProgramada = $this->obtenerFechaProgramada((int) $request->input('idCliente'));
            $respuesta['fecha_programada'] = $fechaProgramada;

            if ($fechaProgramada) {
                $respuesta['mensaje'] .= ' Tu solicitud fue programada para el ' . $fechaProgramada . '.';
            }
        }

        return response()->json($respuesta);
    }

    /**
     * Descarga y guarda el/los comprobante(s) de pago que el contacto envió por Wati.
     */
    private function guardarComprobantes(Request $request, string $phone): void
    {
        try {
            // Idempotencia: solo guardar una vez por ciclo de verificación (resultado-pago es polleado)
            $phoneLimpio = VerificarPagoWati::claveIdentificador($phone);
            $marcador    = storage_path('app/verificaciones/' . $phoneLimpio . '.saved');
            if (file_exists($marcador)) {
                return;
            }

            // El comprobante se archiva en la carpeta del cliente: documentos/{idCliente}/año/mes.
            // Wati DEBE enviar idCliente en la llamada a resultado-pago (?idCliente=17).
            $idCliente = $request->input('idCliente');
            if (!$idCliente) {
                Log::warning('resultado-pago: no se guarda comprobante, falta idCliente', ['phone' => $phone]);
                return;
            }

            $numberDocument = (int) $request->input('numberDocument', 1);
            $sheet          = (int) $request->input('sheet', 0);
            $minutosAtras   = (int) $request->input('minutosAtras', 10);

            $ahora   = now();
            $mes     = str_pad($ahora->month, 2, '0', STR_PAD_LEFT);
            $carpeta = public_path('documentos/' . $idCliente . '/' . $ahora->year . '/' . $mes);

            if (!file_exists($carpeta)) {
                mkdir($carpeta, 0755, true);
            }

            $timestampLimite = time() - ($minutosAtras * 60);

            $job = new DescargarDocumentosWati(
                $phone,
                $numberDocument,
                $sheet,
                $carpeta,
                $timestampLimite
            );
            $job->handle();

            // Marcar como guardado para no re-descargar en los siguientes polls
            if (!is_dir(dirname($marcador))) {
                mkdir(dirname($marcador), 0755, true);
            }
            file_put_contents($marcador, (string) time());

            Log::info('resultado-pago: comprobante(s) guardados', [
                'phone'          => $phone,
                'idCliente'      => $idCliente,
                'numberDocument' => $numberDocument,
            ]);

        } catch (\Exception $e) {
            Log::error('resultado-pago: error al lanzar guardado de comprobante(s)', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Obtiene la programación vigente más reciente creada para un cliente express.
     */
    private function obtenerFechaProgramada(int $idCliente): ?string
    {
        return DB::table('progvehiculos as programacion')
            ->join('solicitud_servicios as servicio', 'servicio.ID_SolSer', '=', 'programacion.FK_ProgServi')
            ->where('servicio.FK_Cliente_Express', $idCliente)
            ->where('servicio.SolSerDelete', 0)
            ->where('programacion.ProgVehDelete', 0)
            ->orderByDesc('programacion.ID_ProgVeh')
            ->value('programacion.ProgVehFecha');
    }
}
