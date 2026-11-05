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
use Carbon\Carbon;

class VerificarPagoWati implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries   = 1;
    private ?string $ultimoErrorDescarga = null;

    private array $meses = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    public function __construct(
        private string  $numberPhone,
        private ?string $monto,
        private int     $diasMargen = 0,
    ) {}

    /** BSUID de WhatsApp, p. ej. CO.1787416719197505 (contactos sin teléfono visible). */
    public static function esBsuid(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z]{2}\.\d+$/', trim($id));
    }

    /** Clave segura para nombres de archivo/carpeta: dígitos para teléfonos, BSUID tal cual. */
    public static function claveIdentificador(string $id): string
    {
        $id = trim($id);

        return self::esBsuid($id) ? $id : preg_replace('/\D/', '', $id);
    }

    public function handle(): void
    {
        $endpoint = config('services.wati.endpoint');
        $token    = config('services.wati.token');

        // Descargar el último archivo enviado por el usuario
        [$archivoContenido, $nombreArchivo] = $this->descargarUltimoArchivo($endpoint, $token);

        if ($archivoContenido === null) {
            $this->guardarResultado(self::enriquecerResultado([
                'ok'    => false,
                'error' => $this->ultimoErrorDescarga ?: 'No se encontró ningún archivo reciente',
            ]));
            return;
        }

        // Guardar comprobante en disco
        $rutaComprobante = $this->guardarComprobante($archivoContenido, $nombreArchivo);

        $ocr = $this->extraerTextoComprobante($archivoContenido, $nombreArchivo);
        if (!$ocr['ok']) {
            $this->guardarResultado(self::enriquecerResultado([
                'ok'          => false,
                'error'       => $ocr['error'],
                'comprobante' => $rutaComprobante,
            ]));
            return;
        }

        $texto = $ocr['texto'];

        $valorExtraido = $this->extraerValor($texto);
        $fechaExtraida = $this->extraerFecha($texto);
        $nitExtraido   = $this->extraerNit($texto);

        $fechaValida   = true;
        $prosarcValido = stripos($texto, 'prosarc') !== false;
        $montoValido   = $this->monto !== null
            ? $this->validarMonto($this->monto, $valorExtraido)
            : null;

        $facturaValida = $prosarcValido && ($montoValido === null || $montoValido === true);

        $resultado = self::enriquecerResultado([
            'ok'             => $facturaValida,
            'factura_valida' => $facturaValida,
            'comprobante'    => $rutaComprobante,
            'datos'          => [
                'valor'          => $valorExtraido,
                'fecha'          => $fechaExtraida,
                'nit'            => $nitExtraido,
                'fecha_valida'   => $fechaValida,
                'prosarc_valido' => $prosarcValido,
                'monto_valido'   => $montoValido,
            ],
        ], $this->monto);

        $this->guardarResultado($resultado);

        Log::info('VerificarPagoWati completado', [
            'phone'          => $this->numberPhone,
            'factura_valida' => $facturaValida,
            'comprobante'    => $rutaComprobante,
            'ocr_metodo'     => $ocr['metodo'] ?? 'ocr',
            'monto_esperado' => $this->monto,
            'valor'          => $valorExtraido,
            'monto_valido'   => $montoValido,
            'prosarc_valido' => $prosarcValido,
            'ocr_texto'      => mb_substr(preg_replace('/\s+/u', ' ', $texto), 0, 300),
        ]);
    }

    /** Límite del plan gratuito de OCR.space (1 MB). */
    private const OCR_MAX_BYTES = 1048576;

    /**
     * Obtiene texto del comprobante: PDF digital (extracción local) u OCR.space.
     */
    private function extraerTextoComprobante(string $contenido, string $nombreArchivo): array
    {
        $esPdf = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION)) === 'pdf';

        if ($esPdf) {
            $textoLocal = $this->extraerTextoPdfLocal($contenido);
            if (mb_strlen(trim($textoLocal)) >= 30) {
                Log::info('PDF: texto extraído localmente', [
                    'archivo' => $nombreArchivo,
                    'chars'   => mb_strlen($textoLocal),
                ]);
                return ['ok' => true, 'texto' => $textoLocal, 'metodo' => 'pdf_texto'];
            }

            if (strlen($contenido) > self::OCR_MAX_BYTES) {
                $jpeg = $this->pdfPrimeraPaginaComoJpeg($contenido);
                if ($jpeg !== null && strlen($jpeg) <= self::OCR_MAX_BYTES) {
                    Log::info('PDF: convertido a JPEG para OCR', [
                        'archivo'   => $nombreArchivo,
                        'bytes_jpg' => strlen($jpeg),
                    ]);
                    return $this->enviarOcrSpace($jpeg, 'comprobante.jpg');
                }

                $mb = round(strlen($contenido) / 1048576, 1);
                return [
                    'ok'    => false,
                    'error' => "El PDF pesa {$mb} MB (máximo 1 MB para OCR). Envía una captura JPG o PNG del comprobante.",
                ];
            }

            return $this->enviarOcrSpace($contenido, $nombreArchivo, true);
        }

        return $this->enviarOcrSpace($contenido, $nombreArchivo, false);
    }

    /** Extrae texto de PDFs generados digitalmente (sin OCR). */
    private function extraerTextoPdfLocal(string $contenido): string
    {
        $partes = [];

        if (preg_match_all('/\((?:\\\\.|[^\\\\\)])*\)/s', $contenido, $matches)) {
            foreach ($matches[0] as $literal) {
                $t = stripcslashes(trim($literal, '()'));
                if ($t !== '' && mb_check_encoding($t, 'UTF-8')) {
                    $partes[] = $t;
                }
            }
        }

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $contenido, $streams)) {
            foreach ($streams[1] as $raw) {
                $decoded = @gzuncompress($raw);
                if ($decoded === false) {
                    $decoded = @gzdecode($raw) ?: $raw;
                }
                if (preg_match_all('/\((?:\\\\.|[^\\\\\)])*\)/s', $decoded, $m)) {
                    foreach ($m[0] as $literal) {
                        $partes[] = stripcslashes(trim($literal, '()'));
                    }
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $partes)));
    }

    /** Convierte la 1.ª página del PDF a JPEG (requiere extensión Imagick). */
    private function pdfPrimeraPaginaComoJpeg(string $contenido): ?string
    {
        if (!extension_loaded('imagick') || !class_exists('\\Imagick')) {
            return null;
        }

        try {
            $pdf = new \Imagick();
            $pdf->setResolution(150, 150);
            $pdf->readImageBlob($contenido);
            $pdf->setIteratorIndex(0);
            $pdf->setImageFormat('jpeg');
            $pdf->setImageCompressionQuality(75);
            $pdf->stripImage();

            $jpeg = $pdf->getImageBlob();
            $pdf->clear();
            $pdf->destroy();

            return $jpeg ?: null;
        } catch (\Throwable $e) {
            Log::warning('No se pudo convertir PDF a JPEG', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function enviarOcrSpace(string $contenido, string $nombreArchivo, bool $esPdf = false): array
    {
        $esPdf = $esPdf || strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION)) === 'pdf';

        $params = [
            'apikey'            => config('services.ocr_space.key'),
            'language'          => 'spa',
            'OCREngine'         => $esPdf ? '1' : '2',
            'isOverlayRequired' => 'false',
            'scale'             => $esPdf ? 'false' : 'true',
        ];

        if ($esPdf) {
            $params['filetype'] = 'PDF';
        }

        $mime = $esPdf ? 'application/pdf' : 'application/octet-stream';

        $response = Http::withoutVerifying()
            ->timeout($esPdf ? 120 : 60)
            ->attach('file', $contenido, $nombreArchivo, ['Content-Type' => $mime])
            ->post('https://api.ocr.space/parse/image', $params);

        if (!$response->successful()) {
            Log::warning('OCR HTTP error', [
                'status'  => $response->status(),
                'archivo' => $nombreArchivo,
                'bytes'   => strlen($contenido),
                'body'    => mb_substr($response->body(), 0, 500),
            ]);

            $error = $esPdf
                ? 'No se pudo leer el PDF. Envía una captura JPG o PNG del comprobante.'
                : 'Error al conectar con el servicio OCR';

            return ['ok' => false, 'error' => $error];
        }

        $ocrData = $response->json();

        if ($ocrData['IsErroredOnProcessing'] ?? false) {
            $errorMsg = $ocrData['ErrorMessage'][0] ?? 'Error en el procesamiento OCR';
            Log::warning('OCR processing error', [
                'archivo' => $nombreArchivo,
                'error'   => $ocrData['ErrorMessage'] ?? [],
            ]);

            if ($esPdf) {
                $errorMsg = 'No se pudo procesar el PDF. Envía una captura JPG o PNG del comprobante.';
            }

            return ['ok' => false, 'error' => $errorMsg];
        }

        $texto = collect($ocrData['ParsedResults'] ?? [])
            ->pluck('ParsedText')
            ->filter()
            ->implode("\n");

        return ['ok' => true, 'texto' => $texto, 'metodo' => 'ocr'];
    }

    private function guardarComprobante(string $contenido, string $nombreArchivo): string
    {
        $phone   = self::claveIdentificador($this->numberPhone);
        $ahora   = now();
        $carpeta = public_path('comprobantes/' . $phone . '/' . $ahora->year . '/' . str_pad($ahora->month, 2, '0', STR_PAD_LEFT));

        if (!file_exists($carpeta)) {
            mkdir($carpeta, 0755, true);
        }

        $nombre    = time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nombreArchivo);
        $rutaFull  = $carpeta . '/' . $nombre;

        file_put_contents($rutaFull, $contenido);

        Log::info('Comprobante guardado', ['ruta' => $rutaFull, 'phone' => $this->numberPhone]);

        // Retornar ruta relativa desde public/
        return 'comprobantes/' . $phone . '/' . $ahora->year . '/' . str_pad($ahora->month, 2, '0', STR_PAD_LEFT) . '/' . $nombre;
    }

    private function guardarResultado(array $resultado): void
    {
        $carpeta = storage_path('app/verificaciones');
        if (!file_exists($carpeta)) mkdir($carpeta, 0755, true);

        $archivo = $carpeta . '/' . self::claveIdentificador($this->numberPhone) . '.json';

        // No sobreescribir un resultado exitoso con un error
        if (isset($resultado['error']) && file_exists($archivo)) {
            $existente = json_decode(file_get_contents($archivo), true);
            if (isset($existente['ok']) && $existente['ok'] === true) {
                return;
            }
        }

        file_put_contents($archivo, json_encode($resultado));
    }

    private function descargarUltimoArchivo(string $endpoint, string $token): array
    {
        $this->ultimoErrorDescarga = null;
        if (trim($endpoint) === '' || trim($token) === '') {
            $this->ultimoErrorDescarga = 'Configuración WATI incompleta: revisa endpoint y token.';
            Log::warning('VerificarPagoWati: configuración WATI incompleta', [
                'endpoint_definido' => trim($endpoint) !== '',
                'token_definido'    => trim($token) !== '',
            ]);
            return [null, null];
        }

        if (self::esBsuid($this->numberPhone)) {
            return $this->descargarUltimoArchivoV3($token);
        }

        $pageNumber = 1;

        $telefonos = $this->telefonosConsulta();

        for ($i = 0; $i < 5; $i++) {
            foreach ($telefonos as $telefono) {
                $msgResponse = Http::withoutVerifying()
                    ->withToken($token)
                    ->timeout(30)
                    ->get("{$endpoint}/api/v1/getMessages/{$telefono}", [
                        'pageSize'   => 20,
                        'pageNumber' => $pageNumber,
                    ]);

                if (!$msgResponse->successful()) {
                    Log::warning('Wati getMessages falló en verificación de pago', [
                        'phone_original' => $this->numberPhone,
                        'phone_consulta' => $telefono,
                        'pageNumber'     => $pageNumber,
                        'status'         => $msgResponse->status(),
                        'body'           => mb_substr($msgResponse->body(), 0, 300),
                    ]);

                    if (in_array($msgResponse->status(), [401, 403], true)) {
                        $this->ultimoErrorDescarga = 'No fue posible consultar WATI: token o endpoint no autorizado.';
                    } elseif ($msgResponse->status() >= 500) {
                        $this->ultimoErrorDescarga = 'WATI no respondió correctamente al consultar los mensajes.';
                    }
                    continue;
                }

                $items = $msgResponse->json()['messages']['items'] ?? [];
                if (empty($items)) {
                    continue;
                }

                foreach ($items as $message) {
                    // Solo mensajes recibidos del contacto, no enviados por el bot
                    if ($this->esMensajeSaliente($message)) continue;

                    $type        = strtolower((string) ($message['type'] ?? ''));
                    $dataPath    = (string) ($message['data'] ?? $message['mediaUrl'] ?? $message['fileName'] ?? '');
                    $textoNombre = (string) ($message['text'] ?? $message['fileName'] ?? '');

                    $tipoNormalizado = $this->normalizarTipoAdjunto($type, $dataPath, $textoNombre);

                    if (!in_array($tipoNormalizado, ['image', 'document'], true) || !$dataPath) {
                        continue;
                    }
                    if (str_contains(strtolower($textoNombre ?: $dataPath), '.crdownload')) continue;

                    $descarga = WatiMediaService::download($dataPath, $textoNombre, $tipoNormalizado);
                    if (!$descarga) {
                        Log::warning('Wati adjunto no se pudo descargar en verificación de pago', [
                            'phone_original' => $this->numberPhone,
                            'phone_consulta' => $telefono,
                            'type'           => $type,
                            'tipo_usado'     => $tipoNormalizado,
                            'dataPath'       => $dataPath,
                            'text'           => $textoNombre,
                        ]);
                        continue;
                    }

                    $ext = $descarga['extension'];
                    $nombreArchivo = ($textoNombre && preg_match('/\.\w{2,4}$/i', $textoNombre))
                        ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($textoNombre, PATHINFO_FILENAME)) . '.' . $ext
                        : 'comprobante_' . time() . '.' . $ext;

                    return [$descarga['content'], $nombreArchivo];
                }
            }

            $pageNumber++;
        }

        if ($this->ultimoErrorDescarga === null) {
            $this->ultimoErrorDescarga = 'No se encontró ningún archivo reciente. Verifica que el comprobante se haya enviado como imagen o documento.';
        }

        return [null, null];
    }

    /** Contactos sin teléfono: mensajes y archivo por la API v3, usando el BSUID como target. */
    private function descargarUltimoArchivoV3(string $token): array
    {
        $target = trim($this->numberPhone);
        $base   = WatiMediaService::baseV3();

        for ($pagina = 1; $pagina <= 5; $pagina++) {
            $response = Http::withoutVerifying()
                ->withToken($token)
                ->timeout(30)
                ->get($base . '/api/ext/v3/conversations/' . rawurlencode($target) . '/messages', [
                    'page_number' => $pagina,
                    'page_size'   => 20,
                ]);

            if (!$response->successful()) {
                Log::warning('Wati v3 messages falló en verificación de pago', [
                    'target' => $target,
                    'pagina' => $pagina,
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 300),
                ]);

                if (in_array($response->status(), [401, 403], true)) {
                    $this->ultimoErrorDescarga = 'No fue posible consultar WATI: token o endpoint no autorizado.';
                } elseif ($response->status() >= 500) {
                    $this->ultimoErrorDescarga = 'WATI no respondió correctamente al consultar los mensajes.';
                }
                break;
            }

            $mensajes = $response->json('message_list') ?? [];
            if (empty($mensajes)) {
                break;
            }

            foreach ($mensajes as $message) {
                if (($message['event_type'] ?? 'message') !== 'message') continue;
                if (($message['owner'] ?? false) === true) continue;

                $type = strtolower((string) ($message['type'] ?? ''));
                $id   = (string) ($message['id'] ?? '');
                if (!in_array($type, ['image', 'document'], true) || $id === '') continue;

                $textoNombre = (string) ($message['text'] ?? '');
                if (str_contains(strtolower($textoNombre), '.crdownload')) continue;

                $descarga = WatiMediaService::downloadV3($id, $textoNombre, $type);
                if (!$descarga) {
                    continue;
                }

                $ext = $descarga['extension'];
                $nombreArchivo = ($textoNombre && preg_match('/\.\w{2,4}$/i', $textoNombre))
                    ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($textoNombre, PATHINFO_FILENAME)) . '.' . $ext
                    : 'comprobante_' . time() . '.' . $ext;

                return [$descarga['content'], $nombreArchivo];
            }
        }

        if ($this->ultimoErrorDescarga === null) {
            $this->ultimoErrorDescarga = 'No se encontró ningún archivo reciente. Verifica que el comprobante se haya enviado como imagen o documento.';
        }

        return [null, null];
    }

    /** WATI marca los mensajes del bot/operador con owner=true (booleano), no solo "us". */
    private function esMensajeSaliente(array $message): bool
    {
        $owner = $message['owner'] ?? null;
        if ($owner === true || in_array(strtolower(trim((string) $owner)), ['1', 'true', 'us'], true)) {
            return true;
        }

        $eventType = strtolower((string) ($message['eventType'] ?? ''));
        if (in_array($eventType, ['sent', 'broadcast', 'broadcastmessage', 'template', 'templatemessage'], true)) {
            return true;
        }

        return !empty($message['isOwner']);
    }

    private function telefonosConsulta(): array
    {
        $original = trim($this->numberPhone);
        $digitos  = preg_replace('/\D/', '', $original);

        $telefonos = array_filter([
            $original,
            $digitos,
            $digitos !== '' && str_starts_with($digitos, '57') ? substr($digitos, 2) : null,
            $digitos !== '' && !str_starts_with($digitos, '57') ? '57' . $digitos : null,
        ]);

        return array_values(array_unique($telefonos));
    }

    private function normalizarTipoAdjunto(string $type, string $dataPath, string $textoNombre): ?string
    {
        if (in_array($type, ['image', 'document'], true)) {
            return $type;
        }

        if ($type === 'file') {
            $ref = strtolower($textoNombre ?: $dataPath);
            if (preg_match('/\.(jpg|jpeg|png|gif|bmp|webp)$/i', $ref)) {
                return 'image';
            }
            return 'document';
        }

        $ref = strtolower($textoNombre ?: $dataPath);
        if (preg_match('/\.(jpg|jpeg|png|gif|bmp|webp)$/i', $ref)) {
            return 'image';
        }
        if (preg_match('/\.(pdf|doc|docx|xls|xlsx)$/i', $ref)) {
            return 'document';
        }

        return null;
    }

    private function extraerValor(string $texto): ?string
    {
        $patrones = [
            '/\$\s*([\d]{1,3}(?:[.,]\d{3})+(?:[.,]\d{2})?)/',
            '/\$\s*([\d]{1,3}(?:[.,]\d{3})+)/',
            '/\$\s*(\d+(?:[.,]\d+)*)/',
            '/COP\s*([\d]{1,3}(?:[.,]\d{3})+)/i',
            '/COP\s*(\d+)/i',
            '/([\d]{1,3}(?:\.\d{3})+(?:,\d{2})?)/',
            '/([\d]{1,3}(?:\.\d{3})+)/',
        ];
        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $m)) return trim($m[1]);
        }
        return null;
    }

    private function extraerFecha(string $texto): ?string
    {
        $patrones = [
            '/(\d{4}-\d{2}-\d{2})/',
            '/(\d{2}\/\d{2}\/\d{4})/',
            '/(\d{2}-\d{2}-\d{4})/',
            '/(\d{1,2}\s+de\s+\w+\s+de\s+\d{4})/iu',
            '/(\d{1,2}\s+de\s+\w+,?\s+\d{4})/iu',
            '/(\d{1,2}\s+\w{3,}\s+\d{4})/iu',
            '/(\w+\s+\d{1,2},?\s+\d{4})/iu',
        ];
        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $m)) return trim($m[1]);
        }
        return null;
    }

    private function extraerNit(string $texto): ?string
    {
        if (preg_match('/N\.?I\.?T\.?\s*[:#No.\s]*(\d[\d.\s]{6,12}(?:-\d)?)/i', $texto, $m)) {
            return trim(preg_replace('/[\s.]/', '', $m[1]));
        }
        if (preg_match('/\b(\d{7,10})-(\d)\b/', $texto, $m)) return $m[1] . '-' . $m[2];
        if (preg_match_all('/\b(\d{9,10})\b/', $texto, $all)) {
            foreach ($all[1] as $numero) {
                $pos      = strpos($texto, $numero);
                $contexto = strtolower(substr($texto, max(0, $pos - 40), 40));
                if (!preg_match('/comprobante|referencia|voucher|transacci[oó]n|nequi|no\.\s*$|n[°º]\s*$|cuenta|ahorros|corriente|tel[eé]fono|celular|m[oó]vil/i', $contexto)) {
                    return $numero;
                }
            }
        }
        return null;
    }

    private function parsearFecha(string $texto): ?Carbon
    {
        $texto = trim($texto);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m)) return Carbon::createFromDate($m[1], $m[2], $m[3]);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $texto, $m)) return Carbon::createFromDate($m[3], $m[2], $m[1]);
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $texto, $m)) return Carbon::createFromDate($m[3], $m[2], $m[1]);
        if (preg_match('/^(\d{1,2})\s+(?:de\s+)?(\w+)(?:\s+de)?\s+(\d{4})$/iu', $texto, $m)) {
            $mes = $this->meses[strtolower($m[2])] ?? null;
            if ($mes) return Carbon::createFromDate($m[3], $mes, $m[1]);
        }
        if (preg_match('/^(\w+)\s+(\d{1,2}),?\s+(\d{4})$/iu', $texto, $m)) {
            $mes = $this->meses[strtolower($m[1])] ?? null;
            if ($mes) return Carbon::createFromDate($m[3], $mes, $m[2]);
        }
        try { return Carbon::parse($texto); } catch (\Exception) { return null; }
    }

    private function validarFechaHoy(?string $fecha): bool
    {
        if (!$fecha) return false;
        $c = $this->parsearFecha($fecha);
        return $c !== null && $c->toDateString() === now()->toDateString();
    }

    private function normalizarMonto(string $valor): float
    {
        $valor = trim(str_replace(['$', ' ', 'COP'], '', $valor));
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $valor)) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.?\d{1,2})?$/', $valor)) {
            $valor = str_replace(',', '', $valor);
        } else {
            $valor = str_replace(',', '.', $valor);
        }
        return (float) $valor;
    }

    private function validarMonto(string $esperado, ?string $extraido): bool
    {
        if (!$extraido) return false;
        $e = $this->normalizarMonto($esperado);
        $x = $this->normalizarMonto($extraido);
        if ($e <= 0) return false;
        return abs($e - $x) / $e <= 0.01;
    }

    /**
     * Asegura que el resultado incluya el campo mensaje que Wati usa en @mensaje.
     */
    public static function enriquecerResultado(array $resultado, ?string $montoEsperado = null): array
    {
        unset($resultado['mensaje']);

        if (isset($resultado['error'])) {
            $resultado['mensaje'] = $resultado['error'];
            $resultado['ok']      = false;
            return $resultado;
        }

        $datos = $resultado['datos'] ?? [];

        // Compatibilidad con JSON viejos: ya no se valida fecha
        if (array_key_exists('fecha_valida', $datos)) {
            $datos['fecha_valida'] = true;
            $resultado['datos']    = $datos;
        }

        $prosarcValido = $datos['prosarc_valido'] ?? null;
        $montoValido   = $datos['monto_valido'] ?? null;

        $facturaValida = ($prosarcValido !== false) && ($montoValido === null || $montoValido === true);
        $resultado['factura_valida'] = $facturaValida;

        if ($facturaValida) {
            $resultado['ok']      = true;
            $resultado['mensaje'] = 'Tu comprobante de pago fue verificado correctamente.';
            return $resultado;
        }

        $razones = [];

        if ($prosarcValido === false) {
            $razones[] = 'El comprobante no parece ser de PROSARC. Verifica que el pago se haya hecho a PROSARC S.A. ESP.';
        }

        if ($montoValido === false) {
            $extraido = $datos['valor'] ?? 'no detectado';
            if ($montoEsperado) {
                $razones[] = "El monto encontrado ({$extraido}) no coincide con el valor esperado ({$montoEsperado}).";
            } else {
                $razones[] = "El monto encontrado ({$extraido}) no coincide con el valor esperado del servicio.";
            }
        }

        $resultado['ok']      = false;
        $resultado['mensaje'] = $razones
            ? implode(' ', $razones)
            : 'No pudimos validar tu comprobante de pago. Por favor intenta de nuevo.';

        return $resultado;
    }
}
