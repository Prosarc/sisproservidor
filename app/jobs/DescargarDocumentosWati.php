<?php

namespace App\Jobs;

use App\Services\WatiMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DescargarDocumentosWati implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries   = 1;

    public function __construct(
        private string $numberPhone,
        private int    $numberDocument,
        private int    $sheet,
        private string $carpeta,
        private int    $timestampLimite,
    ) {}

    public function handle(): void
    {
        $endpoint = config('services.wati.endpoint');
        $token    = config('services.wati.token');

        if (!file_exists($this->carpeta)) {
            mkdir($this->carpeta, 0755, true);
        }

        $guardados  = 0;

        if (VerificarPagoWati::esBsuid($this->numberPhone)) {
            $guardados = $this->descargarV3($token);
            Log::info('Wati descarga completada', ['phone' => $this->numberPhone, 'guardados' => $guardados, 'api' => 'v3']);
            return;
        }

        $pageNumber = $this->sheet + 1;
        $pageSize   = 20;
        $maxPaginas = 10;

        while ($guardados < $this->numberDocument && $maxPaginas-- > 0) {
            $msgResponse = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get("{$endpoint}/api/v1/getMessages/{$this->numberPhone}", [
                    'pageSize'   => $pageSize,
                    'pageNumber' => $pageNumber,
                ]);

            if (!$msgResponse->successful()) {
                Log::warning('Wati getMessages falló', [
                    'phone'      => $this->numberPhone,
                    'pageNumber' => $pageNumber,
                    'status'     => $msgResponse->status(),
                ]);
                break;
            }

            $items = $msgResponse->json()['messages']['items'] ?? [];
            if (empty($items)) {
                break;
            }

            foreach ($items as $idx => $message) {
                if ($guardados >= $this->numberDocument) {
                    break;
                }

                $msgTimestamp = (int) ($message['timestamp'] ?? 0);
                if ($msgTimestamp > 0 && $msgTimestamp < $this->timestampLimite) {
                    $maxPaginas = 0;
                    break;
                }

                $owner     = strtolower($message['owner'] ?? '');
                $eventType = strtolower($message['eventType'] ?? '');
                if ($owner === 'us' || $eventType === 'sent') {
                    continue;
                }

                $type        = $message['type'] ?? '';
                $dataPath    = $message['data'] ?? '';
                $textoNombre = $message['text'] ?? '';

                if (!in_array($type, ['document', 'image'], true) || !$dataPath) {
                    continue;
                }

                $nombreReferencia = strtolower($textoNombre ?: $dataPath);
                if (str_contains($nombreReferencia, '.crdownload')) {
                    continue;
                }

                $descarga = WatiMediaService::download($dataPath, $textoNombre, $type);
                if (!$descarga) {
                    Log::warning('Wati archivo descarga fallida', [
                        'dataPath'    => $dataPath,
                        'textoNombre' => $textoNombre,
                        'type'        => $type,
                    ]);
                    continue;
                }

                $ext = $descarga['extension'];
                if ($textoNombre && preg_match('/\.\w{2,4}$/i', $textoNombre)) {
                    $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($textoNombre, PATHINFO_FILENAME));
                    $nombreArchivo = ($base !== '' ? $base : time()) . '.' . $ext;
                } else {
                    $nombreArchivo = time() . '_' . $pageNumber . '_' . $idx . '.' . $ext;
                }

                file_put_contents($this->carpeta . '/' . $nombreArchivo, $descarga['content']);

                Log::info('Wati archivo guardado', [
                    'archivo' => $nombreArchivo,
                    'tamaño'  => strlen($descarga['content']),
                    'tipo'    => $ext,
                ]);
                $guardados++;
            }

            $pageNumber++;
        }

        Log::info('Wati descarga completada', [
            'phone'     => $this->numberPhone,
            'guardados' => $guardados,
        ]);
    }

    /** Contactos sin teléfono (BSUID): mensajes y archivos por la API v3. */
    private function descargarV3(string $token): int
    {
        $guardados  = 0;
        $pagina     = $this->sheet + 1;
        $maxPaginas = 10;
        $base       = WatiMediaService::baseV3();

        while ($guardados < $this->numberDocument && $maxPaginas-- > 0) {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get($base . '/api/ext/v3/conversations/' . rawurlencode(trim($this->numberPhone)) . '/messages', [
                    'page_number' => $pagina,
                    'page_size'   => 20,
                ]);

            if (!$response->successful()) {
                Log::warning('Wati v3 messages falló', [
                    'target' => $this->numberPhone,
                    'pagina' => $pagina,
                    'status' => $response->status(),
                ]);
                break;
            }

            $mensajes = $response->json('message_list') ?? [];
            if (empty($mensajes)) {
                break;
            }

            foreach ($mensajes as $idx => $message) {
                if ($guardados >= $this->numberDocument) {
                    break;
                }

                if (($message['event_type'] ?? 'message') !== 'message') {
                    continue;
                }

                $msgTimestamp = (int) ($message['timestamp'] ?? 0);
                if ($msgTimestamp > 0 && $msgTimestamp < $this->timestampLimite) {
                    return $guardados;
                }

                if (($message['owner'] ?? false) === true) {
                    continue;
                }

                $type        = strtolower((string) ($message['type'] ?? ''));
                $id          = (string) ($message['id'] ?? '');
                $textoNombre = (string) ($message['text'] ?? '');

                if (!in_array($type, ['document', 'image'], true) || $id === '') {
                    continue;
                }
                if (str_contains(strtolower($textoNombre), '.crdownload')) {
                    continue;
                }

                $descarga = WatiMediaService::downloadV3($id, $textoNombre, $type);
                if (!$descarga) {
                    continue;
                }

                $ext = $descarga['extension'];
                if ($textoNombre && preg_match('/\.\w{2,4}$/i', $textoNombre)) {
                    $nombreBase    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($textoNombre, PATHINFO_FILENAME));
                    $nombreArchivo = ($nombreBase !== '' ? $nombreBase : time()) . '.' . $ext;
                } else {
                    $nombreArchivo = time() . '_' . $pagina . '_' . $idx . '.' . $ext;
                }

                file_put_contents($this->carpeta . '/' . $nombreArchivo, $descarga['content']);

                Log::info('Wati archivo guardado', [
                    'archivo' => $nombreArchivo,
                    'tamaño'  => strlen($descarga['content']),
                    'tipo'    => $ext,
                ]);
                $guardados++;
            }

            $pagina++;
        }

        return $guardados;
    }
}
